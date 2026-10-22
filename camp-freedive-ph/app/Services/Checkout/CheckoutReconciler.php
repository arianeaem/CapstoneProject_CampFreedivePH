<?php

namespace App\Services\Checkout;

use App\Models\CheckoutIntent;
use App\Services\AuditLogger;
use App\Services\PayMongoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recovers checkout payments when both the customer's return and PayMongo's webhook were missed, and closes
 * abandoned unpaid checkouts. Uses only the existing processor/verifier/finalizer (same locks, same idempotency);
 * it never creates a second way to confirm a booking.
 *
 * Groups (config('checkout.reconcile')):
 *  - paid:    payment verified and saved, booking not created yet -> resumed from the saved evidence
 *  - pending: has a PayMongo session, older than min_age -> fresh verification, then finalize. A PayMongo session
 *             never expires on its own, so an unpaid one stays "pending" here until PayMongo confirms it is closed;
 *             after unclosed_days it is flagged for staff (resolution_status = needs_staff_check).
 *  - review:  payment_review with a transient reason, within review_retry_hours -> fresh verification
 *  - expired: PayMongo confirmed the session closed and unpaid; re-checked for post_close_days in case a payment
 *             that was already in progress completes after the close (not verified with PayMongo either way)
 *
 * Closing an abandoned checkout (pending, unpaid for expire_after_hours, hold over):
 *   fresh verification shows no payment at all -> ask PayMongo to expire the session -> re-read the session ->
 *   only "status expired with no paid or pending payment" marks the checkout expired (conditional update that loses
 *   to any payment). A payment found at any step goes through the normal processor/finalizer (capacity re-checked;
 *   paid_unallocated when full). Anything uncertain leaves the checkout pending for the next attempt.
 *   The 15-minute capacity hold (hold_expires_at) is never changed here.
 *
 * Fairness: each group is scanned from a per-group cursor (shared cache), and due checkouts are taken round-robin
 * across groups. Pacing per checkout decays with its age. Losing the cache only means earlier retries or rescans;
 * payment evidence lives in the database. No notifications, refunds or personal data in logs.
 */
class CheckoutReconciler
{
    public const RUN_LOCK = 'checkout:reconcile:run';
    public const NEEDS_STAFF_CHECK = 'needs_staff_check';
    private const PACING_KEY = 'checkout:reconcile:intent:';
    private const CURSOR_KEY = 'checkout:reconcile:cursor:';
    private const CACHE_DAYS = 100;
    private const PAID_MIN_AGE_MINUTES = 2; // do not race the request that is finishing it right now

    public function __construct(
        protected CheckoutPaymentProcessor $processor,
        protected PayMongoService $paymongo,
    ) {
    }

    /**
     * Checkouts that are due for an attempt, by group: each group continues from its cursor, then due checkouts are
     * taken round-robin across groups up to $limit. Read-only unless $advanceCursors (no PayMongo call, no locks).
     *
     * @return array<string, list<CheckoutIntent>>
     */
    public function candidates(int $limit, bool $advanceCursors = false): array
    {
        $scans = [];
        foreach ($this->groupQueries() as $group => $query) {
            $scans[$group] = $this->scan($group, $query, $limit);
        }

        $selected = array_fill_keys(array_keys($scans), []);
        $taken = array_fill_keys(array_keys($scans), 0);
        for ($total = 0, $progress = true; $total < $limit && $progress;) {
            $progress = false;
            foreach ($scans as $group => $scan) {
                if ($total < $limit && $taken[$group] < count($scan['due'])) {
                    $selected[$group][] = $scan['due'][$taken[$group]++];
                    $total++;
                    $progress = true;
                }
            }
        }

        if ($advanceCursors) {
            foreach ($scans as $group => $scan) {
                // Resume at the first due checkout that did not fit; otherwise after the last row read, or from the start
                $next = $taken[$group] < count($scan['due']) ? $scan['due'][$taken[$group]]->id - 1 : ($scan['complete'] ? 0 : $scan['last_id']);
                Cache::put(self::CURSOR_KEY . $group, $next, now()->addDays(self::CACHE_DAYS));
            }
        }

        return $selected;
    }

