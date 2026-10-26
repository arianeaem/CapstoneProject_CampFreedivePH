<?php

namespace App\Services\Payment;

use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use Illuminate\Support\Facades\DB;

/**
 * DRY RUN ONLY (Batch 3-II B1): what a later, separately approved backfill would have to do for money recorded
 * before provider identities and payment exceptions existed. It reads and reports; it never writes. There is no
 * execution code in B1, and nothing runs this automatically (not scheduled, not called by migrations, deploys,
 * application start-up or tests other than its own).
 *
 * Read-only by construction: everything runs in a transaction that is always rolled back, and the connection is put in
 * read-only mode first (PostgreSQL: SET TRANSACTION READ ONLY; SQLite: PRAGMA query_only). No provider is contacted.
 *
 * Items (sorted, so the same data always gives the same plan and hash):
 *   duplicate_provider_id      several payment rows share one provider payment id: staff review; never merged,
 *                              deleted or given to an arbitrary winner
 *   claim_identity_unverified  one historical receipt with this id and no identity: could be claimed, with currency and
 *                              livemode UNKNOWN (never assumed PHP or live)
 *   create_unapplied_receipt   a paid_unallocated checkout with no receipt: its verified evidence is on the intent
 *   open_exception             a flagged receipt or a paid_unallocated checkout with no payment exception
 *   review_intent_payment      a checkout under payment review that recorded a verified payment id: staff only
 *   review_payment_evidence    a provider payment seen during checkout verification with a different amount or
 *                              currency (review history only: intent.paymongo_payment_id stays empty, no receipt
 *                              exists). One item per provider payment id, with every checkout that saw it, the amount
 *                              and currency PayMongo reported (null when not recorded or inconsistent) and how often.
 *                              Evidence for staff, not a receipt and not cash; live/test mode is not recorded there
 *   review_history_unreadable  a review history (or one of its events) that cannot be read: staff check by hand
 */
class ReceiptBackfillPlanner
{
    /** Review reasons raised only after PayMongo showed a paid payment for the session (id and mode already checked). */
    private const EVIDENCE_REASONS = ['amount_mismatch', 'currency_mismatch'];
    /** @return array{items: list<array<string, mixed>>, counts: array<string, int>, hash: string} */
    public function plan(): array
    {
        DB::beginTransaction();
        try {
            $this->readOnly(true);

            $items = array_merge($this->providerIds(), $this->unallocatedIntents(), $this->flaggedReceipts(), $this->reviewIntents(), $this->reviewEvidence());
            usort($items, fn (array $a, array $b) => [$a['action'], $a['key']] <=> [$b['action'], $b['key']]);
        } finally {
            try {
                $this->readOnly(false);
            } finally {
                DB::rollBack();
            }
        }

        $counts = array_count_values(array_column($items, 'action'));
        ksort($counts);

        return ['items' => $items, 'counts' => $counts, 'hash' => hash('sha256', json_encode($items))];
    }

    private function providerIds(): array
    {
        $items = [];
        $rows = Payment::whereNotNull('paymongo_payment_id')->where('paymongo_payment_id', 'like', 'pay_%')
            ->whereNotExists(fn ($q) => $q->from('provider_payment_identities as i')->whereColumn('i.payment_id', 'payments.id'))
            ->orderBy('id')->get(['id', 'paymongo_payment_id', 'amount', 'status', 'booking_id', 'checkout_intent_id'])
            ->groupBy('paymongo_payment_id');

        foreach ($rows as $providerId => $payments) {
            $claimed = DB::table('provider_payment_identities')->where('provider', 'paymongo')->where('provider_payment_id', $providerId)->exists();
            if ($payments->count() > 1 || $claimed) {
                $items[] = ['action' => 'duplicate_provider_id', 'key' => "paymongo:{$providerId}", 'provider_payment_id' => $providerId,
                    'payment_ids' => $payments->pluck('id')->map(fn ($id) => (int) $id)->all(), 'identity_exists' => $claimed];

                continue;
            }
            $payment = $payments->first();
            if (in_array($payment->status, Payment::RECEIPT_STATUSES, true)) {
                $items[] = ['action' => 'claim_identity_unverified', 'key' => "paymongo:{$providerId}", 'provider_payment_id' => $providerId,
                    'payment_id' => (int) $payment->id, 'amount_centavos' => $payment->amountCentavos(), 'currency' => null, 'livemode' => null,
                    'booking_id' => $payment->booking_id === null ? null : (int) $payment->booking_id,
                    'checkout_intent_id' => $payment->checkout_intent_id === null ? null : (int) $payment->checkout_intent_id];
            }
        }

        return $items;
    }

