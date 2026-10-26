<?php

namespace App\Services\Booking;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\BookingStatusLog;
use App\Models\Payment;
use App\Models\RefundAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BatchManagementService;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundLedger;
use Carbon\Carbon;
use RuntimeException;

/**
 * Staff booking changes that affect who takes a place (Batch 3-II A). Every change runs inside the capacity locks of
 * the dates involved (CapacityCoordinator) with the booking row locked, re-checks the booking's state (stale forms and
 * double clicks are refused), and writes status, dates and batch together or not at all.
 *
 * Status changes allowed from the staff status menu (anything else is refused, not guessed):
 *   pending_downpayment -> confirmed            payment received (receipts minus refunds >= downpayment) and a free place
 *                       -> cancelled_by_guest   only with no money received
 *                       -> cancelled_by_camp    (a refund case is opened if money was received)
 *   paid_unallocated    -> confirmed            a free place (payment already verified); its payment review is resolved
 *                       -> cancelled_by_camp    (refund case)
 *   confirmed / rescheduled -> completed / no_show   only on or after the dive date (nothing is forfeited here)
 *                       -> cancelled_by_camp    (refund case); -> cancelled_by_guest only with no money received
 *                       -> rescheduled / confirmed
 *   cancelled*          -> confirmed            restore: no open refund case or refund in progress, receipts minus
 *                                               refunds >= downpayment, and a free place
 *   reschedule_requested / cancellation_requested: decided only through the guest's request (Booking Requests)
 *   completed / no_show: final here
 * Money is never moved here: cancellations open (or join) the booking's refund case; refunds stay in Refund Cases.
 */
class BookingTransitionService
{
    private const ALLOWED = [
        'pending_downpayment' => ['confirmed', 'cancelled_by_guest', 'cancelled_by_camp'],
        'paid_unallocated' => ['confirmed', 'cancelled_by_camp'],
        'confirmed' => ['completed', 'no_show', 'cancelled_by_camp', 'cancelled_by_guest', 'rescheduled'],
        'rescheduled' => ['confirmed', 'completed', 'no_show', 'cancelled_by_camp', 'cancelled_by_guest'],
        'cancelled_by_guest' => ['confirmed'],
        'cancelled_by_camp' => ['confirmed'],
        'cancelled' => ['confirmed'],
    ];

    public function __construct(
        protected CapacityCoordinator $capacity,
        protected BatchManagementService $batches,
        protected RefundCaseService $cases,
        protected RefundLedger $ledger,
    ) {
    }

    /** The staff status menu. @throws RuntimeException (incl. CapacityException) with a message for staff; nothing written. */
    public function changeStatus(Booking $booking, string $to, User $actor, ?string $note = null): Booking
    {
        $this->assertStaff($actor);
        $from = $booking->status;
        if ($from === $to) {
            throw new RuntimeException('The booking already has this status.');
        }
        if (! in_array($to, self::ALLOWED[$from] ?? [], true)) {
            throw new RuntimeException(match ($from) {
                'reschedule_requested', 'cancellation_requested' => 'This booking has a pending guest request: approve or reject it in Booking Requests instead.',
                default => "A booking that is " . str_replace('_', ' ', $from) . " cannot be changed to " . str_replace('_', ' ', $to) . " here.",
            });
        }
        $note = trim((string) $note) ?: "Status changed from {$from} to {$to} by {$actor->name}";
        $created = null;

        $this->capacity->transaction([$booking->start_date], function () use ($booking, $from, $to, $actor, $note, &$created) {
            $locked = $this->lock($booking, $from);

            if ($to === 'confirmed') {
                if (str_starts_with($from, 'cancelled')) {
                    $this->assertNoUnresolvedRefund($locked);
                }
                $this->assertDownpaymentReceived($locked);
                if (! CapacityCoordinator::takesPlace($from)) {
                    $this->capacity->assertRoom($locked->start_date->format('Y-m-d'), $locked->participants()->count(), $locked);
                }
                $created = $this->alignBatch($locked, $actor);
            }
            if (in_array($to, ['completed', 'no_show'], true) && $locked->start_date->isAfter(today())) {
                throw new RuntimeException('This dive date has not come yet, so the booking cannot be marked ' . str_replace('_', ' ', $to) . '.');
            }
            if ($to === 'cancelled_by_guest' && $this->ledger->position($locked)['paid'] > 0) {
                throw new RuntimeException("Money was received for this booking: a guest cancellation goes through the guest's cancellation request, so the refund terms are recorded. Nothing was changed.");
            }

            $locked->update(['status' => $to]);
            if ($from === 'paid_unallocated' && $to === 'confirmed') {
                Payment::where('booking_id', $locked->id)->awaitingReview()->where('review_reason', Payment::REVIEW_CAPACITY_UNAVAILABLE)
                    ->update(['review_resolved_at' => now(), 'review_resolved_by' => $actor->id]);
            }
            BookingStatusLog::create([
                'booking_id' => $locked->id, 'old_status' => $from, 'new_status' => $to, 'changed_by' => $actor->id,
                'note' => $note . ($to === 'no_show' ? ' (no-show recorded; any forfeiture is decided separately)' : ''), 'created_at' => now(),
            ]);
            if ($to === 'cancelled_by_camp') {
                // Money received: the refund case is opened (reason and progress unconfirmed: staff review). No money moves.
                $this->cases->openForCampCancellation($locked, BookingRefund::REASON_UNKNOWN, 'unknown', $actor, $note);
            }
        });

        AuditLogger::log('BOOKING_STATUS_CHANGED', "Booking #{$booking->booking_number} status: {$from} → {$to} by {$actor->name}. Note: {$note}", $actor, $actor->name);
        if ($created) {
            $this->batches->completeCreatedBatch($created, $actor); // network only after commit
        }

        return $booking->fresh();
    }

