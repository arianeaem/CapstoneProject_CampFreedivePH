<?php

namespace App\Services\Payment;

use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionChoice;
use App\Models\PaymentExceptionEvent;
use App\Models\PaymentExceptionRefundReview;
use App\Models\ProviderPaymentIdentity;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Refunds\RefundGuard;
use App\Services\Refunds\RefundLedger;
use RuntimeException;

/**
 * B3: the owner/admin decision on an exception refund review (client-approved policies P1-P6).
 *
 *  - P1: the entitlement is 100% of the verified, unallocated amount received, no deductions, less anything already
 *    refunded or reserved for that payment (one balance per payment, RefundLedger::paymentRow). Only for exceptions
 *    caused by capacity_unavailable or date_not_operating. The server computes the amount; staff never type one.
 *  - P2: only an active owner or admin decides; the approver's id and role are recorded with the amount and currency.
 *  - P3: rejection is for an invalid or duplicate request only, with a reason; the exception and receipt stay held.
 *  - P4/P5: approval reserves the amount (approved_awaiting_payout) and moves no money. The exception stays open; a
 *    payout and "refunded" need provider execution and verification, which do not exist here.
 *
 * Lock order (as B2): acting staff -> booking or checkout -> exception (GuestChoiceService::withLocks) -> review ->
 * receipt. The database allows only these two transitions, once (migration 2026_10_29_000001).
 */
class ExceptionRefundService
{
    /** Exception causes the approved full-refund policy covers (P1). */
    public const REFUNDABLE_REASONS = ['capacity_unavailable', 'date_not_operating'];

    public function __construct(protected GuestChoiceService $choices, protected RefundLedger $ledger, protected RefundGuard $guard)
    {
    }

    /** Read-only: what approval would reserve now, or why it cannot. For staff screens. */
    public function entitlement(PaymentExceptionRefundReview $review): array
    {
        $exception = $review->paymentException;
        $receipt = Payment::find($review->primary_payment_id);
        try {
            return ['centavos' => $this->check($exception, $review, $receipt), 'currency' => $exception->currency, 'blocked' => null];
        } catch (RuntimeException $e) {
            return ['centavos' => 0, 'currency' => $exception->currency, 'blocked' => $e->getMessage()];
        }
    }

    public function approve(PaymentExceptionRefundReview $review, User $actor, int $expectedVersion, ?string $note = null): PaymentExceptionRefundReview
    {
        $this->guard->assertApprover($actor);
        $note = $note === null || trim($note) === '' ? null : $this->text($note, 'approval note');

        return $this->choices->withLocks($review->paymentException()->firstOrFail(), $actor, function (PaymentException $exception, User $staff) use ($review, $expectedVersion, $note) {
            $r = PaymentExceptionRefundReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($r->status === PaymentExceptionRefundReview::APPROVED_AWAITING_PAYOUT && $r->lock_version === $expectedVersion + 1) {
                return $r; // a replay of the approval that already happened: same outcome, nothing new
            }
            $this->assertCurrent($r, $expectedVersion);
            $receipt = Payment::whereKey($r->primary_payment_id)->lockForUpdate()->firstOrFail();
            $amount = $this->check($exception, $r, $receipt);

            $r->forceFill([
                'status' => PaymentExceptionRefundReview::APPROVED_AWAITING_PAYOUT,
                'approved_centavos' => $amount,
                'approved_currency' => $exception->currency,
                'approved_by' => $staff->id,
                'approver_role' => $staff->role,
                'approved_at' => now(),
                'approval_note' => $note,
                'lock_version' => $r->lock_version + 1,
            ])->save();
            $this->record($exception, $r, $staff, PaymentExceptionEvent::REFUND_APPROVED, 'PAYMENT_EXCEPTION_REFUND_APPROVED',
                "refund of {$exception->currency} " . number_format($amount / 100, 2) . " approved by {$staff->role} #{$staff->id} (full verified amount, no deductions). "
                . 'Reserved for payout; nothing was paid and the exception stays open.', ['approved_centavos' => $amount, 'approver_role' => $staff->role]);

            return $r;
        });
    }

    /** An invalid or duplicate request: rejected with a reason. The verified payment and its exception stay held (P3). */
    public function reject(PaymentExceptionRefundReview $review, User $actor, string $reason, int $expectedVersion): PaymentExceptionRefundReview
    {
        $this->guard->assertApprover($actor);
        $reason = $this->text($reason, 'reason');

        return $this->choices->withLocks($review->paymentException()->firstOrFail(), $actor, function (PaymentException $exception, User $staff) use ($review, $reason, $expectedVersion) {
            $r = PaymentExceptionRefundReview::whereKey($review->id)->lockForUpdate()->firstOrFail();
            if ($r->status === PaymentExceptionRefundReview::REJECTED_INVALID && $r->lock_version === $expectedVersion + 1) {
                return $r; // replay
            }
            $this->assertCurrent($r, $expectedVersion);
            $r->forceFill(['status' => PaymentExceptionRefundReview::REJECTED_INVALID, 'rejected_by' => $staff->id, 'rejected_at' => now(),
                'rejection_reason' => $reason, 'lock_version' => $r->lock_version + 1])->save();
            $this->record($exception, $r, $staff, PaymentExceptionEvent::REFUND_REJECTED, 'PAYMENT_EXCEPTION_REFUND_REJECTED',
                "refund request rejected as invalid by {$staff->role} #{$staff->id}. The verified payment stays held under the open exception; nothing was forfeited.", []);

            return $r;
        });
    }

