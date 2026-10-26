<?php

namespace App\Services\Refunds;

use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\RefundAttempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Report-wide money definitions (same as RefundLedger, summed over payments):
 *   Paid      = receipts (Payment::RECEIPT_STATUSES) before any refund; a refunded or forfeited payment was still received
 *   Refunded  = old-model refunds on payment rows (capped at the payment amount; a "refunded" row without an amount
 *               counts in full) + ledger refunds confirmed by PayMongo or documented offline. Approved, pending or
 *               unknown refunds are NOT refunded.
 *   Retained  = Paid - Refunded
 * Old-model refunds are dated by the payment's last update, ledger refunds by when they were confirmed.
 * position() splits the same receipts by what is known about them (Batch 3-II B1); operational terms only.
 */
class FinancialTotals
{
    /** @param array{0: CarbonInterface, 1: CarbonInterface}|null $range filters on payments.created_at */
    public static function paid(?array $range = null, ?string $paymentType = null): float
    {
        return (float) Payment::whereIn('status', Payment::RECEIPT_STATUSES)
            ->when($range, fn ($q) => $q->whereBetween('created_at', $range))
            ->when($paymentType === 'downpayment', fn ($q) => $q->where('payment_type', 'downpayment'))
            ->when($paymentType === 'balance', fn ($q) => $q->whereIn('payment_type', ['balance_settlement', 'full']))
            ->sum('amount');
    }

    /** @param array{0: CarbonInterface, 1: CarbonInterface}|null $range */
    public static function refunded(?array $range = null): float
    {
        $legacy = (float) Payment::whereIn('status', Payment::LEGACY_REFUNDED_STATUSES)
            ->when($range, fn ($q) => $q->whereBetween('updated_at', $range))
            ->sum(DB::raw('CASE WHEN amount_refunded > 0 AND amount_refunded < amount THEN amount_refunded ELSE amount END'));

        $ledger = (int) RefundAttempt::whereIn('status', RefundAttempt::COMPLETED)
            ->when($range, fn ($q) => $q->whereBetween('resolved_at', $range))
            ->sum('amount_centavos');

        return round($legacy + $ledger / 100, 2);
    }

    /**
     * Where received money stands (pesos; counts where useful). Each receipt is counted once: a checkout payment is a
     * single payments row whether or not it has a booking, and checkout intents are never added on top.
     *   cash_received        all receipts (= paid())
     *   applied              receipts of a booking
     *   unapplied            receipts with no booking yet (a paid checkout that could not be given a place)
     *   currency_unverified  PayMongo receipts without provider-verified currency and mode (recorded before provider
     *                        identities existed); counted in cash received, but their currency is not verified
     *   held                 receipts held by an unresolved payment exception or review flag (gross)
     *   retained_unresolved  held receipts minus their completed and reserved refunds: money still with the camp
     *                        while a decision about it is open (not income, not refunded)
     *   completed_refunds    = refunded()
     *   reserved_refunds     ledger refunds started but not confirmed (not refunded yet)
     *
     * @return array<string, float|int>
     */
    public static function position(): array
    {
        $receipts = fn () => Payment::whereIn('status', Payment::RECEIPT_STATUSES);
        $unverified = $receipts()->where('paymongo_payment_id', 'like', 'pay_%')
            ->whereNotExists(fn ($q) => $q->from('provider_payment_identities as i')->whereColumn('i.payment_id', 'payments.id')
                ->where('i.verification', 'provider_verified')->whereNotNull('i.currency')->whereNotNull('i.livemode'));

        $heldIds = DB::table('payment_exception_receipts as r')->join('payment_exceptions as e', 'e.id', '=', 'r.payment_exception_id')
            ->whereIn('e.status', PaymentException::UNRESOLVED_STATUSES)->pluck('r.payment_id')
            ->merge(Payment::awaitingReview()->pluck('id'))->unique()->values()->all();
        $held = $receipts()->whereIn('id', $heldIds)->get();
        $heldGross = (int) $held->sum(fn (Payment $p) => $p->amountCentavos());
        $heldRefunds = (int) RefundAttempt::whereIn('payment_id', $held->pluck('id'))
            ->whereIn('status', array_merge(RefundAttempt::COMPLETED, RefundAttempt::RESERVED))->sum('amount_centavos')
            + (int) $held->sum(fn (Payment $p) => $p->legacyRefundedCentavos());

        return [
            'cash_received' => self::paid(),
            'applied' => (float) $receipts()->whereNotNull('booking_id')->sum('amount'),
            'unapplied' => (float) $receipts()->whereNull('booking_id')->sum('amount'),
            'unapplied_count' => $receipts()->whereNull('booking_id')->count(),
            'currency_unverified' => (float) (clone $unverified)->sum('amount'),
            'currency_unverified_count' => $unverified->count(),
            'held' => round($heldGross / 100, 2),
            'held_count' => $held->count(),
            'retained_unresolved' => round(max(0, $heldGross - $heldRefunds) / 100, 2),
            'completed_refunds' => self::refunded(),
            'reserved_refunds' => round((int) RefundAttempt::whereIn('status', RefundAttempt::RESERVED)->sum('amount_centavos') / 100, 2),
        ];
    }

    /** Refunded amount per payment (old model + confirmed ledger), for per-payment lists. @return array<int, float> */
    public static function refundedByPayment(array $paymentIds): array
    {
        $ledger = RefundAttempt::whereIn('payment_id', $paymentIds)->whereIn('status', RefundAttempt::COMPLETED)
            ->selectRaw('payment_id, SUM(amount_centavos) AS c')->groupBy('payment_id')->pluck('c', 'payment_id');

        return Payment::whereIn('id', $paymentIds)->get()->mapWithKeys(fn (Payment $p) => [
            $p->id => round(($p->legacyRefundedCentavos() + (int) ($ledger[$p->id] ?? 0)) / 100, 2),
        ])->all();
    }
}
