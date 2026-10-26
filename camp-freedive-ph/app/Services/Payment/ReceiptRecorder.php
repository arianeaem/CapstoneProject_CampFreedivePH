<?php

namespace App\Services\Payment;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\ProviderIdentityConflict;
use App\Models\ProviderPaymentIdentity;
use App\Models\RefundAttempt;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The single place that turns a verified provider payment into a receipt (Batch 3-II B1). Used by checkout
 * finalization and legacy payment confirmation (webhook and return page alike); reconciliation goes through the
 * finalizer. Always called inside the caller's transaction, after its locks (dates -> booking -> intent).
 *
 * The provider identity row (provider + payment id, database-unique) is the gate. A provider payment seen before is
 * accepted as a replay only when the amount (integer centavos), currency, live/test mode and owner all match the
 * verified facts recorded with it, and its receipt still exists. Anything else is a conflict: kept for staff once per
 * distinct attempt, audited, and nothing is recorded, moved or overwritten. Owners: a checkout intent (with or without
 * its booking) or a legacy booking.
 *
 * Rows recorded before identities existed have none. They are never claimed here: a single receipt with the same
 * owner and amount is a read-only replay; duplicates or anything else are conflicts (a later approved backfill handles
 * them). Two workers recording the same new payment: the unique identity lets exactly one write its receipt; the
 * other waits for it and compares against it.
 */
class ReceiptRecorder
{
    /** Compare without writing a receipt (a conflict is still kept). NOT_RECORDED: the provider payment is new. */
    public function inspect(ProviderPayment $payment, ?int $intentId, ?int $bookingId, string $source): ReceiptOutcome
    {
        $this->assertOwner($intentId, $bookingId);
        $identity = ProviderPaymentIdentity::where('provider', $payment->provider)->where('provider_payment_id', $payment->id)->first();
        if ($identity) {
            return $this->compare($identity, $payment, $intentId, $bookingId, $source);
        }

        $legacy = Payment::where('paymongo_payment_id', $payment->id)->orderBy('id')->get();
        if ($legacy->isEmpty()) {
            return new ReceiptOutcome(ReceiptOutcome::NOT_RECORDED);
        }
        if ($legacy->count() > 1) {
            return $this->conflict(ReceiptOutcome::HISTORICAL, 'historical_duplicate', $payment, $intentId, $bookingId, $source);
        }

        $row = $legacy->first();
        $rowIntent = $this->id($row->checkout_intent_id);
        $rowBooking = $this->id($row->booking_id);
        $sameOwner = $intentId !== null
            ? $rowIntent === $intentId || ($rowIntent === null && $bookingId !== null && $rowBooking === $bookingId)
            : $rowIntent === null && $rowBooking === $bookingId;

        return match (true) {
            ! in_array($row->status, Payment::RECEIPT_STATUSES, true) => $this->conflict(ReceiptOutcome::HISTORICAL, 'receipt_missing', $payment, $intentId, $bookingId, $source, null, $row),
            $row->amountCentavos() !== $payment->amountCentavos => $this->conflict(ReceiptOutcome::PROVIDER_MISMATCH, 'amount', $payment, $intentId, $bookingId, $source, null, $row),
            ! $sameOwner => $this->conflict(ReceiptOutcome::OWNER_MISMATCH, 'owner', $payment, $intentId, $bookingId, $source, null, $row),
            default => new ReceiptOutcome(ReceiptOutcome::ALREADY_RECORDED_LEGACY, $row),
        };
    }

    /**
     * Record a new provider payment: claim its identity, then $writeReceipt() creates (or fills) the receipt row, which
     * must carry the same id, amount and owner. Returns the inspect() outcome instead when the payment was seen before
     * (including by a concurrent worker), without calling $writeReceipt.
     *
     * @param callable(): Payment $writeReceipt
     */
    public function record(ProviderPayment $payment, ?int $intentId, ?int $bookingId, string $source, callable $writeReceipt): ReceiptOutcome
    {
        $this->assertInTransaction();
        $seen = $this->inspect($payment, $intentId, $bookingId, $source);
        if ($seen->status !== ReceiptOutcome::NOT_RECORDED) {
            return $seen;
        }

        try {
            // Savepoint: a unique violation (another worker claimed it first) leaves the caller's transaction usable
            $identity = DB::transaction(fn () => ProviderPaymentIdentity::create([
                'provider' => $payment->provider,
                'provider_payment_id' => $payment->id,
                'amount_centavos' => $payment->amountCentavos,
                'currency' => $payment->currency,
                'livemode' => $payment->livemode,
                'checkout_intent_id' => $intentId,
                'booking_id' => $bookingId,
                'status' => ProviderPaymentIdentity::CLAIMED,
                'verification' => ProviderPaymentIdentity::PROVIDER_VERIFIED,
                'source' => mb_substr($source, 0, 48),
            ]));
        } catch (UniqueConstraintViolationException) {
            return $this->inspect($payment, $intentId, $bookingId, $source);
        }

        $receipt = $writeReceipt();
        if ($receipt->paymongo_payment_id !== $payment->id || $receipt->amountCentavos() !== $payment->amountCentavos
            || $this->id($receipt->checkout_intent_id) !== $intentId || $this->id($receipt->booking_id) !== $bookingId
            || ! in_array($receipt->status, Payment::RECEIPT_STATUSES, true)) {
            throw new LogicException('The receipt written does not match the verified provider payment; nothing is kept.');
        }
        $identity->update(['payment_id' => $receipt->id]);

        return new ReceiptOutcome(ReceiptOutcome::RECORDED, $receipt, $identity);
    }

