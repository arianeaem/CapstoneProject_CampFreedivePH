<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Mail\CancellationApprovedMail;
use App\Mail\CancellationRejectedMail;
use App\Mail\RescheduleApprovedMail;
use App\Mail\RescheduleRejectedMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\BookingStatusLog;
use App\Models\CancellationRequest;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Services\BatchManagementService;
use App\Services\BookingPolicyEngine;
use App\Services\Booking\BookingTransitionService;
use App\Services\CoachNotificationService;
use App\Services\Payment\PaymentExceptionService;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * Staff decisions on participants' reschedule and cancellation requests.
 * Each decision updates the booking and request together, writes the status log,
 * tells the affected coaches and emails the participant.
 *
 * Held payments (Batch 3-II B1): a decision that would leave the booking confirmed (approving a reschedule, rejecting
 * either request) is refused while a payment of the booking is held (unresolved payment exception or review). It is
 * checked with the booking row locked (booking first, then the request), the lock under which a late payment opens its
 * exception, so either order is safe. Approving a cancellation stays possible (the guest's own request; the booking
 * stops taking a place, and held money is still never spent by the refund case).
 */
class BookingRequestService
{
    public function __construct(
        protected BookingPolicyEngine $policyEngine,
        protected BatchManagementService $batchService,
        protected RefundCaseService $cases,
        protected CoachNotificationService $coachNotifier,
        protected RefundGuard $guard,
        protected PaymentExceptionService $exceptions,
    ) {}

    /**
     * Move the booking to the requested dates. Claiming the request, the destination's capacity check (under the
     * capacity locks of both dates, BookingTransitionService::moveDates), dates, batch and status commit together;
     * a full destination changes nothing and the request stays pending. An outdated request (the booking was cancelled
     * by the camp, completed, or decided otherwise meanwhile) or an unresolved refund is refused: nothing changes.
     */
    public function approveReschedule(RescheduleRequest $rescheduleRequest, User $operator, ?string $adminNotes): Booking
    {
        $booking = $rescheduleRequest->booking;
        $previousBatch = $booking->batch()->first();
        $oldDates = "{$booking->start_date->format('M d, Y')} - {$booking->end_date->format('M d, Y')}";
        $newDates = "{$rescheduleRequest->requested_start_date->format('M d, Y')} - {$rescheduleRequest->requested_end_date->format('M d, Y')}";

        $booking = app(BookingTransitionService::class)->moveDates(
            $booking,
            $rescheduleRequest->requested_start_date->format('Y-m-d'),
            $rescheduleRequest->requested_end_date->format('Y-m-d'),
            $operator,
            BookingStatus::Confirmed->value,
            before: function (Booking $locked) use ($rescheduleRequest) {
                // moveDates holds the booking row lock here (booking first, then the request: one lock order everywhere)
                $this->assertAwaiting($locked, BookingStatus::RescheduleRequested);
                $this->exceptions->assertNotHeld($locked, 'confirmed on new dates');
                $this->claimPending($rescheduleRequest);
                app(BookingTransitionService::class)->assertNoUnresolvedRefund($locked);
            },
            after: function (Booking $moved) use ($rescheduleRequest, $adminNotes, $operator, $oldDates, $newDates) {
                $rescheduleRequest->update([
                    'status' => 'approved',
                    'admin_notes' => $adminNotes ?: 'Reschedule request approved by camp staff.',
                    'reviewed_by' => $operator->id,
                    'reviewed_at' => now(),
                ]);

                BookingStatusLog::create([
                    'booking_id' => $moved->id,
                    'old_status' => BookingStatus::RescheduleRequested->value,
                    'new_status' => BookingStatus::Confirmed->value,
                    'changed_by' => $operator->id,
                    'note' => "Reschedule approved ({$oldDates} {$newDates}, attached to " . ($moved->batch?->batch_code ?? 'its batch') . ')' . ($adminNotes ? " - {$adminNotes}" : ''),
                    'created_at' => now(),
                ]);
            },
        );

        $this->tellCoaches($previousBatch, 'Booking rescheduled', "Booking #{$booking->booking_number} was rescheduled by Camp Administration. Please review the affected roster and schedule in the Coach Portal.", $booking);
        $this->emailGuest($booking, fn (Booking $fresh) => new RescheduleApprovedMail($fresh, $rescheduleRequest));

        return $booking;
    }

