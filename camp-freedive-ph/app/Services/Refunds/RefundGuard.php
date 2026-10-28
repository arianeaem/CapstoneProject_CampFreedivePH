<?php

namespace App\Services\Refunds;

use App\Models\BookingRefund;
use App\Models\CancellationRequest;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\AuditLogger;
use RuntimeException;

/**
 * The one check every "no refund" decision goes through: forfeiting, rejecting, closing a refund case and recording an
 * earlier-terms decision. Enforced on the server, so a hidden button, a stale form or a crafted POST cannot skip it.
 *
 * Earlier-terms promise: the booking has no stamped policy version (made under the earlier published terms) and the
 * request or case carries a positive refund quote. Such a promise is never forfeited outside its refund case; it is
 * denied only by an explicit owner decision with a rationale, recorded with the quote (never rewritten).
 *
 * Unresolved balance (weather before diving, paid beyond the downpayment): the case stays open; nothing closes it,
 * forfeits or rejects it until the client policy for that balance is confirmed.
 */
class RefundGuard
{
    public const MIN_RATIONALE = 10;

    /** The refund promised under the earlier published terms, in centavos; 0 when there is no such promise. */
    public function historicalQuote(CancellationRequest|RefundRequest|BookingRefund $subject): int
    {
        return match (true) {
            $subject instanceof BookingRefund => $subject->isHistorical() ? max(0, (int) $subject->quoted_centavos) : 0,

            $subject instanceof CancellationRequest => $subject->policy_version === null && $subject->booking?->refund_policy_version === null
                ? max(0, (int) round((float) $subject->calculated_refund_amount * 100))
                : 0,

            // Old refund-queue rows: the percentage they showed staff and the guest (camp cancellations said 100%);
            // a row without one is treated as a full promise rather than guessed down
            default => $subject->booking?->refund_policy_version === null
                ? (int) round((float) $subject->payment?->amount * 100 * $this->legacyPercentage($subject) / 100)
                : 0,
        };
    }

    /** Forfeiting (keeping the money) is never allowed for an earlier-terms promise or while a refund case is open. */
    public function assertMayForfeit(CancellationRequest|RefundRequest $subject): void
    {
        $this->assertNoOpenCase($subject);
        if (($quote = $this->historicalQuote($subject)) > 0) {
            throw new RuntimeException('This booking was made under the earlier published terms with a quoted refund of '
                . $this->peso($quote) . '. It cannot be forfeited here: approve it into a refund case, where the earlier-terms decision is recorded.');
        }
    }

    /** Rejecting an earlier-terms promise needs the owner and a rationale (recorded with the quote). */
    public function assertMayReject(CancellationRequest|RefundRequest $subject, User $actor, ?string $rationale): void
    {
        $this->assertNoOpenCase($subject);
        if (($quote = $this->historicalQuote($subject)) <= 0) {
            return;
        }
        $this->assertOwnerWithRationale($actor, $rationale, 'reject a request made under the earlier published terms (quoted refund ' . $this->peso($quote) . ')');
        AuditLogger::log('HISTORICAL_PROMISE_DENIED', sprintf('%s #%d (booking %s) rejected by %s. Earlier-terms quote %s kept on record. Rationale: %s',
            class_basename($subject), $subject->id, $subject->booking?->booking_number, $actor->name, $this->peso($quote), trim((string) $rationale)), $actor, $actor->name);
    }

    /** Closing a case without (further) refund. */
    public function assertMayClose(BookingRefund $case, User $actor, string $note): void
    {
        if (mb_strlen(trim($note)) < self::MIN_RATIONALE) {
            throw new RuntimeException('Explain why the case is closed (at least ' . self::MIN_RATIONALE . ' characters).');
        }
        if ($case->balance_review === 'unresolved') {
            throw new RuntimeException('The amount paid beyond the downpayment is still unresolved (client policy not confirmed). This case stays open: it cannot be closed, forfeited or rejected until that is decided.');
        }
        if (($quote = $this->historicalQuote($case)) > 0
            && ($case->historical_decision !== 'apply_current_terms' || ! $actor->isOwner())) {
            throw new RuntimeException('This booking was made under the earlier published terms with a quoted refund of ' . $this->peso($quote)
                . '. Only the owner can close it without that refund, after recording the "apply current terms" decision with a rationale.');
        }
    }