    /**
     * Give an unapplied checkout receipt its booking (the only change a receipt's owner may get: empty -> the booking
     * created from the same checkout intent). Only for an authorized placement; B1 has no caller (the B2 guest-choice
     * workflow will). Refused while the receipt has refund activity or an unresolved payment exception.
     */
    public function attach(Payment $receipt, Booking $booking, string $source): ReceiptOutcome
    {
        $this->assertInTransaction();
        $locked = Payment::whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        $identity = ProviderPaymentIdentity::where('payment_id', $locked->id)->lockForUpdate()->first();
        $intentId = $this->id($locked->checkout_intent_id);

        $blocked = match (true) {
            $locked->booking_id !== null => 'already_applied',
            $intentId === null || $this->id($booking->checkout_intent_id) !== $intentId => 'different_owner',
            $identity === null || ! $identity->isVerified() || $this->id($identity->checkout_intent_id) !== $intentId => 'identity_unverified',
            ! in_array($locked->status, Payment::RECEIPT_STATUSES, true) => 'not_a_receipt',
            RefundAttempt::where('payment_id', $locked->id)->where('status', '!=', RefundAttempt::FAILED)->exists() => 'refund_activity',
            PaymentExceptionService::heldByException([$locked->id]) !== [] => 'payment_exception_open',
            default => null,
        };
        if ($blocked !== null) {
            return new ReceiptOutcome(ReceiptOutcome::ATTACH_BLOCKED, $locked, $identity, $blocked);
        }

        $locked->update(['booking_id' => $booking->id]);
        $identity->update(['booking_id' => $booking->id]);
        AuditLogger::log('PAYMENT_RECEIPT_ATTACHED', "Receipt #{$locked->id} ({$locked->paymongo_payment_id}) of checkout intent #{$intentId} applied to booking {$booking->booking_number} ({$source}).", null, 'Receipt recorder');

        return new ReceiptOutcome(ReceiptOutcome::ATTACHED, $locked, $identity);
    }

    private function compare(ProviderPaymentIdentity $identity, ProviderPayment $payment, ?int $intentId, ?int $bookingId, string $source): ReceiptOutcome
    {
        $idIntent = $this->id($identity->checkout_intent_id);
        $idBooking = $this->id($identity->booking_id);
        $sameOwner = $intentId !== null
            ? $idIntent === $intentId && ($bookingId === null || $idBooking === $bookingId)
            : $idIntent === null && $idBooking === $bookingId;
        $receipt = $identity->payment_id !== null ? Payment::find($identity->payment_id) : null;

        $mismatch = match (true) {
            ! $identity->isVerified() => [ReceiptOutcome::HISTORICAL, 'historical_unverified'],
            $identity->amount_centavos !== $payment->amountCentavos => [ReceiptOutcome::PROVIDER_MISMATCH, 'amount'],
            $identity->currency !== $payment->currency => [ReceiptOutcome::PROVIDER_MISMATCH, 'currency'],
            $identity->livemode !== $payment->livemode => [ReceiptOutcome::PROVIDER_MISMATCH, 'livemode'],
            ! $sameOwner => [ReceiptOutcome::OWNER_MISMATCH, 'owner'],
            $receipt === null || ! in_array($receipt->status, Payment::RECEIPT_STATUSES, true) => [ReceiptOutcome::HISTORICAL, 'receipt_missing'],
            default => null,
        };

        return $mismatch === null
            ? new ReceiptOutcome(ReceiptOutcome::ALREADY_RECORDED, $receipt, $identity)
            : $this->conflict($mismatch[0], $mismatch[1], $payment, $intentId, $bookingId, $source, $identity, $receipt);
    }

    /** Keep the refused attempt once (same provider payment, reason and claimed facts) and audit it; nothing else changes. */
    private function conflict(string $status, string $reason, ProviderPayment $payment, ?int $intentId, ?int $bookingId, string $source,
        ?ProviderPaymentIdentity $identity = null, ?Payment $existing = null): ReceiptOutcome
    {
        $attempted = ['checkout_intent_id' => $intentId, 'booking_id' => $bookingId] + $payment->facts();
        $fingerprint = hash('sha256', implode('|', [$payment->provider, $payment->id, $reason, json_encode($attempted)]));

        try {
            DB::transaction(fn () => ProviderIdentityConflict::create([
                'provider' => $payment->provider,
                'provider_payment_id' => $payment->id,
                'identity_id' => $identity?->id,
                'payment_id' => $existing?->id,
                'reason' => $reason,
                'source' => mb_substr($source, 0, 48),
                'attempted' => $attempted,
                'fingerprint' => $fingerprint,
            ]));
            AuditLogger::log('PAYMENT_IDENTITY_CONFLICT', "Provider payment {$payment->id} was not accepted ({$status}: {$reason}; claimed owner: intent "
                . ($intentId ?? '-') . ', booking ' . ($bookingId ?? '-') . ", from {$source}). Nothing was recorded, moved or overwritten. Needs staff review.", null, 'Receipt recorder');
        } catch (UniqueConstraintViolationException) {
            // The same refused attempt was kept before (a retried webhook or return page)
        }

        return new ReceiptOutcome($status, $existing, $identity, $reason);
    }

    private function assertOwner(?int $intentId, ?int $bookingId): void
    {
        if ($intentId === null && $bookingId === null) {
            throw new LogicException('A receipt needs an owner: a checkout intent or a booking.');
        }
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Receipts are recorded inside the caller\'s locked transaction.');
        }
    }

    private function id(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