    private function unallocatedIntents(): array
    {
        $items = [];
        $intents = CheckoutIntent::where('status', CheckoutIntent::STATUS_PAID_UNALLOCATED)->orderBy('id')
            ->get(['id', 'reference', 'paymongo_payment_id', 'paid_amount', 'paid_currency', 'verification']);
        foreach ($intents as $intent) {
            $receipt = Payment::where('checkout_intent_id', $intent->id)->first()
                ?? ($intent->paymongo_payment_id ? Payment::where('paymongo_payment_id', $intent->paymongo_payment_id)->first() : null);
            if ($receipt === null) {
                $items[] = ['action' => 'create_unapplied_receipt', 'key' => "intent:{$intent->id}", 'checkout_intent_id' => (int) $intent->id,
                    'reference' => $intent->reference, 'provider_payment_id' => $intent->paymongo_payment_id,
                    'amount_centavos' => $intent->paid_amount === null ? null : (int) round((float) $intent->paid_amount * 100),
                    'currency' => $intent->paid_currency, 'livemode' => $intent->verification['livemode'] ?? null,
                    'evidence' => 'checkout intent verification (recorded at the time; not re-checked with the provider)'];
            }
            if ($receipt === null || ! DB::table('payment_exception_receipts')->where('payment_id', $receipt->id)->exists()) {
                $items[] = ['action' => 'open_exception', 'key' => "intent:{$intent->id}", 'kind' => PaymentException::KIND_INTENT_UNALLOCATED,
                    'checkout_intent_id' => (int) $intent->id, 'payment_id' => $receipt?->id];
            }
        }

        return $items;
    }

    private function flaggedReceipts(): array
    {
        $kinds = [
            Payment::REVIEW_CAPACITY_UNAVAILABLE => PaymentException::KIND_LEGACY_UNALLOCATED,
            Payment::REVIEW_PAID_AFTER_CANCELLATION => PaymentException::KIND_PAID_AFTER_CANCELLATION,
            Payment::REVIEW_AMOUNT_MISMATCH => PaymentException::KIND_AMOUNT_MISMATCH,
            Payment::REVIEW_PAID_AGAIN => PaymentException::KIND_PAID_AGAIN,
        ];

        return Payment::awaitingReview()->whereNotExists(fn ($q) => $q->from('payment_exception_receipts as r')->whereColumn('r.payment_id', 'payments.id'))
            ->orderBy('id')->get(['id', 'review_reason', 'booking_id'])
            ->map(fn (Payment $p) => ['action' => 'open_exception', 'key' => "payment:{$p->id}", 'kind' => $kinds[$p->review_reason] ?? $p->review_reason,
                'payment_id' => (int) $p->id, 'booking_id' => $p->booking_id === null ? null : (int) $p->booking_id])
            ->all();
    }

    private function reviewIntents(): array
    {
        return CheckoutIntent::where('status', CheckoutIntent::STATUS_PAYMENT_REVIEW)->whereNotNull('paymongo_payment_id')->orderBy('id')
            ->get(['id', 'reference', 'paymongo_payment_id', 'failure_reason'])
            ->map(fn (CheckoutIntent $i) => ['action' => 'review_intent_payment', 'key' => "intent:{$i->id}", 'checkout_intent_id' => (int) $i->id,
                'reference' => $i->reference, 'provider_payment_id' => $i->paymongo_payment_id, 'reason' => $i->failure_reason])
            ->all();
    }

