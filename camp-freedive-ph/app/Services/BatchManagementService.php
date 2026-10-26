<?php

namespace App\Services;

use App\Mail\BatchRescheduledMail;
use App\Mail\BatchWeatherCancellationMail;
use App\Models\Batch;
use App\Models\BatchStatusLog;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\BookingStatusLog;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Booking\CapacityCoordinator;
use App\Services\Payment\PaymentExceptionService;
use App\Services\Refunds\RefundCaseService;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Handles batches (one batch = one 2-day weekend camp).
 *
 * - finds or creates the batch when someone books a weekend
 * - batch status: open/confirmed (taking bookings, max 45), in_progress, completed,
 *   cancelled/cancelled_by_camp (weather cancellation with refund)
 * - runs a weather check when a new batch is created
 */
class BatchManagementService
{
    /**
     * Renumber all batches by start_date so Batch 1 is the earliest.
     */
    public function resequenceBatches(): void
    {
        $batches = Batch::orderBy('start_date', 'asc')->orderBy('id', 'asc')->get();
        if ($batches->isEmpty()) {
            return;
        }

        // First set temporary codes so we don't hit the unique constraint
        $mapping = [];
        $index = 1;
        foreach ($batches as $batch) {
            $newIdentifier = "Batch {$index}";
            $oldCode = $batch->batch_code;
            $mapping[] = [
                'batch' => $batch,
                'newIdentifier' => $newIdentifier,
                'oldCode' => $oldCode,
                'startDate' => $batch->start_date,
            ];
            $tempCode = "Batch-Temp-{$batch->id}-" . uniqid();
            $batch->update([
                'name' => $tempCode,
                'batch_code' => $tempCode,
            ]);
            $index++;
        }

        // Then set the final batch numbers in date order
        foreach ($mapping as $item) {
            $item['batch']->update([
                'name' => $item['newIdentifier'],
                'batch_code' => $item['newIdentifier'],
            ]);

            if ($item['oldCode'] && $item['oldCode'] !== $item['newIdentifier']) {
                $old = $item['oldCode'];
                $new = $item['newIdentifier'];
                \App\Models\CoachAvailability::where('notes', 'like', "%{$old}%")
                    ->whereDate('date', $item['startDate'])
                    ->get()
                    ->each(function ($a) use ($old, $new) {
                        $a->update(['notes' => str_replace($old, $new, $a->notes)]);
                    });
            }
        }
    }

    /**
     * Get the next batch number.
     */
    public function getNextBatchNumber(?Carbon $date = null): int
    {
        $maxNum = 0;
        foreach (Batch::pluck('batch_code') as $code) {
            if (preg_match('/(\d+)/', (string) $code, $m)) {
                $maxNum = max($maxNum, (int)$m[1]);
            }
        }
        return max($maxNum + 1, Batch::count() + 1);
    }

    /**
     * Find the batch for these dates, or create a new one.
     *
     * @param Carbon|string|\DateTimeInterface $startDate
     * @param Carbon|string|\DateTimeInterface|null $endDate defaults to start date + 1 day
     * @param User|null $creator null when the system creates it
     * @return Batch
     */
    public function findOrCreateBatchForDates(Carbon|string|\DateTimeInterface $startDate, Carbon|string|\DateTimeInterface|null $endDate = null, ?User $creator = null): Batch
    {
        ['batch' => $batch, 'created' => $created] = $this->findOrCreateBatchRecord($startDate, $endDate, $creator);

        if (! $created) {
            return $batch;
        }

        return $this->completeCreatedBatch($batch, $creator);
    }

    /**
     * The non-database follow-up of a batch created by findOrCreateBatchRecord(): renumbering, the creation log and the
     * first weather check (network). Call after the transaction that created it has committed.
     */
    public function completeCreatedBatch(Batch $batch, ?User $creator = null): Batch
    {
        $this->resequenceBatches();
        $batch->refresh();

        BatchStatusLog::create([
            'batch_id' => $batch->id,
            'old_status' => null,
            'new_status' => 'confirmed',
            'changed_by' => $batch->created_by,
            'note' => "Auto-created {$batch->batch_code} for dive date {$batch->start_date->format('M d, Y')}.",
        ]);

        $this->assessBatchWeather($batch, $creator);

        return $batch;
    }

