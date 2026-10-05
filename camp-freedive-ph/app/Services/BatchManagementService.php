<?php

namespace App\Services;

use App\Mail\BatchRescheduledMail;
use App\Mail\BatchWeatherCancellationMail;
use App\Models\Batch;
use App\Models\BatchStatusLog;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\NotificationLog;
use App\Models\RefundRequest;
use App\Models\User;
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
        $startDate = Carbon::parse($startDate)->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->startOfDay() : $startDate->copy()->addDay();

        // Check if there is already an open/confirmed batch for this start date
        $existing = Batch::whereDate('start_date', $startDate->toDateString())
            ->whereIn('status', ['confirmed', 'open'])
            ->first();

        if ($existing) {
            return $existing;
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
            'risk_classification' => 'safe',
            'created_by' => $creatorId,
        ]);

        $this->resequenceBatches();
        $batch->refresh();

        BatchStatusLog::create([
            'batch_id' => $batch->id,
            'old_status' => null,
            'new_status' => 'confirmed',
            'changed_by' => $creatorId,
            'note' => "Auto-created {$batch->batch_code} for dive date {$startDate->format('M d, Y')}.",
        ]);

        try {
            $weatherService = app(\App\Services\WeatherForecastService::class);
            $weatherService->assessBatch($batch, null, $creator);
        } catch (\Throwable $e) {
            // It's fine if the weather check fails (more than 16 days away or API down)
        }

        return $batch;
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
                    'risk_classification' => $data['risk_classification'] ?? 'safe',
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
                    'risk_classification' => $data['risk_classification'] ?? 'safe',
                    'capacity_note' => $data['capacity_note'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $creator->id,
                ]);

                // Renumber if we use automatic batch numbers
                $this->resequenceBatches();
                $batch->refresh();
            }

            // Add the selected bookings
            if (!empty($bookingIds)) {
                Booking::whereIn('id', $bookingIds)->update([
                    'batch_id' => $batch->id,
                ]);
            }

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
    public function updateStatus(Batch $batch, string $newStatus, User $changer, ?string $note = null): void
    {
        $validStatuses = ['confirmed', 'completed', 'rescheduled', 'cancelled_by_camp'];
        if (!in_array($newStatus, $validStatuses)) {
            throw new Exception("Invalid batch status: {$newStatus}");
        }

        DB::transaction(function () use ($batch, $newStatus, $changer, $note) {
            $oldStatus = $batch->status;
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
            $connectedBookings = $batch->bookings()
                ->whereNotIn('status', ['cancelled_by_guest', 'no_show'])
                ->get();

            foreach ($connectedBookings as $booking) {
                $bookingOldStatus = $booking->status;

                if ($newStatus === 'cancelled_by_camp') {
                    $booking->update(['status' => 'cancelled_by_camp']);

                    // Paid bookings get a refund request
                    $completedPayments = $booking->payments()->where('status', 'completed')->get();
                    foreach ($completedPayments as $payment) {
                        RefundRequest::create([
                            'payment_id' => $payment->id,
                            'booking_id' => $booking->id,
                            'requested_by' => 'camp_force_majeure',
                            'requested_at' => now(),
                            'eligibility_calculated' => [
                                'days_until_dive' => max(0, Carbon::now()->diffInDays($booking->start_date, false)),
                                'eligible_for_refund' => true,
                                'refund_percentage' => 100,
                                'window_label' => 'Camp Cancellation (100% Force Majeure Refund)',
                                'policy_action_text' => 'Camp cancelled batch due to marine safety / weather. Full refund approved.',
                            ],
                            'status' => 'pending',
                            'notes' => "Auto-generated refund request from batch cancellation: {$batch->batch_code}. Note: {$note}",
                        ]);
                    }

                    BookingStatusLog::create([
                        'booking_id' => $booking->id,
                        'old_status' => $bookingOldStatus,
                        'new_status' => 'cancelled_by_camp',
                        'changed_by' => $changer->id,
                        'note' => "Whole-batch cancellation: {$batch->name}. Full refund eligibility triggered. Custom email alert sent to {$booking->contact_email}.",
                    ]);

                    if (!empty($booking->contact_email)) {
                        try {
                            Mail::to($booking->contact_email)->send(
                                new BatchWeatherCancellationMail($booking, $batch, $note ?: 'Cancelled by Camp due to weather or operational advisory.')
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
                        'note' => "Batch {$batch->name} was rescheduled by camp. Customer notified via email to select their new preferred date via Manage Booking portal." . ($note ? " Note: {$note}" : ""),
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

            AuditLogger::log(
                'BATCH_STATUS_UPDATED',
                "Updated status of batch {$batch->batch_code} from '{$oldStatus}' to '{$newStatus}'. Cascaded to " . $connectedBookings->count() . " bookings.",
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
     * Move a booking to another batch (or take it out of its batch).
     */
    public function moveBooking(Booking $booking, ?Batch $targetBatch, User $changer, ?string $reason = null): void
    {
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
