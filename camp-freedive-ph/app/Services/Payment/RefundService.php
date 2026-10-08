<?php

namespace App\Services\Payment;

use App\Enums\BookingStatus;
use App\Models\BookingStatusLog;
use App\Models\Payment;
use App\Models\PaymentStatusLog;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\PayMongoService;
use Illuminate\Support\Facades\DB;

/**
 * Refund queue actions: pay a refund through PayMongo, reject it, or keep the money per policy.
 * Each action updates the payment, the refund request and the booking together, with status logs.
 */
class RefundService
{
    public function __construct(protected PayMongoService $payMongo)
    {
    }

    /**
     * The PayMongo payment id ("pay_...") to refund. Older payments only stored the checkout
     * session, so the id is looked up from the session once and saved on the payment.
     */
    public function resolvePayMongoPaymentId(Payment $payment): string
    {
        $paymentId = $payment->paymongo_payment_id;

        if ((empty($paymentId) || !str_starts_with($paymentId, 'pay_')) && !empty($payment->paymongo_resource_id)) {
            $session = $this->payMongo->getCheckoutSession($payment->paymongo_resource_id);
            $sessionPayments = $session['data']['attributes']['payments'] ?? [];
            if (!empty($sessionPayments[0]['id'])) {
                $paymentId = $sessionPayments[0]['id'];
                $payment->update(['paymongo_payment_id' => $paymentId]);
            }
        }

        return $paymentId ?: ($payment->transaction_id ?: 'offline');
    }

    /**
     * Send the money back through PayMongo and close the booking.
     *
     * @return array{success: bool, error?: string, refund_id?: string, amount?: float}
     */
    public function approve(RefundRequest $refundRequest, User $operator, ?string $notes): array
    {
        $payment = $refundRequest->payment;
        $booking = $refundRequest->booking;
        $amount = (float) ($refundRequest->refund_amount ?: $payment->amount);

        $result = $this->payMongo->refund(
            $this->resolvePayMongoPaymentId($payment),
            $amount,
            'requested_by_customer',
            $notes ?? 'Camp FreedivePH Approved Cancellation Refund'
        );

        if (!$result['success']) {
            return ['success' => false, 'error' => $result['error'] ?? 'PayMongo refund execution failed. Please retry.'];
        }

        $refundId = $result['refund_id'] ?? ('ref_' . bin2hex(random_bytes(8)));

        DB::transaction(function () use ($refundRequest, $payment, $booking, $refundId, $amount, $notes, $operator) {
            $payment->update([
                'status' => 'refunded',
                'paymongo_refund_id' => $refundId,
                'amount_refunded' => $amount,
                'refund_reason' => $notes ?? 'Admin approved participant cancellation refund',
            ]);

            $refundRequest->update([
                'status' => 'approved',
                'paymongo_refund_id' => $refundId,
                'notes' => $notes ?? 'Refund processed via PayMongo API',
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            $booking->update(['status' => BookingStatus::CancelledByGuest->value]);

            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => 'refund_requested',
                'new_status' => 'refunded',
                'changed_by' => $operator->id,
                'note' => 'Refund of ₱' . number_format($amount, 2) . " executed via PayMongo (Refund ID: {$refundId})",
                'created_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => BookingStatus::CancellationRequested->value,
                'new_status' => BookingStatus::CancelledByGuest->value,
                'changed_by' => $operator->id,
                'note' => 'Booking cancelled. 100% refund of ₱' . number_format($amount, 2) . ' credited to participant via PayMongo.',
                'created_at' => now(),
            ]);
        });

        return ['success' => true, 'refund_id' => $refundId, 'amount' => $amount];
    }

    /** Turn the refund down: the payment stays and the booking goes back to confirmed. */
    public function reject(RefundRequest $refundRequest, User $operator, string $notes): void
    {
        $payment = $refundRequest->payment;
        $booking = $refundRequest->booking;

        DB::transaction(function () use ($refundRequest, $payment, $booking, $notes, $operator) {
            $payment->update(['status' => 'completed']);
            $booking->update(['status' => BookingStatus::Confirmed->value]);

            $refundRequest->update([
                'status' => 'rejected',
                'notes' => $notes,
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => 'refund_requested',
                'new_status' => 'completed',
                'changed_by' => $operator->id,
                'note' => "Refund request rejected by {$operator->name} - Reason: {$notes}",
                'created_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => BookingStatus::CancellationRequested->value,
                'new_status' => BookingStatus::Confirmed->value,
                'changed_by' => $operator->id,
                'note' => "Cancellation & refund rejected by {$operator->name} - Reason: {$notes}",
                'created_at' => now(),
            ]);
        });
    }

    /**
     * Keep the payment per policy (late cancellation or no-show).
     *
     * @return float The amount kept
     */
    public function forfeit(RefundRequest $refundRequest, User $operator, string $reason, ?string $notes): float
    {
        $payment = $refundRequest->payment;
        $booking = $refundRequest->booking;
        $amount = (float) $payment->amount;

        DB::transaction(function () use ($refundRequest, $payment, $booking, $reason, $notes, $amount, $operator) {
            $payment->update([
                'status' => 'forfeited',
                'is_forfeited' => true,
                'forfeited_amount' => $amount,
                'forfeit_reason' => $reason,
            ]);

            $refundRequest->update([
                'status' => 'forfeited',
                'forfeit_reason' => $reason,
                'notes' => $notes ?? 'Deposit forfeited per camp cancellation policy rules.',
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            $bookingStatus = $reason === 'customer_no_show' ? BookingStatus::NoShow : BookingStatus::CancelledByGuest;
            $booking->update(['status' => $bookingStatus->value]);

            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => 'refund_requested',
                'new_status' => 'forfeited',
                'changed_by' => $operator->id,
                'note' => 'Payment of ₱' . number_format($amount, 2) . " forfeited. Reason: {$reason}" . ($notes ? " - {$notes}" : ''),
                'created_at' => now(),
            ]);

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => BookingStatus::CancellationRequested->value,
                'new_status' => $bookingStatus->value,
                'changed_by' => $operator->id,
                'note' => "Booking cancelled. Downpayment forfeited per camp policy ({$reason}).",
                'created_at' => now(),
            ]);
        });

        return $amount;
    }
}
