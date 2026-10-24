<?php

namespace App\Services\Refunds;

use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\CancellationRequest;
use App\Models\RefundAttempt;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PayMongoService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Refund cases: everything except paying out (RefundExecutor). Approval is a separate staff step and moves no money.
 *
 * A case is opened when a booking is cancelled with money on it (customer cancellation approved by staff, camp
 * cancellation of a batch, or a legacy refund-queue item). One open case per booking: opening again returns it.
 *
 * Status (recomputed after each step until approved / closed):
 *   awaiting_guest_choice  weather/operational cancellation: the guest has not said "refund" or "move dates"
 *   awaiting_staff_review  anything else that needs a person: unclassified reason, unconfirmed weather/start,
 *                          earlier published terms not yet decided, nothing approved yet
 *   approved               an amount was approved (<= RefundLedger::entitlement cap); RefundExecutor pays it out
 *   closed_no_refund       guest moved dates, policy gives nothing, or staff closed it
 *   completed              paid back in full (and no balance question open)
 */
class RefundCaseService
{
    public function __construct(
        protected RefundLedger $ledger,
        protected RefundGuard $guard,
        protected PayMongoService $paymongo,
    ) {
    }

    /** Staff approved a customer's cancellation request (not forfeited). */
    public function openForCustomerCancellation(CancellationRequest $request, User $operator, string $action, ?float $requestedPesos): BookingRefund
    {
        $booking = $request->booking;
        $historical = $request->policy_version === null && $booking->refund_policy_version === null;

        return $this->open($booking, [
            'cancellation_request_id' => $request->id,
            'source' => 'customer_cancellation',
            // A storm/critical-risk flag at request time is a claim, not a confirmation: staff confirm it on the case
            'reason_category' => $request->force_majeure_flag ? BookingRefund::REASON_WEATHER_PRE_DIVE : BookingRefund::REASON_GUEST_VOLUNTARY,
            'policy_version' => $historical ? null : ($request->policy_version ?? $booking->refund_policy_version),
            'guest_choice' => 'refund', // the guest asked to cancel rather than move dates
            'guest_choice_at' => $request->created_at,
            'quoted_centavos' => $this->centavos($request->calculated_refund_amount),
            'requested_centavos' => $requestedPesos !== null ? $this->centavos($requestedPesos) : null,
            'review_note' => "Opened when {$operator->name} approved the cancellation (staff option: {$action}).",
        ], $operator);
    }

    /** A batch was cancelled by the camp. Staff-provided reason and progress; unknown values route to review. */
    public function openForCampCancellation(Booking $booking, string $category, string $activityStarted, User $operator, string $note): ?BookingRefund
    {
        if ($this->ledger->position($booking)['refundable'] <= 0) {
            return null; // nothing was paid (or it was all refunded already): nothing to decide
        }
        $classified = $category !== BookingRefund::REASON_UNKNOWN;

        return $this->open($booking, [
            'source' => 'camp_cancellation',
            'reason_category' => $category,
            'reason_confirmed_by' => $classified ? $operator->id : null,
            'reason_confirmed_at' => $classified ? now() : null,
            'activity_started' => $activityStarted,
            'policy_version' => $booking->refund_policy_version,
            'review_note' => "Camp cancellation by {$operator->name}: {$note}",
        ], $operator);
    }

