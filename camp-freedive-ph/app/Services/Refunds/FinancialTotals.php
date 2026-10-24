<?php

namespace App\Services\Refunds;

use App\Models\Payment;
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