    /**
     * Flag long-unconfirmed checkouts for staff, then process due checkouts until the limit or time budget runs out.
     *
     * @return array<string, int>
     */
    public function run(int $limit, int $maxSeconds): array
    {
        $started = microtime(true);
        $summary = array_fill_keys(['candidates', 'processed', 'finalized', 'paid_unallocated', 'payment_review', 'still_pending', 'expired', 'retryable', 'skipped', 'flagged'], 0);
        $summary['flagged'] = $this->flagExhausted($limit);

        foreach ($this->candidates($limit, true) as $group => $intents) {
            foreach ($intents as $intent) {
                $summary['candidates']++;
                if (microtime(true) - $started > $maxSeconds) {
                    $summary['skipped']++; // time budget used up; picked up by the next run
                    continue;
                }

                $fresh = $intent->fresh();
                if ($fresh === null || $fresh->status !== $intent->status) {
                    $summary['skipped']++; // changed since selection (return page or webhook got there first)
                    continue;
                }

                $summary[$this->attempt($fresh, $group)]++;
                $summary['processed']++;
            }
        }

        return $summary + ['duration_ms' => (int) round((microtime(true) - $started) * 1000)];
    }

    /** @return array<string, Builder> */
    private function groupQueries(): array
    {
        $cfg = config('checkout.reconcile');

        return [
            'paid' => CheckoutIntent::where('status', CheckoutIntent::STATUS_PAID)
                ->where('updated_at', '<=', now()->subMinutes(self::PAID_MIN_AGE_MINUTES)),
            'pending' => CheckoutIntent::where('status', CheckoutIntent::STATUS_PENDING)
                ->whereNotNull('paymongo_checkout_session_id')
                ->whereNull('resolution_status')
                ->where('created_at', '<=', now()->subMinutes($cfg['min_age_minutes'])),
            'review' => CheckoutIntent::where('status', CheckoutIntent::STATUS_PAYMENT_REVIEW)
                ->whereIn('failure_reason', $cfg['transient_review_reasons'])
                ->whereNotNull('paymongo_checkout_session_id')
                ->where('created_at', '>=', now()->subHours($cfg['review_retry_hours'])),
            'expired' => CheckoutIntent::where('status', CheckoutIntent::STATUS_EXPIRED)
                ->whereNotNull('paymongo_checkout_session_id')
                ->where('expired_at', '>=', now()->subDays($cfg['post_close_days'])),
        ];
    }

    /** @return array{due: list<CheckoutIntent>, last_id: int, complete: bool} */
    private function scan(string $group, Builder $query, int $limit): array
    {
        $scanLimit = (int) config('checkout.reconcile.scan_limit');
        $cursor = (int) Cache::get(self::CURSOR_KEY . $group, 0);
        $rows = (clone $query)->where('id', '>', $cursor)->orderBy('id')->limit($scanLimit)->get();
        if ($rows->isEmpty() && $cursor > 0) {
            $rows = (clone $query)->orderBy('id')->limit($scanLimit)->get(); // reached the end: wrap around
        }

        $due = [];
        $lastId = $cursor;
        $stoppedEarly = false;
        foreach ($rows as $intent) {
            $lastId = $intent->id;
            if ($this->isDue($intent)) {
                $due[] = $intent;
                if (count($due) >= $limit) {
                    $stoppedEarly = true;
                    break;
                }
            }
        }

        return ['due' => $due, 'last_id' => $lastId, 'complete' => ! $stoppedEarly && $rows->count() < $scanLimit];
    }

