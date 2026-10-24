<?php

namespace App\Services\Refunds;

use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Payment;
use App\Models\RefundAttempt;
use Illuminate\Support\Collection;

/**
 * The one place that answers "how much was paid, how much went back, how much can still go back" for a booking,
 * and what the client-confirmed policy allows for a refund case. Integer centavos throughout.
 *
 *   paid      = receipts (Payment::RECEIPT_STATUSES), before any refund
 *   refunded  = old-model refunds on the payment row (Payment::legacyRefundedCentavos) + ledger refunds that
 *               PayMongo confirmed or staff documented as paid out offline. Nothing else counts as refunded.
 *   reserved  = ledger refunds not finished yet (prepared, submitting, provider pending, unknown, offline awaiting):
 *               not refunded, but held so the same money is never submitted twice
 *   refundable per payment = paid - refunded - reserved (never negative; 0 for forfeited payments)
 */
class RefundLedger
{
    /**
     * @return array{payments: Collection, paid: int, refunded: int, reserved: int, retained: int, refundable: int, reconciliation_needed: bool}
     */
    public function position(Booking $booking): array
    {
        $payments = $booking->payments()->whereIn('status', Payment::RECEIPT_STATUSES)->orderBy('id')->get();
        $attempts = RefundAttempt::whereIn('payment_id', $payments->pluck('id'))->get()->groupBy('payment_id');

        $rows = $payments->map(function (Payment $payment) use ($attempts) {
            $own = $attempts->get($payment->id, collect());
            $paid = $payment->amountCentavos();
            $refunded = $payment->legacyRefundedCentavos() + (int) $own->whereIn('status', RefundAttempt::COMPLETED)->sum('amount_centavos');
            $reserved = (int) $own->whereIn('status', RefundAttempt::RESERVED)->sum('amount_centavos');

            return [
                'payment' => $payment,
                'paid' => $paid,
                'refunded' => $refunded,
                'reserved' => $reserved,
                'refundable' => in_array($payment->status, Payment::REFUNDABLE_STATUSES, true) ? max(0, $paid - $refunded - $reserved) : 0,
                'over_refunded' => $refunded + $reserved > $paid,
            ];
        });

        // Old model: one refund id copied onto several payments, or "refunded" without an amount, cannot be trusted
        $legacyIds = $payments->pluck('paymongo_refund_id')->filter();
        $reconcile = $rows->contains('over_refunded', true)
            || $legacyIds->count() !== $legacyIds->unique()->count()
            || $payments->contains(fn (Payment $p) => in_array($p->status, Payment::LEGACY_REFUNDED_STATUSES, true) && (float) $p->amount_refunded <= 0);

        $paid = (int) $rows->sum('paid');
        $refunded = (int) $rows->sum('refunded');

        return [
            'payments' => $rows,
            'paid' => $paid,
            'refunded' => $refunded,
            'reserved' => (int) $rows->sum('reserved'),
            'retained' => $paid - $refunded,
            'refundable' => (int) $rows->sum('refundable'),
            'reconciliation_needed' => $reconcile,
        ];
    }