    /**
     * Recording "the guest moves to another date" closes the case without a refund (the guest's own choice). It needs a
     * real note, and for an earlier-terms promise the owner, so it is not a quiet way around assertMayClose.
     */
    public function assertMayCloseByReschedule(BookingRefund $case, User $actor, string $note): void
    {
        if (mb_strlen(trim($note)) < self::MIN_RATIONALE) {
            throw new RuntimeException('Record how the guest chose to move dates (at least ' . self::MIN_RATIONALE . ' characters).');
        }
        if (($quote = $this->historicalQuote($case)) > 0 && ! $actor->isOwner()) {
            throw new RuntimeException('This booking was made under the earlier published terms with a quoted refund of ' . $this->peso($quote)
                . '. Only the owner can record that the guest gave it up for a date move.');
        }
    }

    /** An explicit earlier-terms decision: anyone with access may honour the promise; only the owner may apply current terms. */
    public function assertMayDecideHistorical(BookingRefund $case, string $decision, User $actor, string $rationale): void
    {
        if (! $case->isHistorical() || ($this->historicalQuote($case) <= 0 && $case->reason_category !== BookingRefund::REASON_GUEST_VOLUNTARY)) {
            throw new RuntimeException('Only bookings made under the earlier published terms with a quoted refund need this decision.');
        }
        if ($decision === 'apply_current_terms') {
            $this->assertOwnerWithRationale($actor, $rationale, 'apply the current terms instead of the earlier quoted refund');
        } elseif (mb_strlen(trim($rationale)) < self::MIN_RATIONALE) {
            throw new RuntimeException('Give a rationale of at least ' . self::MIN_RATIONALE . ' characters.');
        }
    }

    private function assertNoOpenCase(CancellationRequest|RefundRequest $subject): void
    {
        $open = BookingRefund::where('booking_id', $subject->booking_id)->whereIn('status', BookingRefund::OPEN_STATUSES)->value('id');
        if ($open !== null) {
            throw new RuntimeException("Refund case #{$open} is open for this booking: decide the refund there (this action would bypass it).");
        }
    }

    private function assertOwnerWithRationale(User $actor, ?string $rationale, string $what): void
    {
        if (! $actor->isOwner()) {
            throw new RuntimeException("Only the owner can {$what}. The request stays open for owner review.");
        }
        if (mb_strlen(trim((string) $rationale)) < self::MIN_RATIONALE) {
            throw new RuntimeException('Give a rationale of at least ' . self::MIN_RATIONALE . ' characters.');
        }
    }

    /**
     * B3 (P2): every refund approval and payout is done by an active owner or admin, checked on the server (the route
     * middleware is not the only line). Reads the account fresh, so a deactivated or demoted account is refused.
     */
    public function assertApprover(User $user): User
    {
        $fresh = User::find($user->id);
        if ($fresh === null || $fresh->status !== 'active' || ! in_array($fresh->role, ['owner', 'admin'], true)) {
            throw new RuntimeException('Only an active owner or admin can approve or pay out a refund; nothing was changed.');
        }

        return $fresh;
    }

    private function legacyPercentage(RefundRequest $request): float
    {
        if ($request->requested_by === 'camp_force_majeure') {
            return 100.0;
        }
        $percentage = $request->eligibility_calculated['refund_percentage'] ?? null;

        return is_numeric($percentage) ? max(0.0, min(100.0, (float) $percentage)) : 100.0;
    }

    private function peso(int $centavos): string
    {
        return '₱' . number_format($centavos / 100, 2);
    }
}