    /** Keep the original dates. */
    public function rejectReschedule(RescheduleRequest $rescheduleRequest, User $operator, ?string $adminNotes): string
    {
        $booking = $rescheduleRequest->booking;
        $reason = $adminNotes ?: 'Reschedule request rejected by camp administration.';

        DB::transaction(function () use ($rescheduleRequest, $booking, $reason, $operator) {
            // Back to confirmed only while the booking is still waiting on this request (never reactivates a cancelled one)
            // and has no held payment (never confirmed around a payment exception)
            $locked = $this->lockAwaiting($booking, BookingStatus::RescheduleRequested);
            $this->exceptions->assertNotHeld($locked, 'returned to confirmed by rejecting this request');
            $this->claimPending($rescheduleRequest);
            $locked->update(['status' => BookingStatus::Confirmed->value]);

            $rescheduleRequest->update([
                'status' => 'rejected',
                'admin_notes' => $reason,
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => BookingStatus::RescheduleRequested->value,
                'new_status' => BookingStatus::Confirmed->value,
                'changed_by' => $operator->id,
                'note' => "Reschedule request rejected by {$operator->name} - Reason: {$reason}",
                'created_at' => now(),
            ]);
        });

        $this->emailGuest($booking, fn (Booking $fresh) => new RescheduleRejectedMail($fresh, $rescheduleRequest, $reason));

        return $reason;
    }

    /**
     * Cancel the booking. Approving a cancellation never moves money (Batch 3-I): unless staff pick "forfeit", it opens
     * the booking's refund case (RefundCaseService), where the reason is confirmed, the policy amount computed from the
     * actual receipts, an amount approved and only then paid out (RefundExecutor). Staff's option and amount are kept
     * on the case as a request. The amount quoted to the guest when they asked (calculated_refund_amount) is preserved.
     *
     * @return array{refund_amount: float, forfeited: bool, case: ?BookingRefund}
     */
    public function approveCancellation(CancellationRequest $cancellationRequest, User $operator, string $actionType, ?float $customAmount, ?string $adminNotes): array
    {
        $booking = $cancellationRequest->booking;
        $previousBatch = $booking->batch()->first();
        $isForfeited = $actionType === 'forfeit';
        $requested = match ($actionType) {
            'forfeit', 'full_refund' => null, // "everything paid" is computed from the receipts on the case
            default => $customAmount ?? (float) $cancellationRequest->calculated_refund_amount,
        };

        $case = DB::transaction(function () use ($cancellationRequest, $booking, $adminNotes, $operator, $isForfeited, $actionType, $requested) {
            // Only while the booking still waits on this request: a camp cancellation since keeps its own reason and
            // refund case (never relabelled as a guest cancellation)
            $locked = $this->lockAwaiting($booking, BookingStatus::CancellationRequested);
            $this->claimPending($cancellationRequest);
            if ($isForfeited) {
                $this->guard->assertMayForfeit($cancellationRequest); // earlier-terms quote or open case: never "no refund" here
            }

            $locked->update([
                'status' => BookingStatus::CancelledByGuest->value,
                'batch_id' => null,
            ]);

            $cancellationRequest->update([
                'status' => 'approved',
                'admin_notes' => $adminNotes ?: ($isForfeited
                    ? 'Cancellation approved. No refund (downpayment kept per policy).'
                    : 'Cancellation approved. Any refund is reviewed separately.'),
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => BookingStatus::CancellationRequested->value,
                'new_status' => BookingStatus::CancelledByGuest->value,
                'changed_by' => $operator->id,
                'note' => "Cancellation approved by {$operator->name} (" . ($isForfeited ? 'no refund' : 'refund reviewed separately') . ')' . ($adminNotes ? " - {$adminNotes}" : ''),
                'created_at' => now(),
            ]);

            return $isForfeited ? null : $this->cases->openForCustomerCancellation($cancellationRequest->fresh(), $operator, $actionType, $requested);
        });

        $underReview = $case !== null && in_array($case->status, BookingRefund::OPEN_STATUSES, true);
        $this->tellCoaches($previousBatch, 'Booking cancelled', "Booking #{$booking->booking_number} was cancelled by Camp Administration. Please review the updated roster in the Coach Portal.", $booking);
        $this->emailGuest($booking, fn (Booking $fresh) => new CancellationApprovedMail($fresh, $cancellationRequest, 0.0, $isForfeited, $underReview));

        return ['refund_amount' => 0.0, 'forfeited' => $isForfeited, 'case' => $case];
    }