    /**
     * A legacy refund-queue item (one row per payment, created by the old batch/weather cancellation). It never
     * pays anything by itself any more: it opens (or joins) the booking's case for staff to classify and approve.
     */
    public function openFromLegacyRequest(RefundRequest $request, User $operator): BookingRefund
    {
        return DB::transaction(function () use ($request, $operator) {
            $locked = RefundRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'pending') {
                throw new RuntimeException('This refund request was already handled.');
            }
            $booking = $locked->booking;
            $case = $this->open($booking, [
                'source' => 'legacy_refund_request',
                // Old requests do not say whether the camp cancelled for weather or for its own reasons
                'reason_category' => BookingRefund::REASON_UNKNOWN,
                'policy_version' => $booking->refund_policy_version,
                // Earlier-terms rows keep the percentage they showed (RefundGuard::historicalQuote), never more
                'quoted_centavos' => $booking->refund_policy_version === null ? $this->guard->historicalQuote($locked) : $this->centavos($locked->payment?->amount),
                'review_note' => "Opened from refund queue item #{$locked->id} ({$locked->requested_by}) by {$operator->name}.",
            ], $operator);
            $locked->update(['status' => 'in_review', 'booking_refund_id' => $case->id, 'reviewed_by' => $operator->id, 'reviewed_at' => now()]);

            return $case;
        });
    }

    /** Staff confirm why the booking was cancelled and whether the activity had already started. */
    public function classify(BookingRefund $case, string $category, string $activityStarted, User $operator, string $note): BookingRefund
    {
        return $this->step($case, $operator, 'REFUND_CASE_CLASSIFIED', function (BookingRefund $c) use ($category, $activityStarted, $operator, $note) {
            if ($c->source === 'customer_cancellation' && in_array($category, [BookingRefund::REASON_CAMP_OPERATIONAL, BookingRefund::REASON_WEATHER_IN_PROGRESS], true)) {
                throw new RuntimeException('A customer cancellation can only be voluntary or weather before diving.');
            }
            $c->fill([
                'reason_category' => $category,
                'activity_started' => $activityStarted,
                'reason_confirmed_by' => $category === BookingRefund::REASON_UNKNOWN ? null : $operator->id,
                'reason_confirmed_at' => $category === BookingRefund::REASON_UNKNOWN ? null : now(),
            ]);

            return "classified as {$category}, activity started: {$activityStarted}. {$note}";
        });
    }

    /** The guest's answer to "refund or move to another date?" (weather / operational cancellations). */
    public function recordGuestChoice(BookingRefund $case, string $choice, User $operator, string $note): BookingRefund
    {
        return $this->step($case, $operator, 'REFUND_GUEST_CHOICE', function (BookingRefund $c) use ($choice, $operator, $note) {
            if ($choice === 'reschedule') {
                $this->guard->assertMayCloseByReschedule($c, $operator, $note); // this choice closes the case
            }
            $c->fill(['guest_choice' => $choice, 'guest_choice_recorded_by' => $operator->id, 'guest_choice_at' => now()]);

            return "guest chose: {$choice}. {$note}";
        });
    }

    /**
     * Booking made under the earlier published terms: an explicit decision, with a rationale, before anything is approved
     * or closed (RefundGuard). "Apply current terms" (owner only) does not close the case or erase the quote: the case
     * stays open and only the owner can then close it.
     */
    public function decideHistorical(BookingRefund $case, string $decision, User $operator, string $rationale): BookingRefund
    {
        return $this->step($case, $operator, 'REFUND_HISTORICAL_DECISION', function (BookingRefund $c) use ($decision, $operator, $rationale) {
            $this->guard->assertMayDecideHistorical($c, $decision, $operator, $rationale);
            $c->fill([
                'historical_decision' => $decision,
                'historical_decided_by' => $operator->id,
                'historical_decided_at' => now(),
                'historical_rationale' => trim($rationale),
            ]);

            return "earlier-terms decision: {$decision} by {$operator->name} ({$operator->role}); quoted refund ₱"
                . number_format((int) $c->quoted_centavos / 100, 2) . " kept on record. Rationale: {$rationale}";
        });
    }

    /** Approve an amount (<= what policy allows now). No money moves here. */
    public function approve(BookingRefund $case, int $centavos, User $operator, string $note): BookingRefund
    {
        return $this->step($case, $operator, 'REFUND_APPROVED', function (BookingRefund $c) use ($centavos, $operator, $note) {
            $allowed = $this->ledger->entitlement($c);
            if ($allowed['blocked'] !== null) {
                throw new RuntimeException($allowed['blocked']);
            }
            if ($centavos <= 0 || $centavos > $allowed['cap']) {
                throw new RuntimeException('The amount must be more than ₱0 and at most ₱' . number_format($allowed['cap'] / 100, 2) . '.');
            }
            $c->fill([
                'status' => BookingRefund::APPROVED,
                'approved_centavos' => $centavos,
                'approved_by' => $operator->id,
                'approved_at' => now(),
                'balance_review' => $allowed['balance_unresolved'] ? 'unresolved' : 'none',
            ]);

            return 'approved ₱' . number_format($centavos / 100, 2) . ($allowed['balance_unresolved'] ? ' (downpayment only; paid balance still under review)' : '') . ". {$note}";
        }, allowApproved: false);
    }

    /** Close without a (further) refund, e.g. the guest moved dates or policy gives nothing. */
    public function close(BookingRefund $case, User $operator, string $note): BookingRefund
    {
        return $this->step($case, $operator, 'REFUND_CASE_CLOSED', function (BookingRefund $c) use ($note, $operator) {
            $this->guard->assertMayClose($c, $operator, $note);
            if ($c->attempts()->whereIn('status', RefundAttempt::RESERVED)->exists()) {
                throw new RuntimeException('A refund is still in progress for this case; resolve it first.');
            }
            $c->status = BookingRefund::CLOSED_NO_REFUND;

            return "closed without refund. {$note}";
        }, allowApproved: true);
    }

    /**
     * Settle an open PayMongo outcome (unknown, pending, or a stale "submitting") by looking the refund up at PayMongo
     * (GET /refunds/{id}). Staff can only supply the refund id they found; their note is stored as unverified evidence.
     * The attempt changes only when PayMongo returns that refund for the same payment, amount, currency, mode and our
     * operation id ("op:<uuid>" in its notes). Anything else (not found, timeout, mismatch) leaves it reserved, so it is
     * never re-sent. Nothing here sends a refund.
     *
     * @return string the new status, or "inconclusive: <why>"
     */
    public function verifyAttempt(RefundAttempt $attempt, ?string $providerRefundId, User $operator, ?string $note): string
    {
        $attempt->refresh();
        $before = $attempt->status;
        if ($attempt->method !== 'paymongo' || ! in_array($before, RefundAttempt::VERIFIABLE, true)) {
            throw new RuntimeException('This refund attempt is not waiting for a PayMongo outcome.');
        }
        if ($before === RefundAttempt::SUBMITTING && $attempt->submitted_at?->gt(now()->subMinutes((int) config('refunds.stale_submission_minutes')))) {
            throw new RuntimeException('This refund was sent moments ago; wait for its outcome first.');
        }
        $given = trim((string) $providerRefundId);
        if ($attempt->provider_refund_id !== null && $given !== '' && $given !== $attempt->provider_refund_id) {
            throw new RuntimeException("This attempt is already linked to PayMongo refund {$attempt->provider_refund_id}.");
        }
        $refundId = $attempt->provider_refund_id ?? $given;
        if (! preg_match('/^ref_[A-Za-z0-9_]+$/', $refundId)) {
            throw new RuntimeException('Enter the PayMongo refund id (ref_...) to look up.');
        }

        $lookup = $this->paymongo->retrieveRefund($refundId);   // read-only, outside any transaction
        $refund = $lookup['refund'] ?? null;
        $problem = match (true) {
            $lookup['outcome'] !== 'found' => 'PayMongo lookup gave no answer (' . ($lookup['error_code'] ?? 'unavailable') . ')',
            $refund['payment_id'] !== $attempt->payment?->paymongo_payment_id => 'the refund is for a different payment',
            $refund['amount'] !== $attempt->amount_centavos => 'the refund amount differs',
            $refund['currency'] !== 'PHP' => 'the refund currency is not PHP',
            $refund['livemode'] !== $this->paymongo->configuredLivemode() => 'test/live mode differs',
            ! str_contains($refund['notes'], "op:{$attempt->operation_id}") => 'the refund was not sent for this operation',
            RefundAttempt::where('provider_refund_id', $refundId)->whereKeyNot($attempt->id)->exists() => 'the refund id is already linked to another attempt',
            default => null,
        };
        $status = $problem === null ? match ($refund['status']) {
            'succeeded' => RefundAttempt::SUCCEEDED,
            'failed' => RefundAttempt::FAILED,
            'pending', 'processing' => RefundAttempt::PROVIDER_PENDING,
            default => null,
        } : null;
        if ($problem === null && $status === null) {
            $problem = 'PayMongo status "' . $refund['status'] . '" is not a known outcome';
        }
        $evidence = trim(($attempt->evidence_note ? $attempt->evidence_note . "\n" : '') . sprintf('[%s] %s looked up %s (unverified staff note: %s)',
            now()->toDateTimeString(), $operator->name, $refundId, trim((string) $note) ?: '-'));

        $result = DB::transaction(function () use ($attempt, $before, $status, $problem, $refundId, $refund, $operator, $evidence) {
            $locked = RefundAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $before) {
                return 'inconclusive: the outcome changed meanwhile (now ' . $locked->status . ')';
            }
            if ($problem !== null) {
                $locked->update(['evidence_note' => $evidence]);   // the reservation stays exactly as it was

                return "inconclusive: {$problem}";
            }
            $locked->update([
                'status' => $status,
                'provider_refund_id' => $refundId,
                'provider_status' => (string) $refund['status'],
                'resolution_source' => RefundAttempt::SOURCE_PROVIDER_VERIFICATION,
                'verified_at' => now(),
                'resolved_at' => in_array($status, [RefundAttempt::SUCCEEDED, RefundAttempt::FAILED], true) ? now() : null,
                'resolved_by' => $operator->id,
                'evidence_note' => $evidence,
            ]);

            return $status;
        });

        $this->audit($attempt->bookingRefund, $operator, str_starts_with($result, 'inconclusive') ? 'REFUND_VERIFICATION_INCONCLUSIVE' : 'REFUND_ATTEMPT_VERIFIED',
            "operation {$attempt->operation_id} checked against PayMongo refund {$refundId}: {$result}.");
        app(RefundExecutor::class)->refreshCompletion($attempt->bookingRefund);

        return $result;
    }

    /** Staff document an offline payout (cash, bank transfer). */
    public function confirmOfflinePayout(RefundAttempt $attempt, User $operator, string $evidence): void
    {
        $updated = RefundAttempt::whereKey($attempt->id)->where('status', RefundAttempt::OFFLINE_AWAITING)->update([
            'status' => RefundAttempt::OFFLINE_CONFIRMED, 'resolution_source' => RefundAttempt::SOURCE_OFFLINE_STAFF, 'resolved_at' => now(), 'resolved_by' => $operator->id,
            'evidence_note' => $evidence, 'updated_at' => now(),
        ]);
        if ($updated !== 1) {
            throw new RuntimeException('This offline refund is not waiting for payout confirmation.');
        }
        $this->audit($attempt->bookingRefund, $operator, 'REFUND_OFFLINE_CONFIRMED', 'offline payout of ₱' . number_format($attempt->amount_centavos / 100, 2) . " confirmed. Evidence: {$evidence}");
        app(RefundExecutor::class)->refreshCompletion($attempt->bookingRefund);
    }

    /** Recompute the waiting status of an open (not yet approved) case. */
    public function refreshStatus(BookingRefund $case): void
    {
        if (! in_array($case->status, [BookingRefund::AWAITING_GUEST_CHOICE, BookingRefund::AWAITING_STAFF_REVIEW], true)) {
            return;
        }
        $needsChoice = in_array($case->reason_category, [BookingRefund::REASON_WEATHER_PRE_DIVE, BookingRefund::REASON_CAMP_OPERATIONAL], true);
        $case->status = match (true) {
            $case->guest_choice === 'reschedule' => BookingRefund::CLOSED_NO_REFUND,
            $needsChoice && $case->guest_choice === null => BookingRefund::AWAITING_GUEST_CHOICE,
            default => BookingRefund::AWAITING_STAFF_REVIEW,
        };
        $case->balance_review = $this->ledger->entitlement($case)['balance_unresolved'] ? 'unresolved' : ($case->balance_review ?? 'none');
    }

    private function open(Booking $booking, array $attributes, User $operator): BookingRefund
    {
        return DB::transaction(function () use ($booking, $attributes, $operator) {
            Booking::whereKey($booking->id)->lockForUpdate()->first();
            $existing = BookingRefund::where('booking_id', $booking->id)->whereIn('status', BookingRefund::OPEN_STATUSES)->first();
            if ($existing) {
                return $existing; // one open case per booking; a second source joins it
            }
            $case = new BookingRefund(['booking_id' => $booking->id, 'status' => BookingRefund::AWAITING_STAFF_REVIEW] + $attributes);
            $this->refreshStatus($case);
            $case->save();
            $this->audit($case, $operator, 'REFUND_CASE_OPENED', "{$case->source}, reason {$case->reason_category}, status {$case->status}"
                . ($case->isHistorical() ? ', made under the earlier published terms (staff review)' : ''));

            return $case;
        });
    }

    /** Run one staff step on a locked, still-open case, then recompute and audit it. */
    private function step(BookingRefund $case, User $operator, string $action, callable $change, bool $allowApproved = false): BookingRefund
    {
        $updated = DB::transaction(function () use ($case, $operator, $action, $change, $allowApproved) {
            $locked = BookingRefund::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $allowed = $allowApproved ? BookingRefund::OPEN_STATUSES : [BookingRefund::AWAITING_GUEST_CHOICE, BookingRefund::AWAITING_STAFF_REVIEW];
            if (! in_array($locked->status, $allowed, true)) {
                throw new RuntimeException('This refund case can no longer be changed (status: ' . str_replace('_', ' ', $locked->status) . ').');
            }
            $summary = $change($locked);
            $this->refreshStatus($locked);
            $locked->save();
            $this->audit($locked, $operator, $action, $summary);

            return $locked;
        });

        return $updated;
    }

    private function audit(?BookingRefund $case, User $operator, string $action, string $summary): void
    {
        AuditLogger::log($action, "Refund case #{$case?->id} (booking {$case?->booking?->booking_number}): {$summary}", $operator, $operator->name);
    }

    private function centavos(mixed $pesos): ?int
    {
        return $pesos === null ? null : (int) round((float) $pesos * 100);
    }
}