    /**
     * Database-only part of findOrCreateBatchForDates(): the open/confirmed batch for the start date,
     * or a new one with a temporary code. No renumbering, status log, or external calls, so it is
     * safe inside a database transaction (checkout finalization).
     *
     * @return array{batch: Batch, created: bool}
     */
    public function findOrCreateBatchRecord(Carbon|string|\DateTimeInterface $startDate, Carbon|string|\DateTimeInterface|null $endDate = null, ?User $creator = null): array
    {
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->startOfDay() : $startDate->copy()->addDay();

        // Look-up and create under the date's capacity lock (PostgreSQL advisory, transaction-scoped; re-entrant when the
        // caller already holds it), so two requests for the same date never both create a batch.
        return DB::transaction(function () use ($startDate, $endDate, $creator) {
            app(SlotReservationService::class)->lockDateInTransaction($startDate->toDateString());

            return $this->findOrCreateBatchRecordLocked($startDate, $endDate, $creator);
        });
    }

    private function findOrCreateBatchRecordLocked(Carbon $startDate, Carbon $endDate, ?User $creator): array
    {

        // Check if there is already an open/confirmed batch for this start date
        $existing = Batch::whereDate('start_date', $startDate->toDateString())
            ->whereIn('status', ['confirmed', 'open'])
            ->first();

        if ($existing) {
            return ['batch' => $existing, 'created' => false];
        }

        $creatorId = $creator?->id ?? \Illuminate\Support\Facades\Auth::id() ?? null;
        $tempCode = 'Batch-Temp-' . uniqid();

        $batch = Batch::create([
            'name' => $tempCode,
            'batch_code' => $tempCode,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
            'risk_classification' => null, // not assessed yet
            'created_by' => $creatorId,
        ]);

        return ['batch' => $batch, 'created' => true];
    }

    /**
     * Weather check for a newly created batch. Best effort: calls the weather service (network), so
     * never call it inside a database transaction.
     */
    public function assessBatchWeather(Batch $batch, ?User $creator = null): void
    {
        try {
            $weatherService = app(\App\Services\WeatherForecastService::class);
            $weatherService->assessBatch($batch, null, $creator);
        } catch (\Throwable $e) {
            // It's fine if the weather check fails (more than 16 days away or API down)
        }
    }