    /** Lock the booking row (always before the request row) and require that it still waits on this kind of request. */
    private function lockAwaiting(Booking $booking, BookingStatus $expected): Booking
    {
        $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        $this->assertAwaiting($locked, $expected);

        return $locked;
    }

    /**
     * A request decided after the booking changed (cancelled by the camp, completed, or another decision) is out of
     * date: refused, nothing is changed. The request stays pending for staff to see.
     */
    private function assertAwaiting(Booking $locked, BookingStatus $expected): void
    {
        if ($locked->status !== $expected->value) {
            throw new RuntimeException('This request is out of date: the booking is now ' . str_replace('_', ' ', $locked->status)
                . '. Nothing was changed. Review the booking (and its refund case, if any) before deciding anything else.');
        }
    }

    /** Lock a request row and make sure nobody decided it already (double submit, two staff members at once). */
    private function claimPending(Model $request): void
    {
        $locked = $request::whereKey($request->getKey())->lockForUpdate()->first();
        if (! $locked || $locked->status !== 'pending') {
            throw new RuntimeException('This request was already decided.');
        }
    }

    /** Keep the booking active. */
    public function rejectCancellation(CancellationRequest $cancellationRequest, User $operator, ?string $adminNotes): string
    {
        $booking = $cancellationRequest->booking;
        $reason = $adminNotes ?: 'Cancellation request rejected by camp administration.';

        DB::transaction(function () use ($cancellationRequest, $booking, $reason, $operator, $adminNotes) {
            $locked = $this->lockAwaiting($booking, BookingStatus::CancellationRequested);
            $this->exceptions->assertNotHeld($locked, 'returned to confirmed by rejecting this request');
            $this->claimPending($cancellationRequest);
            $this->guard->assertMayReject($cancellationRequest, $operator, $adminNotes);
            $locked->update(['status' => BookingStatus::Confirmed->value]);

            $cancellationRequest->update([
                'status' => 'rejected',
                'admin_notes' => $reason,
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => BookingStatus::CancellationRequested->value,
                'new_status' => BookingStatus::Confirmed->value,
                'changed_by' => $operator->id,
                'note' => "Cancellation rejected by {$operator->name} - Reason: {$reason}",
                'created_at' => now(),
            ]);
        });

        $this->emailGuest($booking, fn (Booking $fresh) => new CancellationRejectedMail($fresh, $cancellationRequest, $reason));

        return $reason;
    }

    protected function tellCoaches(?Batch $batch, string $title, string $message, Booking $booking): void
    {
        if ($batch) {
            $this->coachNotifier->notifyBatchCoaches($batch, $title, $message, $booking->booking_number);
        }
    }

    /** @param  callable(Booking): Mailable  $makeMail */
    protected function emailGuest(Booking $booking, callable $makeMail): void
    {
        if (!$booking->contact_email) {
            return;
        }

        try {
            Mail::to($booking->contact_email)->send($makeMail($booking->fresh()));
        } catch (\Throwable $e) {
            Log::warning("Failed to send booking request email to {$booking->contact_email}: " . $e->getMessage());
        }
    }
}
