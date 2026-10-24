<?php

namespace Tests\Feature\Refunds;

use App\Models\BookingRefund;
use App\Models\RefundAttempt;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundExecutor;
use App\Services\Refunds\RefundLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-I: paying out an approved refund. One POST per payment, never retried, nothing assumed from an unclear
 * answer, allocation across installments, offline payouts documented by staff. PayMongo is faked.
 */
class RefundExecutionTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;
    use RefundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Mail::fake();
    }

    /** An approved operational-cancellation case for the booking (everything paid is refundable). */
    private function approved($booking, int $centavos): BookingRefund
    {
        $case = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL);

        return app(RefundCaseService::class)->approve($case, $centavos, $this->staff(), 'Synthetic approval');
    }

    private function execute(BookingRefund $case): array
    {
        return app(RefundExecutor::class)->execute($case, $this->staff());
    }

    // ------------------------------------------------------------------ allocation

    public function test_single_receipt_is_refunded_with_one_post_and_completes_on_success(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $payment = $this->receipt($booking, 4250, 'paid', 'pay_test_single', 'full');

        $case = $this->approved($booking, 425000);
        $this->execute($case);
        $this->execute($case); // a second click finds nothing left to send

        $this->assertCount(1, $posts);
        $this->assertSame(['amount' => 425000, 'payment_id' => 'pay_test_single'], array_intersect_key($posts[0], ['amount' => 0, 'payment_id' => 0]));
        $attempt = RefundAttempt::sole();
        $this->assertSame([RefundAttempt::SUCCEEDED, 'ref_test_1'], [$attempt->status, $attempt->provider_refund_id]);
        $this->assertStringContainsString("op:{$attempt->operation_id}", $posts[0]['notes']);
        $this->assertSame(BookingRefund::COMPLETED, $case->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status, 'The receipt keeps its status; the ledger records the refund.');
        $this->assertSame('cancelled_by_camp', $booking->fresh()->status, 'Never relabelled as a guest cancellation.');
    }

    public function test_two_installments_are_allocated_per_payment_never_more_than_each_paid(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000, 'paid', 'pay_test_dp');
        $this->receipt($booking, 2250, 'paid', 'pay_test_bal', 'balance_settlement');

        $this->execute($this->approved($booking, 425000));

        $this->assertSame([['pay_test_dp', 200000], ['pay_test_bal', 225000]], array_map(fn ($p) => [$p['payment_id'], $p['amount']], $posts));
        $this->assertSame(425000, app(RefundLedger::class)->position($booking)['refunded']);
    }

    public function test_earlier_partial_refund_is_subtracted_and_approval_cannot_exceed_what_is_left(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 4250, 'partially_refunded', 'auto', 'full', ['amount_refunded' => 1000]);
        $case = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL);

        $this->assertSame(325000, app(RefundLedger::class)->entitlement($case)['cap']);
        $this->expectExceptionMessage('at most ₱3,250.00');
        app(RefundCaseService::class)->approve($case, 425000, $this->staff(), 'too much');
    }

    public function test_a_second_case_for_the_same_booking_joins_the_open_one(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $first = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL, 'no', null);

        $second = app(RefundCaseService::class)->openForCampCancellation($booking, BookingRefund::REASON_CAMP_OPERATIONAL, 'no', $this->staff(), 'again');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, BookingRefund::count());
    }

    public function test_approval_is_claimed_once(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $case = $this->approved($booking, 200000);

        $this->expectExceptionMessage('can no longer be changed');
        app(RefundCaseService::class)->approve($case, 200000, $this->staff(), 'double click');
    }

    // ------------------------------------------------------------- provider outcomes

    public static function outcomes(): array
    {
        return [
            'timeout' => [new ConnectionException('Synthetic timeout'), RefundAttempt::UNKNOWN, 'no_response'],
            '500' => [[500, ['errors' => [['code' => 'server_error']]]], RefundAttempt::UNKNOWN, 'http_500'],
            '429' => [[429, []], RefundAttempt::UNKNOWN, 'http_429'],
            // A 404 or "already refunded"-type answer proves nothing: kept reserved for reconciliation (H-2)
            '404' => [[404, ['errors' => [['code' => 'resource_not_found']]]], RefundAttempt::UNKNOWN, 'resource_not_found'],
            'already refunded' => [[400, ['errors' => [['code' => 'parameter_above_maximum', 'detail' => 'Amount is above the refundable amount']]]], RefundAttempt::UNKNOWN, 'parameter_above_maximum'],
            '409' => [[409, ['errors' => [['code' => 'conflict']]]], RefundAttempt::UNKNOWN, 'conflict'],
            'rejected request 401' => [[401, ['errors' => [['code' => 'unauthorized']]]], RefundAttempt::FAILED, 'unauthorized'],
            'invalid parameter 400' => [[400, ['errors' => [['code' => 'parameter_invalid', 'detail' => 'reason is invalid']]]], RefundAttempt::FAILED, 'parameter_invalid'],
            'pending' => ['pending', RefundAttempt::PROVIDER_PENDING, null],
            'provider failed' => ['failed', RefundAttempt::FAILED, null],
            'malformed 200' => [[200, ['data' => ['id' => 'not_a_refund']]], RefundAttempt::UNKNOWN, 'unverifiable_response'],
            'wrong amount 200' => [[200, ['data' => ['id' => 'ref_x', 'attributes' => ['amount' => 1, 'payment_id' => 'pay_test_out', 'status' => 'succeeded']]]], RefundAttempt::UNKNOWN, 'unverifiable_response'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outcomes')]
    public function test_provider_answers_map_strictly_and_nothing_is_counted_as_refunded_without_confirmation(mixed $answer, string $status, ?string $code): void
    {
        $posts = [];
        $this->fakeRefunds([$answer], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000, 'paid', 'pay_test_out');
        $case = $this->approved($booking, 200000);

        $this->execute($case);

        $attempt = RefundAttempt::sole();
        $this->assertSame([$status, $code], [$attempt->status, $attempt->error_code]);
        $this->assertCount(1, $posts, 'Exactly one POST: never retried automatically.');
        $this->assertSame(0, app(RefundLedger::class)->position($booking)['refunded']);
        $this->assertSame(BookingRefund::APPROVED, $case->fresh()->status);
        if ($status === RefundAttempt::UNKNOWN || $status === RefundAttempt::PROVIDER_PENDING) {
            $this->execute($case); // clicking again must not send a second refund for an unclear outcome
            $this->assertCount(1, $posts);
        }
    }

    public function test_definite_failure_releases_the_amount_for_a_new_attempt(): void
    {
        $posts = [];
        $this->fakeRefunds(['failed', 'succeeded'], $posts); // PayMongo itself answered status "failed"
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $case = $this->approved($booking, 200000);

        $this->execute($case);
        $this->execute($case);

        $this->assertCount(2, $posts);
        $this->assertSame([RefundAttempt::FAILED, RefundAttempt::SUCCEEDED], RefundAttempt::orderBy('id')->pluck('status')->all());
        $this->assertSame(BookingRefund::COMPLETED, $case->fresh()->status);
    }

    // --------------------------------------------------------- crashes and concurrency

    public function test_crash_before_the_post_leaves_a_prepared_attempt_that_is_sent_once_later(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $payment = $this->receipt($booking, 2000);
        $case = $this->approved($booking, 200000);
        RefundAttempt::create(['booking_refund_id' => $case->id, 'payment_id' => $payment->id, 'operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'method' => 'paymongo', 'amount_centavos' => 200000, 'status' => RefundAttempt::PREPARED]);

        $this->execute($case);

        $this->assertCount(1, $posts, 'The prepared attempt is sent; no second allocation.');
        $this->assertSame([RefundAttempt::SUCCEEDED], RefundAttempt::pluck('status')->all());
    }

    public function test_crash_after_the_post_is_an_unknown_outcome_that_is_never_resent(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $payment = $this->receipt($booking, 2000);
        $case = $this->approved($booking, 200000);
        $attempt = RefundAttempt::create(['booking_refund_id' => $case->id, 'payment_id' => $payment->id, 'operation_id' => (string) \Illuminate\Support\Str::uuid(),
            'method' => 'paymongo', 'amount_centavos' => 200000, 'status' => RefundAttempt::SUBMITTING, 'submitted_at' => now()]);

        $this->execute($case);
        $this->assertSame([], $posts, 'The interrupted submission is never sent again.');

        // Once stale it is settled only by PayMongo's own record of the refund (looked up), never by a staff note
        $gets = [];
        $this->fakeRefundLookups(['ref_test_dash' => $this->providerRefund($attempt)], $gets);
        try {
            app(RefundCaseService::class)->verifyAttempt($attempt, 'ref_test_dash', $this->staff(), 'Checked PayMongo dashboard');
            $this->fail('A fresh submission must not be settled yet.');
        } catch (\RuntimeException) {
        }
        $this->assertSame([], $gets);
        $this->travel(11)->minutes();
        app(RefundCaseService::class)->verifyAttempt($attempt, 'ref_test_dash', $this->staff(), 'Found in PayMongo dashboard');

        $this->assertSame([RefundAttempt::SUCCEEDED, 'ref_test_dash', RefundAttempt::SOURCE_PROVIDER_VERIFICATION], [$attempt->fresh()->status, $attempt->fresh()->provider_refund_id, $attempt->fresh()->resolution_source]);
        $this->assertSame(BookingRefund::COMPLETED, $case->fresh()->status);
        $this->assertSame([], $posts, 'Settling never sends a refund.');
    }

    public function test_two_executions_racing_send_each_refund_once(): void
    {
        $posts = [];
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $case = $this->approved($booking, 200000);
        $executor = app(RefundExecutor::class);
        // The second "process" runs while the first is inside its PayMongo call
        $nested = false;
        \Illuminate\Support\Facades\Http::fake(function ($request) use (&$posts, &$nested, $executor, $case) {
            $posts[] = $request->data()['data']['attributes'];
            if (! $nested) {
                $nested = true;
                $executor->execute($case, $this->staff());
            }

            return \Illuminate\Support\Facades\Http::response(['data' => ['id' => 'ref_test_race', 'attributes' => ['amount' => 200000, 'payment_id' => $posts[0]['payment_id'], 'status' => 'succeeded']]]);
        });

        $executor->execute($case, $this->staff());

        $this->assertCount(1, $posts);
        $this->assertSame(1, RefundAttempt::count());
    }

    // ------------------------------------------------------------------- offline

    public function test_offline_receipt_waits_for_a_documented_payout(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000, 'completed', null); // cash at camp: no PayMongo id
        $case = $this->approved($booking, 200000);

        $this->execute($case);

        $attempt = RefundAttempt::sole();
        $this->assertSame([RefundAttempt::OFFLINE_AWAITING, 'offline'], [$attempt->status, $attempt->method]);
        $this->assertSame([], $posts);
        $this->assertSame(0, app(RefundLedger::class)->position($booking)['refunded'], 'Not refunded until the payout is documented.');
        $this->assertSame(200000, app(RefundLedger::class)->position($booking)['reserved']);

        app(RefundCaseService::class)->confirmOfflinePayout($attempt, $this->staff(), 'Cash returned at the office, receipt #A-102');

        $this->assertSame(200000, app(RefundLedger::class)->position($booking)['refunded']);
        $this->assertSame(BookingRefund::COMPLETED, $case->fresh()->status);
    }
}
