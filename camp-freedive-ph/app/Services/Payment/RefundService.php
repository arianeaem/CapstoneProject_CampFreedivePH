<?php

namespace App\Services\Payment;

use App\Enums\BookingStatus;
use App\Models\BookingRefund;
use App\Models\BookingStatusLog;
use App\Models\PaymentStatusLog;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundGuard;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The legacy refund queue (refund_requests, one row per payment). Since Batch 3-I nothing here moves money:
 *  - "approve" opens (or joins) the booking's refund case, where the reason is confirmed and an amount approved
 *    from the actual receipts before RefundExecutor pays it out;
 *  - "reject" and "forfeit" are decisions only. Payment rows keep their receipt status (no more status flips that
 *    hide receipts), and a camp cancellation is never relabelled as a guest cancellation.
 * Every action first claims the request (pending -> decided) under a row lock, so it is decided once.
 */
class RefundService
{
    public function __construct(protected RefundCaseService $cases, protected RefundGuard $guard)
    {
    }

    /** @return BookingRefund the case to review (no money moved) */
    public function approve(RefundRequest $refundRequest, User $operator): BookingRefund
    {
        return $this->cases->openFromLegacyRequest($refundRequest, $operator);
    }

    /** Turn the request down: the booking goes back to confirmed only if it was waiting on this cancellation. */
    public function reject(RefundRequest $refundRequest, User $operator, string $notes): void
    {
        DB::transaction(function () use ($refundRequest, $operator, $notes) {
            $request = $this->claim($refundRequest);
            $this->guard->assertMayReject($request, $operator, $notes);
            $booking = $request->booking;

            $request->update(['status' => 'rejected', 'notes' => $notes, 'reviewed_by' => $operator->id, 'reviewed_at' => now()]);

            // A payment marked "refund requested" goes back to its received status: online "paid", manual "completed"
            $payment = $request->payment;
            $oldStatus = $payment?->status ?? 'unknown';
            if ($payment && $payment->status === 'refund_requested') {
                $payment->update(['status' => str_starts_with((string) $payment->paymongo_payment_id, 'pay_') ? 'paid' : 'completed']);
            }

            PaymentStatusLog::create([
                'payment_id' => $request->payment_id,
                'old_status' => $oldStatus,
                'new_status' => $payment?->status ?? 'unknown',
                'changed_by' => $operator->id,
                'note' => "Refund request rejected by {$operator->name} - Reason: {$notes}",
                'created_at' => now(),
            ]);

            if ($booking->status === BookingStatus::CancellationRequested->value) {
                $booking->update(['status' => BookingStatus::Confirmed->value]);
                BookingStatusLog::create([
                    'booking_id' => $booking->id,
                    'old_status' => BookingStatus::CancellationRequested->value,
                    'new_status' => BookingStatus::Confirmed->value,
                    'changed_by' => $operator->id,
                    'note' => "Cancellation & refund rejected by {$operator->name} - Reason: {$notes}",
                    'created_at' => now(),
                ]);
            }
        });
    }

    /**
     * Keep the payment per policy (late cancellation or no-show). Not allowed for camp cancellations: the guest is
     * owed a free move or their money back there.
     *
     * @return float The amount kept
     */
    public function forfeit(RefundRequest $refundRequest, User $operator, string $reason, ?string $notes): float
    {
        return DB::transaction(function () use ($refundRequest, $operator, $reason, $notes) {
            $request = $this->claim($refundRequest);
            if ($request->requested_by === 'camp_force_majeure') {
                throw new RuntimeException('A camp cancellation cannot be forfeited: the guest may move dates or be refunded. Open the refund case instead.');
            }
            $this->guard->assertMayForfeit($request); // earlier-terms quote or open refund case: never forfeited here
            $payment = $request->payment;
            $booking = $request->booking;
            $amount = (float) $payment->amount;

            // "forfeited" is still a receipt (counted as paid and retained) but no longer refundable
            $payment->update(['status' => 'forfeited', 'is_forfeited' => true, 'forfeited_amount' => $amount, 'forfeit_reason' => $reason]);
            $request->update([
                'status' => 'forfeited',
                'forfeit_reason' => $reason,
                'notes' => $notes ?? 'Deposit forfeited per camp cancellation policy rules.',
                'reviewed_by' => $operator->id,
                'reviewed_at' => now(),
            ]);

            PaymentStatusLog::create([
                'payment_id' => $payment->id,
                'old_status' => $payment->getOriginal('status') ?? 'paid',
                'new_status' => 'forfeited',
                'changed_by' => $operator->id,
                'note' => 'Payment of ₱' . number_format($amount, 2) . " kept (forfeited). Reason: {$reason}" . ($notes ? " - {$notes}" : ''),
                'created_at' => now(),
            ]);

            // Only a booking still waiting on this request changes status; a camp cancellation keeps its label
            if (in_array($booking->status, [BookingStatus::CancellationRequested->value, BookingStatus::Confirmed->value], true)) {
                $newStatus = $reason === 'customer_no_show' ? BookingStatus::NoShow : BookingStatus::CancelledByGuest;
                $booking->update(['status' => $newStatus->value]);
                BookingStatusLog::create([
                    'booking_id' => $booking->id,
                    'old_status' => BookingStatus::CancellationRequested->value,
                    'new_status' => $newStatus->value,
                    'changed_by' => $operator->id,
                    'note' => "Booking cancelled. Downpayment forfeited per camp policy ({$reason}).",
                    'created_at' => now(),
                ]);
            }

            return $amount;
        });
    }

    private function claim(RefundRequest $refundRequest): RefundRequest
    {
        $locked = RefundRequest::whereKey($refundRequest->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== 'pending') {
            throw new RuntimeException('This refund request was already handled.');
        }

        return $locked;
    }
}