    /**
     * What may be approved for this case now, under the client-confirmed rules (config/refunds.php):
     *  - camp operational: everything still refundable (receipts - earlier refunds - reserved), once staff confirmed
     *    the reason, that the activity had NOT started, and the guest chose a refund;
     *  - weather before diving: the downpayment only (minus anything already refunded or reserved), once staff
     *    confirmed the reason, that the activity had NOT started, and the guest chose a refund. Money paid beyond the
     *    downpayment is flagged as an unresolved balance for staff; it is never part of the cap;
     *  - weather after the activity started, voluntary cancellation (current terms), or anything unclassified: 0;
     *  - booking made under the earlier published terms: 0 until staff decide; "honor previous terms" allows up to
     *    the amount quoted then (never more than what is still refundable).
     *
     * @return array{cap: int, balance_unresolved: bool, blocked: ?string}
     */
    public function entitlement(BookingRefund $case): array
    {
        $position = $this->position($case->booking);
        $refundable = $position['refundable'];
        $confirmed = $case->reason_confirmed_by !== null;

        $needsRefundChoice = in_array($case->reason_category, [BookingRefund::REASON_WEATHER_PRE_DIVE, BookingRefund::REASON_CAMP_OPERATIONAL], true);
        if ($needsRefundChoice && $case->guest_choice !== 'refund') {
            return $this->blocked($case->guest_choice === 'reschedule' ? 'The guest chose to move to another date.' : 'The guest has not chosen a refund yet.');
        }

        // A refund quoted under the earlier published terms: nothing is approvable until the earlier-terms decision is
        // recorded (RefundGuard). Honouring allows up to that quote; applying the current terms falls through to them.
        if ($case->isHistorical() && (int) $case->quoted_centavos > 0) {
            if ($case->historical_decision === null) {
                return $this->blocked('Made under the earlier published terms with a quoted refund: record the earlier-terms decision first.');
            }
            if ($case->historical_decision === 'honor_previous_terms') {
                // Weather before diving: money paid beyond the honoured quote and the downpayment stays unresolved
                $covered = max((int) $case->quoted_centavos, (int) round((float) $case->booking->downpayment_amount * 100));

                return ['cap' => min((int) $case->quoted_centavos, $refundable),
                    'balance_unresolved' => $case->reason_category === BookingRefund::REASON_WEATHER_PRE_DIVE && $position['paid'] > $covered,
                    'blocked' => null];
            }
        }

        return match ($case->reason_category) {
            BookingRefund::REASON_CAMP_OPERATIONAL => match (true) {
                ! $confirmed => $this->blocked('Staff have not confirmed the cancellation reason.'),
                // Unknown progress is never treated as "before the activity"; after it started the policy is not confirmed
                $case->activity_started !== 'no' => $this->blocked('Staff have not confirmed that the activity had not started.'),
                default => ['cap' => $refundable, 'balance_unresolved' => false, 'blocked' => null],
            },

            BookingRefund::REASON_WEATHER_PRE_DIVE => match (true) {
                ! $confirmed => $this->blocked('Staff have not confirmed the weather cancellation.'),
                $case->activity_started !== 'no' => $this->blocked('Staff have not confirmed that the activity had not started.'),
                default => $this->downpaymentOnly($case->booking, $position),
            },

            BookingRefund::REASON_WEATHER_IN_PROGRESS => $this->blocked('No refund applies once the activity has started (client policy).'),

            BookingRefund::REASON_GUEST_VOLUNTARY => $case->isHistorical()
                ? match ($case->historical_decision) {
                    'honor_previous_terms' => ['cap' => min((int) $case->quoted_centavos, $refundable), 'balance_unresolved' => false, 'blocked' => null],
                    'apply_current_terms' => $this->blocked('Staff applied the current terms: no refund for voluntary cancellation.'),
                    default => $this->blocked('Made under the earlier published terms: staff must decide first.'),
                }
                : $this->blocked('Voluntary cancellations are not refunded under the current terms.'),

            default => $this->blocked('The cancellation reason has not been classified.'),
        };
    }

    /**
     * Split $amount across the booking's refundable payments, oldest first, never more than each payment's
     * refundable amount. Returns [payment, centavos] pairs, or null when there is not enough left.
     *
     * @return list<array{0: Payment, 1: int}>|null
     */
    public function allocate(Booking $booking, int $amount): ?array
    {
        $parts = [];
        foreach ($this->position($booking)['payments'] as $row) {
            if ($amount <= 0) {
                break;
            }
            $take = min($amount, $row['refundable']);
            if ($take > 0) {
                $parts[] = [$row['payment'], $take];
                $amount -= $take;
            }
        }

        return $amount > 0 ? null : $parts;
    }

    private function downpaymentOnly(Booking $booking, array $position): array
    {
        $downpayment = (int) round((float) $booking->downpayment_amount * 100);
        $cap = max(0, min($downpayment - $position['refunded'] - $position['reserved'], $position['refundable']));

        return ['cap' => $cap, 'balance_unresolved' => $position['paid'] > $downpayment, 'blocked' => null];
    }

    private function blocked(string $why): array
    {
        return ['cap' => 0, 'balance_unresolved' => false, 'blocked' => $why];
    }
}