    /**
     * Move a booking to new dates (staff edit, reschedule approval): the destination must have room (its old place is
     * kept until the move commits), and the batch follows the start date. $before runs first inside the locks with the
     * booking row locked (e.g. claiming a request), $after last (other field updates, logs), so everything commits
     * together or not at all. A details-only edit (same dates, no new place taken) never touches the batch, so
     * historical assignments (completed or cancelled batches) are kept as they are.
     */
    public function moveDates(Booking $booking, string $startDate, string $endDate, User $actor, ?string $newStatus = null,
        ?callable $before = null, ?callable $after = null): Booking
    {
        $this->assertStaff($actor);
        $start = Carbon::parse($startDate)->format('Y-m-d');
        $end = Carbon::parse($endDate)->format('Y-m-d');
        if ($end <= $start) {
            throw new RuntimeException('The end date must be after the start date.');
        }
        $created = null;

        $this->capacity->transaction([$booking->start_date, $start], function () use ($booking, $start, $end, $actor, $newStatus, $before, $after, &$created) {
            $locked = $this->lock($booking, $booking->status);
            if ($before) {
                $before($locked);
            }
            $status = $newStatus ?? $locked->status;
            $dateChanged = $locked->start_date->format('Y-m-d') !== $start;
            $startsTakingPlace = CapacityCoordinator::takesPlace($status) && ($dateChanged || ! CapacityCoordinator::takesPlace($locked->status));
            if ($startsTakingPlace) {
                // The booking's own place on its old date is not counted against the new one
                $this->capacity->assertRoom($start, $locked->participants()->count(), $dateChanged ? null : $locked);
            }

            $locked->fill(['start_date' => $start, 'end_date' => $end, 'status' => $status]);
            if ($dateChanged || $startsTakingPlace) {
                $created = $this->alignBatch($locked, $actor);
            }
            $locked->save();
            if ($after) {
                $after($locked);
            }
        });

        if ($created) {
            $this->batches->completeCreatedBatch($created, $actor);
        }

        return $booking->fresh();
    }

    /** Lock the row and make sure nobody changed it since the form was loaded. */
    private function lock(Booking $booking, string $expectedStatus): Booking
    {
        $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== $expectedStatus || $locked->start_date->format('Y-m-d') !== $booking->start_date->format('Y-m-d')) {
            throw new RuntimeException('This booking was changed by someone else meanwhile. Reload the page and try again; nothing was changed.');
        }

        return $locked;
    }

    /**
     * Keep the booking in a batch of its own start date (a batch is one dive date). Returns a batch this call created
     * (for completeCreatedBatch after commit), or null.
     */
    private function alignBatch(Booking $booking, User $actor): ?Batch
    {
        $current = $booking->batch_id ? Batch::find($booking->batch_id) : null;
        if ($current && $current->start_date?->format('Y-m-d') === $booking->start_date->format('Y-m-d') && in_array($current->status, ['open', 'confirmed'], true)) {
            return null;
        }
        ['batch' => $batch, 'created' => $created] = $this->batches->findOrCreateBatchRecord($booking->start_date, $booking->end_date, $actor);
        $booking->batch_id = $batch->id;
        $booking->save();

        return $created ? $batch : null;
    }

    private function assertDownpaymentReceived(Booking $booking): void
    {
        $position = $this->ledger->position($booking);
        $needed = (int) round((float) $booking->downpayment_amount * 100);
        if ($position['paid'] - $position['refunded'] < $needed) {
            throw new RuntimeException('No downpayment of ₱' . number_format($needed / 100, 2) . ' is on record for this booking (received minus refunded: ₱'
                . number_format(max(0, $position['paid'] - $position['refunded']) / 100, 2) . '). Record the payment first; nothing was changed.');
        }
    }

    /** Refuses while the booking has an open refund case or a refund in progress (reactivating it would be unsafe). */
    public function assertNoUnresolvedRefund(Booking $booking): void
    {
        $open = BookingRefund::where('booking_id', $booking->id)->whereIn('status', BookingRefund::OPEN_STATUSES)->value('id');
        $inProgress = RefundAttempt::whereIn('payment_id', $booking->payments()->pluck('id'))->whereIn('status', RefundAttempt::RESERVED)->exists();
        if ($open !== null || $inProgress) {
            throw new RuntimeException('This booking has ' . ($open ? "an open refund case (#{$open})" : 'a refund in progress')
                . '. Resolve it in Refund Cases before restoring the booking; nothing was changed.');
        }
    }

    private function assertStaff(User $actor): void
    {
        if (! in_array($actor->role, ['owner', 'admin'], true)) {
            throw new RuntimeException('Only owners and admins can change bookings.');
        }
    }
}
