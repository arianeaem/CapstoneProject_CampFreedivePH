<?php

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\RefundAttempt;
use App\Services\Payment\PaymentExceptionService;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundExecutor;
use App\Services\Refunds\RefundLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B1: receipt-level quarantine. A receipt held by an unresolved payment exception (or review flag) is never
 * spent by an ordinary booking refund; other, undisputed receipts of the same booking still are, when they cover the
 * whole entitlement. Otherwise approval and payout are refused with the held amount: the entitlement is never reduced.
 */
class ReceiptQuarantineTest extends TestCase
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

    private function hold(Payment $receipt): PaymentException
    {
        return DB::transaction(fn () => app(PaymentExceptionService::class)->open(PaymentException::KIND_PAID_AGAIN, $receipt, 'test'));
    }

    /** A camp-cancelled booking with an undisputed ₱2,000 downpayment and a second ₱2,000 payment that is held. */
    private function bookingWithHeldReceipt(): array
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $undisputed = $this->receipt($booking, 2000, 'paid', 'pay_q_undisputed');
        $held = $this->receipt($booking, 2000, 'paid', 'pay_q_held');
        $exception = $this->hold($held);

        return [$booking, $undisputed, $held, $exception];
    }

    public function test_ledger_keeps_its_figures_and_adds_held_and_spendable(): void
    {
        [$booking, , $held, $exception] = $this->bookingWithHeldReceipt();

        $position = app(RefundLedger::class)->position($booking);

        $this->assertSame([400000, 0, 0, 400000, 400000], [$position['paid'], $position['refunded'], $position['reserved'], $position['retained'], $position['refundable']]);
        $this->assertSame([200000, 200000], [$position['held'], $position['spendable']]);
        $this->assertSame([$held->id => "exception #{$exception->id}"], $position['held_by']);
    }

    public function test_undisputed_receipts_covering_the_whole_entitlement_are_refunded_and_the_held_one_is_untouched(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        [$booking, $undisputed, $held] = $this->bookingWithHeldReceipt();
        // Weather before diving: the downpayment only (₱2,000), which the undisputed receipt covers
        $case = $this->campCase($booking, BookingRefund::REASON_WEATHER_PRE_DIVE);

        $this->assertNull(app(RefundLedger::class)->entitlement($case)['blocked']);
        $case = app(RefundCaseService::class)->approve($case, 200000, $this->staff(), 'Synthetic approval');
        app(RefundExecutor::class)->execute($case, $this->staff());

        $this->assertSame([['pay_q_undisputed', 200000]], array_map(fn ($p) => [$p['payment_id'], $p['amount']], $posts));
        $this->assertSame(0, RefundAttempt::where('payment_id', $held->id)->count());
        $this->assertSame([RefundAttempt::SUCCEEDED, $undisputed->id], [RefundAttempt::sole()->status, (int) RefundAttempt::sole()->payment_id]);
        // Batch 3-I weather rule unchanged: money paid beyond the downpayment stays an open balance question
        $this->assertSame('unresolved', $case->fresh()->balance_review);
    }

    public function test_an_entitlement_that_needs_held_money_is_refused_with_the_held_amount_and_never_reduced(): void
    {
        [$booking] = $this->bookingWithHeldReceipt();
        $case = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL); // everything refundable: ₱4,000

        $entitlement = app(RefundLedger::class)->entitlement($case);

        $this->assertSame(0, $entitlement['cap']);
        $this->assertStringContainsString('₱2,000.00 of this refund depends on received money that is held (exception #', $entitlement['blocked']);
        foreach ([400000, 200000] as $amount) {
            try {
                app(RefundCaseService::class)->approve($case, $amount, $this->staff('owner'), 'Synthetic approval');
                $this->fail("approving {$amount} must be refused");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('held', $e->getMessage());
            }
        }
        $this->assertNotSame(BookingRefund::APPROVED, $case->fresh()->status);
        $this->assertSame(0, RefundAttempt::count());
    }

    public function test_a_case_approved_before_the_hold_is_not_paid_from_held_money(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000, 'paid', 'pay_q_first');
        $second = $this->receipt($booking, 2000, 'paid', 'pay_q_second');
        $case = app(RefundCaseService::class)->approve($this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL), 400000, $this->staff(), 'Synthetic approval');
        $this->hold($second); // e.g. a review flag found after the approval

        try {
            app(RefundExecutor::class)->execute($case, $this->staff());
            $this->fail('payout must be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('₱2,000.00 of this refund depends on received money that is held', $e->getMessage());
        }

        $this->assertSame([], $posts);
        $this->assertSame(0, RefundAttempt::count(), 'nothing prepared: no partial payout of an approved amount');
        $this->assertSame(BookingRefund::APPROVED, $case->fresh()->status);
    }

    public function test_an_unresolved_review_flag_holds_its_receipt_too(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000, 'paid', 'pay_q_flag', 'downpayment', ['review_reason' => Payment::REVIEW_PAID_AGAIN, 'review_flagged_at' => now()]);
        $case = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL);

        $this->assertStringContainsString('a payment awaiting review', app(RefundLedger::class)->entitlement($case)['blocked']);
        $this->assertNull(app(RefundLedger::class)->allocate($booking, 100));
    }

    public function test_cross_case_protection_and_reserved_attempts_still_count_against_every_receipt(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $receipt = $this->receipt($booking, 2000, 'paid', 'pay_q_reserved');
        RefundAttempt::create(['payment_id' => $receipt->id, 'operation_id' => (string) \Illuminate\Support\Str::uuid(), 'method' => 'paymongo',
            'amount_centavos' => 150000, 'currency' => 'PHP', 'status' => RefundAttempt::UNKNOWN]);

        $position = app(RefundLedger::class)->position($booking);

        $this->assertSame([150000, 50000, 0, 50000], [$position['reserved'], $position['refundable'], $position['held'], $position['spendable']]);
        $this->assertNull(app(RefundLedger::class)->allocate($booking, 50001));
    }
}