    /** One bounded attempt for one checkout. Returns the summary bucket. */
    private function attempt(CheckoutIntent $intent, string $group): string
    {
        $t0 = microtime(true);
        try {
            $result = $group === 'paid'
                ? $this->processor->resumeFromEvidence($intent, 'reconcile')
                : $this->processor->process($intent, 'reconcile');
            $bucket = $this->bucketFor($result);
            $reason = $result->reason;

            if ($bucket === 'still_pending' && $group === 'pending' && $result->reason === 'no_successful_payment' && $this->abandoned($intent)) {
                [$bucket, $reason] = $this->closeAndExpire($intent);
            }
        } catch (Throwable $e) {
            $bucket = 'retryable';
            $reason = 'exception:' . class_basename($e);
        }

        $this->pace($intent, $bucket === 'expired' ? 'expired' : $group, $bucket);
        Log::info('Checkout reconciliation attempt.', [
            'checkout_intent_id' => $intent->id, 'group' => $group, 'outcome' => $bucket, 'reason' => $reason,
            'ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);

        return $bucket;
    }

    /**
     * Close an abandoned, verified-unpaid checkout at PayMongo. The expire call is only a request; the fresh re-read
     * that follows decides. @return array{0: string, 1: string} bucket and reason
     */
    private function closeAndExpire(CheckoutIntent $intent): array
    {
        $request = $this->paymongo->expireCheckoutSession($intent->paymongo_checkout_session_id)['outcome'];
        $state = $this->providerState($intent);

        return match ($state) {
            'closed_unpaid' => $this->markExpired($intent) ? ['expired', 'provider_closed'] : ['skipped', 'changed_concurrently'],
            // A payment appeared between the first read and the close: the normal path decides (capacity re-checked)
            'paid' => [$this->bucketFor($result = $this->processor->process($intent->fresh(), 'reconcile')), 'paid_during_close:' . $result->reason],
            'payment_in_progress' => ['still_pending', 'payment_in_progress'],
            'still_active' => ['still_pending', 'provider_not_closed:' . $request],
            default => ['retryable', 'provider_state_unknown:' . $request],
        };
    }

    /** Session state from a fresh PayMongo read: closed_unpaid, paid, payment_in_progress, still_active or unknown. */
    private function providerState(CheckoutIntent $intent): string
    {
        $fetched = $this->paymongo->fetchCheckoutSessionForVerification($intent->paymongo_checkout_session_id);
        if ($fetched['outcome'] !== 'ok') {
            return 'unknown';
        }

        $session = $fetched['session'];
        $attributes = $session['attributes'];
        $metadata = is_array($attributes['metadata'] ?? null) ? $attributes['metadata'] : [];
        if ($session['id'] !== $intent->paymongo_checkout_session_id
            || (string) ($metadata['checkout_intent_id'] ?? '') !== (string) $intent->id
            || (string) ($metadata['checkout_reference'] ?? '') !== (string) $intent->reference
            || ! is_array($attributes['payments'] ?? null)) {
            return 'unknown';
        }

        foreach ($attributes['payments'] as $payment) {
            $status = $payment['attributes']['status'] ?? null;
            if ($status === 'paid') {
                return 'paid';
            }
            if ($status !== 'failed') {
                return 'payment_in_progress'; // pending, or anything unexpected: never treat as unpaid
            }
        }

        return match ($attributes['status'] ?? null) {
            'expired' => 'closed_unpaid',
            'active' => 'still_active',
            default => 'unknown',
        };
    }

    private function bucketFor(FinalizationResult $result): string
    {
        return match ($result->outcome) {
            FinalizationResult::FINALIZED, FinalizationResult::ALREADY_FINALIZED => 'finalized',
            FinalizationResult::PAID_UNALLOCATED => 'paid_unallocated',
            FinalizationResult::PAYMENT_REVIEW => 'payment_review',
            FinalizationResult::NOT_PAID => 'still_pending',
            default => 'retryable',
        };
    }

    /** Hold over and old enough: an unpaid checkout may be closed (it can still be paid until PayMongo closes it). */
    private function abandoned(CheckoutIntent $intent): bool
    {
        return ($intent->hold_expires_at === null || $intent->hold_expires_at->isPast())
            && $intent->created_at->lte(now()->subHours(config('checkout.reconcile.expire_after_hours')));
    }

    /** Only a pending checkout with no payment recorded can become expired (atomic; loses to a concurrent payment). */
    private function markExpired(CheckoutIntent $intent): bool
    {
        $updated = DB::table('checkout_intents')
            ->where('id', $intent->id)
            ->where('status', CheckoutIntent::STATUS_PENDING)
            ->whereNull('paymongo_payment_id')
            ->update(['status' => CheckoutIntent::STATUS_EXPIRED, 'expired_at' => now(), 'updated_at' => now()]);

        if ($updated === 1) {
            AuditLogger::log('CHECKOUT_SESSION_CLOSED', "Checkout {$intent->reference}: PayMongo confirmed the session closed with no payment; checkout marked expired. A later payment is still recognised.", null, 'Checkout reconciliation');
        }

        return $updated === 1;
    }

    /** Pending checkouts whose session was never confirmed closed within unclosed_days: flag for staff, stop polling. */
    private function flagExhausted(int $limit): int
    {
        $days = (int) config('checkout.reconcile.unclosed_days');
        $flagged = 0;
        $ids = CheckoutIntent::where('status', CheckoutIntent::STATUS_PENDING)
            ->whereNotNull('paymongo_checkout_session_id')
            ->whereNull('resolution_status')
            ->where('created_at', '<', now()->subDays($days))
            ->orderBy('id')->limit($limit)->pluck('reference', 'id');

        foreach ($ids as $id => $reference) {
            $updated = DB::table('checkout_intents')->where('id', $id)
                ->where('status', CheckoutIntent::STATUS_PENDING)->whereNull('resolution_status')
                ->update([
                    'resolution_status' => self::NEEDS_STAFF_CHECK,
                    'resolution_note' => "Automatic payment checks ended after {$days} days without PayMongo confirming this checkout's session closed. Check the session in PayMongo; a payment can still arrive by webhook.",
                    'updated_at' => now(),
                ]);
            if ($updated === 1) {
                $flagged++;
                AuditLogger::log('CHECKOUT_RECONCILE_EXHAUSTED', "Checkout {$reference}: automatic payment checks ended after {$days} days; flagged for staff review.", null, 'Checkout reconciliation');
            }
        }

        return $flagged;
    }

    private function isDue(CheckoutIntent $intent): bool
    {
        $pacing = Cache::get(self::PACING_KEY . $intent->id);

        return ! is_array($pacing) || ($pacing['next_at'] ?? 0) <= now()->getTimestamp();
    }

    /** Durable outcomes clear the pacing; everything else waits an interval that grows with the checkout's age. */
    private function pace(CheckoutIntent $intent, string $group, string $bucket): void
    {
        $key = self::PACING_KEY . $intent->id;
        if (in_array($bucket, ['finalized', 'paid_unallocated'], true)) {
            Cache::forget($key);

            return;
        }

        $cfg = config('checkout.reconcile');
        $closed = $group === 'expired';
        $anchor = $closed ? ($intent->fresh()->expired_at ?? now()) : $intent->created_at;
        $ageHours = max(0, now()->getTimestamp() - $anchor->getTimestamp()) / 3600;
        $minutes = 5;
        foreach ($closed ? $cfg['post_close_pacing'] : $cfg['pacing'] as $untilHours => $interval) {
            if ($ageHours < $untilHours) {
                $minutes = $interval;
                break;
            }
        }

        $attempts = (int) (Cache::get($key)['attempts'] ?? 0) + 1;
        Cache::put($key, ['attempts' => $attempts, 'next_at' => now()->addMinutes($minutes)->getTimestamp()], now()->addDays(self::CACHE_DAYS));
    }
}