    private function reviewEvidence(): array
    {
        $items = [];
        $seen = [];
        $intents = CheckoutIntent::whereNotNull('verification')->orderBy('id')->get(['id', 'reference', 'status', 'verification']);
        foreach ($intents as $intent) {
            $verification = $intent->verification;
            if (! is_array($verification) || ! array_key_exists('review_history', $verification)) {
                continue;
            }
            $history = $verification['review_history'];
            if (! is_array($history) || ! array_is_list($history)) {
                $items[] = ['action' => 'review_history_unreadable', 'key' => "intent:{$intent->id}", 'checkout_intent_id' => (int) $intent->id, 'event' => null];

                continue;
            }
            foreach ($history as $i => $event) {
                $reason = is_array($event) ? ($event['reason'] ?? null) : null;
                if (! is_array($event) || ! is_string($reason)) {
                    $items[] = ['action' => 'review_history_unreadable', 'key' => "intent:{$intent->id}:{$i}", 'checkout_intent_id' => (int) $intent->id, 'event' => $i];

                    continue;
                }
                if (! in_array($reason, self::EVIDENCE_REASONS, true)) {
                    continue;
                }
                $paymentId = $event['payment_id'] ?? ($event['details']['payment_id'] ?? null);
                if (! is_string($paymentId) || ! str_starts_with($paymentId, 'pay_')) {
                    $items[] = ['action' => 'review_history_unreadable', 'key' => "intent:{$intent->id}:{$i}", 'checkout_intent_id' => (int) $intent->id, 'event' => $i, 'reason' => $reason];

                    continue;
                }
                $details = is_array($event['details'] ?? null) ? $event['details'] : [];
                $seen[$paymentId][] = [
                    'checkout_intent_id' => (int) $intent->id,
                    'reason' => $reason,
                    'amount_centavos' => is_int($details['actual_centavos'] ?? null) ? $details['actual_centavos'] : null,
                    'currency' => is_string($details['actual_currency'] ?? null) ? $details['actual_currency'] : null,
                    'occurrences' => is_int($event['occurrences'] ?? null) ? max(1, $event['occurrences']) : 1,
                ];
            }
        }

        foreach ($seen as $paymentId => $events) {
            $recorded = Payment::where('paymongo_payment_id', $paymentId)->exists()
                || DB::table('provider_payment_identities')->where('provider', 'paymongo')->where('provider_payment_id', $paymentId)->exists();
            if ($recorded) {
                continue; // it is a receipt (or a recorded conflict) already
            }
            $amounts = array_values(array_unique(array_filter(array_column($events, 'amount_centavos'), 'is_int')));
            $currencies = array_values(array_unique(array_filter(array_column($events, 'currency'), 'is_string')));
            $items[] = [
                'action' => 'review_payment_evidence',
                'key' => "paymongo:{$paymentId}",
                'provider_payment_id' => $paymentId,
                'checkout_intent_ids' => array_values(array_unique(array_column($events, 'checkout_intent_id'))),
                'reasons' => array_values(array_unique(array_column($events, 'reason'))),
                'amount_centavos' => count($amounts) === 1 ? $amounts[0] : null,
                'amounts_differ' => count($amounts) > 1,
                'currency' => count($currencies) === 1 ? $currencies[0] : null,
                'livemode' => null,
                'occurrences' => array_sum(array_column($events, 'occurrences')),
                'evidence' => 'checkout verification review history (not a receipt, not counted as cash)',
            ];
        }

        return $items;
    }

    private function readOnly(bool $on): void
    {
        match (DB::getDriverName()) {
            'pgsql' => $on ? DB::statement('SET TRANSACTION READ ONLY') : null, // undone by the rollback
            'sqlite' => DB::statement('PRAGMA query_only = ' . ($on ? 'ON' : 'OFF')),
            default => $on ? throw new \RuntimeException('The backfill planner only supports PostgreSQL and SQLite.') : null,
        };
    }
}