    /**
     * Create a batch and add the selected bookings to it.
     */
    public function createBatch(array $data, array $bookingIds, User $creator): Batch
    {
        return DB::transaction(function () use ($data, $bookingIds, $creator) {
            $startDate = Carbon::parse($data['start_date'])->startOfDay();
            $endDate = isset($data['end_date']) ? Carbon::parse($data['end_date'])->startOfDay() : $startDate->copy()->addDay();

            $batchNumberInput = trim($data['batch_number'] ?? $data['name'] ?? $data['batch_code'] ?? '');
            $hasCustomNumber = false;
            if (preg_match('/(\d+)/', $batchNumberInput, $matches)) {
                $batchIdentifier = 'Batch ' . (int)$matches[1];
                $hasCustomNumber = true;
            }

            if ($hasCustomNumber) {
                $batchCode = $batchIdentifier;
                if (Batch::where('batch_code', $batchCode)->exists()) {
                    $nextNum = $this->getNextBatchNumber($startDate);
                    $batchCode = 'Batch ' . $nextNum;
                    $batchIdentifier = $batchCode;
                }

                $batch = Batch::create([
                    'name' => $batchIdentifier,
                    'batch_code' => $batchCode,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'confirmed',
                    'lifecycle_status' => 'confirmed',
                    'risk_classification' => $data['risk_classification'] ?? null,
                    'capacity_note' => $data['capacity_note'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $creator->id,
                ]);
            } else {
                $tempCode = 'Batch-Temp-' . uniqid();
                $batch = Batch::create([
                    'name' => $tempCode,
                    'batch_code' => $tempCode,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => 'confirmed',
                    'lifecycle_status' => 'confirmed',
                    'risk_classification' => $data['risk_classification'] ?? null,
                    'capacity_note' => $data['capacity_note'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $creator->id,
                ]);

                // Renumber if we use automatic batch numbers
                $this->resequenceBatches();
                $batch->refresh();
            }

            // Add the selected bookings (only bookings on this batch's start date)
            $this->attachBookings($batch, $bookingIds);

            // Audit log
            BatchStatusLog::create([
                'batch_id' => $batch->id,
                'old_status' => null,
                'new_status' => 'confirmed',
                'changed_by' => $creator->id,
                'note' => "Batch created with " . count($bookingIds) . " attached booking(s).",
            ]);

            AuditLogger::log(
                'BATCH_CREATED',
                "Created batch {$batch->batch_code} ({$batch->name}) with " . count($bookingIds) . " booking(s).",
                $creator,
                $creator->name
            );

            // Run the first weather check if the batch is within 16 days
            try {
                $weatherService = app(\App\Services\WeatherForecastService::class);
                $weatherService->assessBatch($batch, null, $creator);
            } catch (\Exception $e) {
                // More than 16 days away or the service is down, skip it
            }

            return $batch;
        });
    }

    /**
     * Change the batch status and update its bookings too.
     *
     * @throws Exception
     */
    /**
     * @param array{reason_category?: ?string, activity_started?: ?string} $cancellation for cancelled_by_camp: why the
     *        camp cancelled and whether the activity had started, as confirmed by the staff member. Missing values are
     *        "unknown" and the refund cases wait for staff review (nothing is refunded automatically either way).
     */
    public function updateStatus(Batch $batch, string $newStatus, User $changer, ?string $note = null, array $cancellation = []): void
    {
        $validStatuses = ['confirmed', 'completed', 'rescheduled', 'cancelled_by_camp'];
        if (!in_array($newStatus, $validStatuses)) {
            throw new Exception("Invalid batch status: {$newStatus}");
        }

        $category = $cancellation['reason_category'] ?? BookingRefund::REASON_UNKNOWN;
        $activityStarted = $cancellation['activity_started'] ?? 'unknown';
        if ($newStatus === 'cancelled_by_camp' && ($category === BookingRefund::REASON_WEATHER_IN_PROGRESS
                || ($category === BookingRefund::REASON_WEATHER_PRE_DIVE && $activityStarted === 'yes'))) {
            // Client policy: weather after the activity started is an interruption, not a cancellation, and no refund
            // applies. It must not cancel every booking and open refunds; the interruption record comes in Batch 3-II.
            throw new Exception('Weather after the camp activity has started is an interruption, not a pre-dive cancellation: no refund applies. Do not cancel the batch; mark it completed with a note explaining what happened.');
        }

        DB::transaction(function () use ($batch, $newStatus, $changer, $note, $category, $activityStarted) {
            // One status change per batch at a time, with the old status read under the row lock (a repeated or concurrent
            // change sees the first one). PostgreSQL: NO KEY UPDATE, so bookings joining the batch (key-share locks) are not
            // blocked and the booking-then-batch order of date moves cannot deadlock with this batch-then-booking cascade.
            $oldStatus = Batch::whereKey($batch->id)->lock(DB::getDriverName() === 'pgsql' ? 'for no key update' : true)->firstOrFail()->status;
            if ($oldStatus === $newStatus && empty($note)) {
                return;
            }

            // 1. Update the batch
            $updateData = [
                'status' => $newStatus,
                'lifecycle_status' => $newStatus,
            ];

            if ($newStatus === 'cancelled_by_camp') {
                $updateData['cancelled_at'] = now();
                $updateData['cancellation_reason'] = $note ?: 'Cancelled by Camp due to weather or operational advisory';
            } elseif ($newStatus === 'completed') {
                $updateData['completed_at'] = now();
            }

            $batch->update($updateData);

            // 2. Save the status log
            BatchStatusLog::create([
                'batch_id' => $batch->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'changed_by' => $changer->id,
                'note' => $note,
            ]);

            // 3. Update the bookings
            // Finished, already-cancelled and no-show bookings keep their status; "completed" only finishes bookings that
            // were actually going (an unpaid or paid-but-unplaced booking is never marked as a finished trip)
            $connectedBookings = $batch->bookings()
                ->whereNotIn('status', ['cancelled_by_guest', 'no_show', 'completed', 'cancelled'])
                ->when($newStatus === 'completed', fn ($q) => $q->whereIn('status', ['confirmed', 'rescheduled']))
                ->get();

            $cascaded = 0;
            $notRescheduled = [];
            foreach ($connectedBookings as $listed) {
                // Re-read under the row lock: a staff decision committed since the list was read (a guest cancellation,
                // a move to another batch) is never overwritten
                $booking = Booking::whereKey($listed->id)->lockForUpdate()->first();
                if (! $booking || $booking->batch_id !== $batch->id
                    || in_array($booking->status, ['cancelled_by_guest', 'no_show', 'completed', 'cancelled'], true)
                    || ($newStatus === 'completed' && ! in_array($booking->status, ['confirmed', 'rescheduled'], true))) {
                    continue;
                }
                // A paid booking without a place whose money is held waits for the guest's choice: left unchanged, noted.
                // (Cancelling would decide its money; "rescheduled" would give it a place without a capacity check.)
                if (in_array($newStatus, ['cancelled_by_camp', 'rescheduled'], true) && $booking->status === 'paid_unallocated'
                    && ($issue = app(PaymentExceptionService::class)->blockingIssue($booking)) !== null) {
                    $newStatus === 'cancelled_by_camp'
                        ? app(PaymentExceptionService::class)->noteCampCancellation($booking, $batch, $changer, true, $issue)
                        : app(PaymentExceptionService::class)->noteBatchRescheduleSkipped($booking, $batch, $changer, $issue);
                    if ($newStatus === 'rescheduled') {
                        $notRescheduled[$booking->status] = ($notRescheduled[$booking->status] ?? 0) + 1;
                    }
                    continue;
                }
                // "Rescheduled" keeps a place: only bookings that already hold one are rescheduled. An unpaid booking is
                // never given a place without its payment and a capacity check, a camp-cancelled booking is never
                // reactivated (its refund case stays as it is), and a paid booking without a place waits for staff.
                // They keep their status, dates and batch; the skip is logged on the booking for staff follow-up.
                if ($newStatus === 'rescheduled' && ! CapacityCoordinator::takesPlace($booking->status)) {
                    $notRescheduled[$booking->status] = ($notRescheduled[$booking->status] ?? 0) + 1;
                    BookingStatusLog::create([
                        'booking_id' => $booking->id,
                        'old_status' => $booking->status,
                        'new_status' => $booking->status,
                        'changed_by' => $changer->id,
                        'note' => "Batch {$batch->name} was rescheduled by camp; this booking was not changed because it does not hold a place ("
                            . str_replace('_', ' ', $booking->status) . '). No reschedule email was sent; staff follow up.',
                    ]);
                    continue;
                }
                // A pending guest request (reschedule or cancellation) is the guest's to have decided by staff: the booking
                // and the request are left exactly as they are, so the request stays actionable in Booking Requests.
                if ($newStatus === 'rescheduled' && in_array($booking->status, ['reschedule_requested', 'cancellation_requested'], true)) {
                    $notRescheduled[$booking->status] = ($notRescheduled[$booking->status] ?? 0) + 1;
                    BookingStatusLog::create([
                        'booking_id' => $booking->id,
                        'old_status' => $booking->status,
                        'new_status' => $booking->status,
                        'changed_by' => $changer->id,
                        'note' => "Batch {$batch->name} was rescheduled by camp; this booking was not changed because the guest has a pending "
                            . ($booking->status === 'reschedule_requested' ? 'reschedule' : 'cancellation') . ' request, which staff still decide in Booking Requests. No reschedule email was sent; staff follow up.',
                    ]);
                    continue;
                }
                // Repeating the reschedule of an already rescheduled batch: bookings already told are not told again
                if ($newStatus === 'rescheduled' && $oldStatus === 'rescheduled' && $booking->status === 'rescheduled') {
                    continue;
                }
                $bookingOldStatus = $booking->status;
                $cascaded++;

                if ($newStatus === 'cancelled_by_camp') {
                    $booking->update(['status' => 'cancelled_by_camp']);
                    app(PaymentExceptionService::class)->noteCampCancellation($booking, $batch, $changer, false);

                    // Paid bookings get a refund case (reason/progress as confirmed by staff; the guest's choice and an
                    // approved amount come before any money moves). Every receipt counts: online "paid" and manual "completed".
                    $case = app(RefundCaseService::class)->openForCampCancellation($booking, $category, $activityStarted, $changer, $note ?: 'Batch cancelled by camp');

                    BookingStatusLog::create([
                        'booking_id' => $booking->id,
                        'old_status' => $bookingOldStatus,
                        'new_status' => 'cancelled_by_camp',
                        'changed_by' => $changer->id,
                        'note' => "Whole-batch cancellation: {$batch->name} (" . (BookingRefund::REASONS[$category] ?? $category) . ')'
                            . ($case ? ". Refund case #{$case->id} opened ({$case->status}); nothing refunded yet." : '. No payment to refund.'),
                    ]);

                    if (!empty($booking->contact_email)) {
                        try {
                            Mail::to($booking->contact_email)->send(
                                new BatchWeatherCancellationMail($booking, $batch, $note ?: 'Cancelled by Camp due to weather or operational advisory.', $category)
                            );

                            NotificationLog::create([
                                'batch_id' => $batch->id,
                                'booking_id' => $booking->id,
                                'recipient_email' => $booking->contact_email,
                                'recipient_name' => $booking->contact_name,
                                'subject' => "Camp Cancellation Notice - Booking #{$booking->booking_number}",
                                'message_body' => $note ?: 'Cancelled by Camp due to weather or operational advisory.',
                                'channel' => 'email',
                                'sent_by' => $changer->id,
                                'sent_at' => now(),
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning("Failed to dispatch batch cancellation email to {$booking->contact_email} for booking #{$booking->booking_number}: " . $e->getMessage());
                        }
                    }

                } elseif ($newStatus === 'completed') {
                    $booking->update(['status' => 'completed']);

                    BookingStatusLog::create([
                        'booking_id' => $booking->id,
                        'old_status' => $bookingOldStatus,
                        'new_status' => 'completed',
                        'changed_by' => $changer->id,
                        'note' => "Concluded 2D1N dive session with batch {$batch->name}.",
                    ]);

                } elseif ($newStatus === 'rescheduled') {
                    $booking->update(['status' => 'rescheduled']);

                    BookingStatusLog::create([
                        'booking_id' => $booking->id,
                        'old_status' => $bookingOldStatus,
                        'new_status' => 'rescheduled',
                        'changed_by' => $changer->id,
                        'note' => "Batch {$batch->name} was rescheduled by camp. Participant notified via email to select their new preferred date via Manage Booking portal." . ($note ? " Note: {$note}" : ""),
                    ]);

                    if (!empty($booking->contact_email)) {
                        try {
                            Mail::to($booking->contact_email)->send(
                                new BatchRescheduledMail($booking, $batch, $note)
                            );

                            NotificationLog::create([
                                'batch_id' => $batch->id,
                                'booking_id' => $booking->id,
                                'recipient_email' => $booking->contact_email,
                                'recipient_name' => $booking->contact_name,
                                'subject' => "Camp Schedule Rescheduled - Booking #{$booking->booking_number}",
                                'message_body' => $note ?: "Batch {$batch->name} was rescheduled by camp.",
                                'channel' => 'email',
                                'sent_by' => $changer->id,
                                'sent_at' => now(),
                            ]);
                        } catch (\Throwable $e) {
                            Log::warning("Failed to dispatch batch rescheduled email to {$booking->contact_email} for booking #{$booking->booking_number}: " . $e->getMessage());
                        }
                    }
                }
            }

            $skipped = $notRescheduled;
            ksort($skipped);
            AuditLogger::log(
                'BATCH_STATUS_UPDATED',
                "Updated status of batch {$batch->batch_code} from '{$oldStatus}' to '{$newStatus}'. Cascaded to {$cascaded} bookings."
                    . ($skipped ? ' Not rescheduled (left unchanged for staff follow-up): ' . implode(', ', array_map(fn ($status, $n) => "{$n} " . str_replace('_', ' ', $status), array_keys($skipped), $skipped)) . '.' : ''),
                $changer,
                $changer->name
            );
        });

        if ($newStatus === 'cancelled_by_camp') {
            app(CoachNotificationService::class)->notifyBatchCoaches(
                $batch,
                'Batch cancelled',
                'This batch has been cancelled by Camp Administration. Please review the latest operational instructions in the Coach Portal.' . ($note ? " Reason: {$note}" : ''),
            );
        } elseif ($newStatus === 'rescheduled') {
            app(CoachNotificationService::class)->notifyBatchCoaches(
                $batch,
                'Batch rescheduled',
                'This batch schedule has been updated by Camp Administration. Please review the latest dates and roster in the Coach Portal.' . ($note ? " Note: {$note}" : ''),
            );
        }
    }

    /**
     * Put bookings into a batch. A batch is one dive date: every booking must start on the batch's start date, or
     * nothing is attached (change the booking's dates first, through edit or reschedule).
     *
     * @throws Exception
     */
    public function attachBookings(Batch $batch, array $bookingIds): void
    {
        if ($bookingIds === []) {
            return;
        }
        $date = $batch->start_date->toDateString();
        $mismatched = Booking::whereIn('id', $bookingIds)->whereDate('start_date', '!=', $date)->pluck('booking_number');
        if ($mismatched->isNotEmpty()) {
            throw new Exception('These bookings are not on ' . $batch->start_date->format('M d, Y') . " and cannot join {$batch->name}: "
                . $mismatched->implode(', ') . '. Change their dates first (edit or reschedule). Nothing was changed.');
        }
        Booking::whereIn('id', $bookingIds)->update(['batch_id' => $batch->id]);
    }

    /**
     * Move a booking to another batch (or take it out of its batch).
     */
    public function moveBooking(Booking $booking, ?Batch $targetBatch, User $changer, ?string $reason = null): void
    {
        // A batch is one dive date: moving between batches never changes dates or capacity (use edit or reschedule)
        if ($targetBatch !== null) {
            if ($targetBatch->start_date?->format('Y-m-d') !== $booking->start_date?->format('Y-m-d')) {
                throw new Exception("Booking {$booking->booking_number} is on " . $booking->start_date?->format('M d, Y') . " but {$targetBatch->name} starts "
                    . $targetBatch->start_date?->format('M d, Y') . '. Change the booking dates first (edit or reschedule). Nothing was moved.');
            }
            if (! in_array($targetBatch->status, ['open', 'confirmed'], true)) {
                throw new Exception("{$targetBatch->name} is {$targetBatch->status}; bookings can only join an open or confirmed batch. Nothing was moved.");
            }
        }
        $oldBatchId = $booking->batch_id;
        $oldBatchName = $booking->batch ? $booking->batch->name : 'Unbatched';
        $newBatchName = $targetBatch ? $targetBatch->name : 'Unbatched';

        $booking->update(['batch_id' => $targetBatch?->id]);

        BookingStatusLog::create([
            'booking_id' => $booking->id,
            'old_status' => $booking->status,
            'new_status' => $booking->status,
            'changed_by' => $changer->id,
            'note' => "Moved from batch [{$oldBatchName}] to [{$newBatchName}]. Reason: " . ($reason ?: 'Operational adjustment'),
        ]);

        AuditLogger::log(
            'BOOKING_REBATCHED',
            "Moved booking {$booking->booking_number} from [{$oldBatchName}] to [{$newBatchName}].",
            $changer,
            $changer->name
        );
    }

    /**
     * Confirmed bookings on a date that aren't in a batch yet.
     */
    public function getUnbatchedBookingsForDate(Carbon $date): Collection
    {
        return Booking::with('participants')
            ->whereDate('start_date', $date->format('Y-m-d'))
            ->where(function ($q) {
                $q->whereNull('batch_id')
                  ->orWhere('batch_id', 0);
            })
            ->whereDoesntHave('batch')
            ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'completed', 'no_show', 'cancellation_requested'])
            ->get();
    }
}
