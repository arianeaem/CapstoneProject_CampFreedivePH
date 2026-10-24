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
use App\Services\CoachNotificationService;
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
 */
class BookingRequestService
{
    public function __construct(
        protected BookingPolicyEngine $policyEngine,
        protected BatchManagementService $batchService,
        protected RefundCaseService $cases,
        protected CoachNotificationService $coachNotifier,
        protected RefundGuard $guard,
    ) {}

    /** Move the booking to the requested dates (creating the batch if needed). */
    public function approveReschedule(RescheduleRequest $rescheduleRequest, User $operator, ?string $adminNotes): Booking
    {
        $booking = $rescheduleRequest->booking;
        $previousBatch = $booking->batch()->first();

        DB::transaction(function () use ($rescheduleRequest, $booking, $adminNotes, $operator) {
            $this->claimPending($rescheduleRequest);
            $oldDates ="{$booking->start_date->format('M d, Y')} - {$booking->end_date->format('M d, Y')}";
            $newDates = "{$rescheduleRequest->requested_start_date->format('M d, Y')} - {$rescheduleRequest->requested_end_date->format('M d, Y')}";

            $batch = $this->batchService->findOrCreateBatchForDates(
                $rescheduleRequest->requested_start_date,
                $rescheduleRequest->requested_end_date,
                $operator
            );

            $booking->update([
                'batch_id' => $batch->id,
                'start_date' => $rescheduleRequest->requested_start_date,
                'end_date' => $rescheduleRequest->requested_end_date,
                'status' => BookingStatus::Confirmed->value,
            ]);

            $rescheduleRequest->update([
                'status' => 'approved',
                'admin_notes' => $adminNotes ?: 'Reschedule request approved by camp staff.',
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => BookingStatus::RescheduleRequested->value,
                'new_status' => BookingStatus::Confirmed->value,
                'changed_by' => $operator->id,
                'note' => "Reschedule approved ({$oldDates} {$newDates}, attached to {$batch->batch_code})" . ($adminNotes ? " - {$adminNotes}" : ''),
                'created_at' => now(),
            ]);
        });

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
            $this->claimPending($rescheduleRequest);
            $booking->update(['status' => BookingStatus::Confirmed->value]);

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
            $this->claimPending($cancellationRequest);
            if ($isForfeited) {
                $this->guard->assertMayForfeit($cancellationRequest); // earlier-terms quote or open case: never "no refund" here
            }

            $booking->update([
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
            $this->claimPending($cancellationRequest);
            $this->guard->assertMayReject($cancellationRequest, $operator, $adminNotes);
            $booking->update(['status' => BookingStatus::Confirmed->value]);

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