    private function assertCurrent(PaymentExceptionRefundReview $r, int $expectedVersion): void
    {
        if ($r->status !== PaymentExceptionRefundReview::AWAITING_OWNER_REVIEW || $r->lock_version !== $expectedVersion) {
            throw new RuntimeException("Refund review #{$r->id} changed meanwhile (now " . str_replace('_', ' ', $r->status) . '). Reload it; nothing was changed.');
        }
    }

    /**
     * Identity and eligibility of the review's receipt, read from authoritative records; returns the full entitlement
     * (P1). Under the locks when called from a decision.
     */
    private function check(PaymentException $exception, PaymentExceptionRefundReview $r, ?Payment $receipt): int
    {
        $refuse = fn (string $why) => throw new RuntimeException("This refund cannot be approved: {$why}. Nothing was changed.");
        $choice = PaymentExceptionChoice::find($r->choice_id);
        if ($choice === null || $choice->choice !== PaymentExceptionChoice::REFUND_REVIEW || $choice->status !== PaymentExceptionChoice::ACTED_ON
            || $choice->resolution_reference !== "refund_review:{$r->id}" || (int) $choice->payment_exception_id !== (int) $exception->id) {
            $refuse('the guest\'s verified refund choice does not match this review');
        }
        if ((int) $r->payment_exception_id !== (int) $exception->id || $exception->status !== PaymentException::OPEN
            || ! in_array($exception->kind, PaymentExceptionChoice::SUPPORTED_KINDS, true)) {
            $refuse('the payment exception is not open or not of a refundable kind');
        }
        $identity = $receipt ? ProviderPaymentIdentity::where('payment_id', $receipt->id)->first() : null;
        $owned = $receipt !== null && (int) $receipt->id === (int) $exception->primary_payment_id && (int) $receipt->id === (int) $r->primary_payment_id
            && ($exception->booking_id === null
                ? $receipt->booking_id === null && (int) $receipt->checkout_intent_id === (int) $exception->checkout_intent_id && (int) $r->checkout_intent_id === (int) $exception->checkout_intent_id
                : (int) $receipt->booking_id === (int) $exception->booking_id && (int) $r->booking_id === (int) $exception->booking_id);
        if (! $owned) {
            $refuse('the receipt does not belong to this exception and its checkout or booking');
        }
        if ($identity === null || ! $identity->isVerified() || (int) $identity->id !== (int) $r->provider_payment_identity_id
            || $identity->amount_centavos !== $receipt->amountCentavos() || $identity->amount_centavos !== (int) $r->received_centavos
            || $identity->amount_centavos !== (int) $exception->amount_centavos) {
            $refuse('the payment is not verified, or its verified identity and amounts do not match');
        }
        if ($exception->currency === null || $identity->currency !== $exception->currency) {
            $refuse('the payment currency does not match');
        }
        $reason = $exception->booking_id === null ? CheckoutIntent::whereKey($exception->checkout_intent_id)->value('unallocated_reason') : $receipt->review_reason;
        if (! in_array($reason, self::REFUNDABLE_REASONS, true)) {
            $refuse('the approved full-refund policy covers only payments held because the date was full or not running');
        }
        $amount = $this->ledger->paymentRow($receipt, $r->id)['refundable'];
        if ($amount <= 0) {
            $refuse('nothing of this payment is left to refund (already refunded or reserved)');
        }

        return $amount;
    }

    private function text(string $value, string $label): string
    {
        $value = trim($value);
        if (mb_strlen($value) < RefundGuard::MIN_RATIONALE || mb_strlen($value) > 500) {
            throw new RuntimeException("Give a {$label} of 10 to 500 characters; nothing was changed.");
        }
        if (($kind = GuestChoiceService::sensitiveDataKind($value)) !== null) {
            throw new RuntimeException("The {$label} was not saved: do not include {$kind}; nothing was changed.");
        }

        return $value;
    }

    private function record(PaymentException $exception, PaymentExceptionRefundReview $r, User $staff, string $event, string $audit, string $what, array $details): void
    {
        PaymentExceptionEvent::create(['payment_exception_id' => $exception->id, 'type' => $event, 'actor_id' => $staff->id, 'details' => ['review_id' => $r->id] + $details]);
        AuditLogger::log($audit, "Payment exception #{$exception->id}, refund review #{$r->id}: {$what}", $staff, $staff->name);
    }
}
