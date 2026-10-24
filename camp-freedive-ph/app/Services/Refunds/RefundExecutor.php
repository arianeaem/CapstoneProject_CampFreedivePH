<?php

namespace App\Services\Refunds;

use App\Models\BookingRefund;
use App\Models\Payment;
use App\Models\RefundAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PayMongoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pays out an APPROVED refund case. The only code that sends refund money.
 *
 * 1. Allocate (one transaction, case and the booking's payment rows locked): the approved amount not yet paid,
 *    submitted or reserved is split across refundable payments (RefundLedger::allocate) into "prepared" attempts,
 *    each with its own operation id. PayMongo payments ("pay_" id) are refunded through PayMongo; anything else
 *    becomes "offline_awaiting_payout" for staff to pay back and document.
 * 2. Submit each prepared PayMongo attempt: an atomic claim prepared -> submitting first (only one process wins),
 *    then exactly one POST outside any transaction, then the outcome is written only if the row is still ours.
 *    A process that dies after the claim leaves "submitting": that is an unknown outcome and is never re-sent.
 * 3. The case is "completed" only when everything approved is confirmed paid back and no balance question is open.
 */
class RefundExecutor
{
    public function __construct(
        protected RefundLedger $ledger,
        protected PayMongoService $paymongo,
    ) {
    }

    /** @return array{prepared: int, submitted: int, outcomes: array<string, int>} */
    public function execute(BookingRefund $case, User $operator): array
    {
        $prepared = DB::transaction(function () use ($case, $operator) {
            $locked = BookingRefund::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === BookingRefund::COMPLETED) {
                return 0; // already paid out (a second click): nothing to send
            }
            if ($locked->status !== BookingRefund::APPROVED || (int) $locked->approved_centavos <= 0) {
                throw new RuntimeException('Only an approved refund with an amount can be paid out.');
            }
            Payment::where('booking_id', $locked->booking_id)->lockForUpdate()->get(); // one allocation per booking at a time

            $taken = (int) $locked->attempts()->whereIn('status', array_merge(RefundAttempt::COMPLETED, RefundAttempt::RESERVED))->sum('amount_centavos');
            $missing = (int) $locked->approved_centavos - $taken;
            if ($missing <= 0) {
                return 0;
            }

            $parts = $this->ledger->allocate($locked->booking, $missing);
            if ($parts === null) {
                throw new RuntimeException('Not enough refundable money is left on this booking for the approved amount.');
            }

            foreach ($parts as [$payment, $centavos]) {
                $online = is_string($payment->paymongo_payment_id) && str_starts_with($payment->paymongo_payment_id, 'pay_');
                RefundAttempt::create([
                    'booking_refund_id' => $locked->id,
                    'payment_id' => $payment->id,
                    'operation_id' => (string) Str::uuid(),
                    'method' => $online ? 'paymongo' : 'offline',
                    'amount_centavos' => $centavos,
                    'currency' => 'PHP',
                    'status' => $online ? RefundAttempt::PREPARED : RefundAttempt::OFFLINE_AWAITING,
                    'created_by' => $operator->id,
                ]);
            }

            return count($parts);
        });

        $outcomes = [];
        foreach ($case->attempts()->where('status', RefundAttempt::PREPARED)->where('method', 'paymongo')->orderBy('id')->get() as $attempt) {
            $outcome = $this->submit($attempt, $operator);
            $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
        }

        $this->refreshCompletion($case);

        return ['prepared' => $prepared, 'submitted' => array_sum($outcomes), 'outcomes' => $outcomes];
    }

    /** One POST for one prepared attempt; returns the recorded outcome ("skipped" when another process holds it). */
    public function submit(RefundAttempt $attempt, User $operator): string
    {
        $claimed = RefundAttempt::whereKey($attempt->id)->where('status', RefundAttempt::PREPARED)
            ->update(['status' => RefundAttempt::SUBMITTING, 'submitted_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return 'skipped';
        }

        $attempt->refresh();
        $result = $this->paymongo->submitRefund(
            (string) $attempt->payment->paymongo_payment_id,
            $attempt->amount_centavos,
            "Camp FreedivePH refund op:{$attempt->operation_id}",
        );

        $status = match ($result['outcome']) {
            'succeeded' => RefundAttempt::SUCCEEDED,
            'provider_pending' => RefundAttempt::PROVIDER_PENDING,
            'failed' => RefundAttempt::FAILED,
            default => RefundAttempt::UNKNOWN, // unknown, not_configured: never assume anything happened
        };
        RefundAttempt::whereKey($attempt->id)->where('status', RefundAttempt::SUBMITTING)->update([
            'status' => $status,
            'provider_refund_id' => $result['provider_refund_id'] ?? null,
            'provider_status' => $result['provider_status'] ?? null,
            'error_code' => $result['error_code'] ?? ($result['outcome'] === 'not_configured' ? 'provider_not_configured' : null),
            // Only PayMongo's own verified answer settles an outcome here; "unknown" stays reserved
            'resolution_source' => in_array($status, [RefundAttempt::SUCCEEDED, RefundAttempt::FAILED], true) ? RefundAttempt::SOURCE_PROVIDER_RESPONSE : null,
            'resolved_at' => in_array($status, [RefundAttempt::SUCCEEDED, RefundAttempt::FAILED], true) ? now() : null,
            'updated_at' => now(),
        ]);

        $case = $attempt->bookingRefund;
        AuditLogger::log('REFUND_SUBMITTED', sprintf('Refund of ₱%s for booking %s (payment #%d, operation %s) sent to PayMongo: %s%s.',
            number_format($attempt->amount_centavos / 100, 2), $case?->booking?->booking_number, $attempt->payment_id, $attempt->operation_id,
            $status, isset($result['error_code']) ? " ({$result['error_code']})" : ''), $operator, $operator->name);

        return $status;
    }

    /** "completed" only when every approved centavo is confirmed paid back and no balance decision is pending. */
    public function refreshCompletion(BookingRefund $case): void
    {
        $case->refresh();
        $paid = (int) $case->attempts()->whereIn('status', RefundAttempt::COMPLETED)->sum('amount_centavos');
        if ($case->status === BookingRefund::APPROVED && $paid >= (int) $case->approved_centavos && $case->balance_review !== 'unresolved') {
            BookingRefund::whereKey($case->id)->where('status', BookingRefund::APPROVED)->update(['status' => BookingRefund::COMPLETED, 'updated_at' => now()]);
        }
    }
}
