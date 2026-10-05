<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Mail\CancellationApprovedMail;
use App\Mail\CancellationRejectedMail;
use App\Mail\RescheduleApprovedMail;
use App\Mail\RescheduleRejectedMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\CancellationRequest;
use App\Models\PaymentStatusLog;
use App\Models\RefundRequest;
use App\Models\RescheduleRequest;
use App\Models\User;
use App\Services\BatchManagementService;
use App\Services\BookingPolicyEngine;
use App\Services\CoachNotificationService;
use App\Services\Payment\RefundService;
use App\Services\PayMongoService;
use Carbon\Carbon;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Staff decisions on guests' reschedule and cancellation requests.
 * Each decision updates the booking and request together, writes the status log,
 * tells the affected coaches and emails the guest.
 */
class BookingRequestService
{
    public function __construct(
        protected BookingPolicyEngine $policyEngine,
        protected BatchManagementService $batchService,
        protected PayMongoService $payMongo,
        protected RefundService $refunds,
        protected CoachNotificationService $coachNotifier,
    ) {}

    /** Move the booking to the requested dates (creating the batch if needed). */
    public function approveReschedule(RescheduleRequest $rescheduleRequest, User $operator, ?string $adminNotes): Booking
    {
        $booking = $rescheduleRequest->booking;
        $previousBatch = $booking->batch()->with('assigned_coaches')->first();

        DB::transaction(function () use ($rescheduleRequest, $booking, $adminNotes, $operator) {
            $oldDates = "{$booking->start_date->format('M d, Y')} - {$booking->end_date->format('M d, Y')}";
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
     * Cancel the booking and refund based on the option picked:
     * 'full_refund', 'forfeit', or 'policy_refund' (policy amount, can be changed by staff).
     *
     * @return array{refund_amount: float, forfeited: bool}
     */
    public function approveCancellation(CancellationRequest $cancellationRequest, User $operator, string $actionType, ?float $customAmount, ?string $adminNotes): array
    {
        $booking = $cancellationRequest->booking;
        $previousBatch = $booking->batch()->with('assigned_coaches')->first();
        $policy = $this->policyEngine->evaluate($booking);

        [$refundAmount, $refundPercentage, $isForfeited] = match ($actionType) {
            'forfeit' => [0.00, 0, true],
            'full_refund' => [(float) $booking->paid_amount, 100, false],
            default => (function () use ($customAmount, $policy, $cancellationRequest) {
                $amount = $customAmount ?? (float) ($policy['calculated_refund'] ?? $cancellationRequest->calculated_refund_amount);

                return [$amount, $policy['refund_percentage'] ?? 0, $amount <= 0];
            })(),
        };

        // Do the PayMongo refund first, only save it once the money is sent
        $paymongoRefundId = null;
        if (!$isForfeited && $refundAmount > 0) {
            foreach ($booking->payments()->whereIn('status', ['completed', 'paid'])->get() as $payment) {
                $result = $this->payMongo->refund(
                    $this->refunds->resolvePayMongoPaymentId($payment),
                    $refundAmount,
                    'requested_by_customer',
                    $adminNotes ?? 'Camp FreedivePH Approved Cancellation Refund'
                );
                $paymongoRefundId = $result['refund_id'] ?? ('ref_' . bin2hex(random_bytes(8)));
            }
        }

        DB::transaction(function () use ($cancellationRequest, $booking, $adminNotes, $operator, $policy, $refundAmount, $refundPercentage, $isForfeited, $paymongoRefundId) {
            $booking->update([
                'status' => BookingStatus::CancelledByGuest->value,
                'batch_id' => null,
            ]);

            $cancellationRequest->update([
                'status' => 'approved',
                'calculated_refund_amount' => $refundAmount,
                'admin_notes' => $adminNotes ?: ($isForfeited ? 'Cancellation approved (Downpayment forfeited per policy).' : 'Cancellation and refund of ₱' . number_format($refundAmount, 2) . ' processed successfully.'),
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            foreach ($booking->payments()->whereIn('status', ['completed', 'paid', 'refunded'])->get() as $payment) {
                if (!$isForfeited && $refundAmount > 0) {
                    $payment->update([
                        'status' => 'refunded',
                        'paymongo_refund_id' => $paymongoRefundId,
                        'amount_refunded' => $refundAmount,
                        'refund_reason' => $adminNotes ?? 'Admin approved customer cancellation refund',
                    ]);

                    PaymentStatusLog::create([
                        'payment_id' => $payment->id,
                        'old_status' => 'completed',
                        'new_status' => 'refunded',
                        'changed_by' => $operator->id,
                        'note' => 'Direct 1-step refund of ₱' . number_format($refundAmount, 2) . " executed via PayMongo (Refund ID: {$paymongoRefundId})",
                        'created_at' => now(),
                    ]);
                }

                RefundRequest::create([
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id,
                    'requested_by' => 'guest_cancellation',
                    'requested_at' => now(),
                    'eligibility_calculated' => [
                        'days_until_dive' => max(0, Carbon::now()->diffInDays($booking->start_date, false)),
                        'eligible_for_refund' => !$isForfeited && ($refundAmount > 0),
                        'refund_percentage' => $refundPercentage,
                        'window_label' => $policy['policy_tier'] ?? 'Standard Policy',
                        'policy_action_text' => $isForfeited ? 'Cancellation within forfeiture window.' : 'Approved & processed refund of ₱' . number_format($refundAmount, 2),
                    ],
                    'status' => $isForfeited ? 'forfeited' : 'approved',
                    'paymongo_refund_id' => $paymongoRefundId,
                    'forfeit_reason' => $isForfeited ? 'cancellation_outside_policy_window' : null,
                    'notes' => 'Processed directly via Guest Cancellation Request. ' . ($adminNotes ?? ''),
                    'reviewed_by' => $operator->id,
                    'reviewed_at' => now(),
                ]);
            }

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => BookingStatus::CancellationRequested->value,
                'new_status' => BookingStatus::CancelledByGuest->value,
                'changed_by' => $operator->id,
                'note' => "Cancellation approved by {$operator->name} (" . ($isForfeited ? 'Forfeited' : 'Refund processed: ₱' . number_format($refundAmount, 2)) . ')' . ($adminNotes ? " - {$adminNotes}" : ''),
                'created_at' => now(),
            ]);
        });

        $this->tellCoaches($previousBatch, 'Booking cancelled', "Booking #{$booking->booking_number} was cancelled by Camp Administration. Please review the updated roster in the Coach Portal.", $booking);
        $this->emailGuest($booking, fn (Booking $fresh) => new CancellationApprovedMail($fresh, $cancellationRequest, $refundAmount, $isForfeited));

        return ['refund_amount' => $refundAmount, 'forfeited' => $isForfeited];
    }

    /** Keep the booking active. */
    public function rejectCancellation(CancellationRequest $cancellationRequest, User $operator, ?string $adminNotes): string
    {
        $booking = $cancellationRequest->booking;
        $reason = $adminNotes ?: 'Cancellation request rejected by camp administration.';

        DB::transaction(function () use ($cancellationRequest, $booking, $reason, $operator) {
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
