<?php

namespace Tests\Feature\Refunds;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\Payment;
use App\Models\PaymongoWebhookEvent;
use App\Models\RefundAttempt;
use App\Services\Refunds\RefundExecutor;
use App\Services\Refunds\RefundLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-I (P-01..P-03, W-01): the legacy PayMongo webhook. Durable once-only events that are retried on failure,
 * verified payment evidence that is never overwritten, and refund events that update the ledger by status.
 */
class LegacyWebhookIntegrityTest extends TestCase
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

    private function pendingBooking(): Booking
    {
        $booking = $this->booking(['status' => 'pending_downpayment']);
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'paymongo', 'transaction_id' => 'PAY-PEND-' . $booking->id,
            'paymongo_resource_id' => 'cs_test_legacy_' . $booking->id, 'amount' => 2000, 'payment_type' => 'downpayment', 'status' => 'pending']);

        return $booking;
    }

    private function paidEvent(Booking $booking, string $eventId, string $payId, array $payment = []): array
    {
        return ['data' => ['id' => $eventId, 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false, 'data' => [
            'id' => 'cs_test_legacy_' . $booking->id, 'type' => 'checkout_session',
            'attributes' => [
                'metadata' => ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number],
                'payments' => [['id' => $payId, 'type' => 'payment', 'attributes' => array_merge(
                    ['amount' => 200000, 'currency' => 'PHP', 'status' => 'paid', 'livemode' => false, 'fee' => 5000, 'source' => ['type' => 'gcash']], $payment)]],
            ],
        ]]]];
    }

    private function refundEvent(string $eventId, string $refundId, string $payId, int $amount, string $status, string $notes = ''): array
    {
        return ['data' => ['id' => $eventId, 'type' => 'event', 'attributes' => ['type' => 'refund.updated', 'livemode' => false, 'data' => [
            'id' => $refundId, 'type' => 'refund',
            'attributes' => ['amount' => $amount, 'currency' => 'PHP', 'payment_id' => $payId, 'status' => $status, 'livemode' => false, 'notes' => $notes],
        ]]]];
    }

    // ------------------------------------------------------------- paid events

    public function test_verified_paid_event_confirms_once_and_duplicates_or_replays_change_nothing(): void
    {
        $booking = $this->pendingBooking();
        $event = $this->paidEvent($booking, 'evt_legacy_1', 'pay_test_legacy_1');

        $this->postSignedPayMongoWebhook($event)->assertOk();
        $this->postSignedPayMongoWebhook($event)->assertOk()->assertJson(['status' => 'duplicate_ignored']);
        Cache::flush(); // the durable record does not depend on the cache (the old 24-hour dedup did)
        $this->postSignedPayMongoWebhook($event)->assertOk()->assertJson(['status' => 'duplicate_ignored']);

        $this->assertSame('confirmed', $booking->fresh()->status);
        $payment = Payment::where('booking_id', $booking->id)->sole();
        $this->assertSame(['paid', 'pay_test_legacy_1', 2000.0], [$payment->status, $payment->paymongo_payment_id, (float) $payment->amount]);
        $this->assertSame(['processed', 1], [PaymongoWebhookEvent::sole()->status, PaymongoWebhookEvent::sole()->attempts]);
    }

    public function test_a_failed_handler_is_retried_on_the_next_delivery(): void
    {
        $booking = $this->pendingBooking();
        $event = $this->paidEvent($booking, 'evt_legacy_fail', 'pay_test_legacy_fail');
        $fail = true;
        Event::listen('eloquent.saving: ' . Payment::class, function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('Synthetic database failure');
            }
        });

        $this->postSignedPayMongoWebhook($event)->assertStatus(500);
        $this->assertSame('failed', PaymongoWebhookEvent::sole()->status);
        $this->assertSame('pending_downpayment', $booking->fresh()->status);

        $fail = false;
        $this->postSignedPayMongoWebhook($event)->assertOk(); // PayMongo's retry is processed, not dropped as a duplicate

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(['processed', 2], [PaymongoWebhookEvent::sole()->status, PaymongoWebhookEvent::sole()->attempts]);
    }

    public static function rejectedPayments(): array
    {
        return [
            'currency' => [['currency' => 'USD']],
            'livemode' => [['livemode' => true]],
            'missing amount' => [['amount' => null]],
            'not paid' => [['status' => 'pending']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedPayments')]
    public function test_unverifiable_payment_is_not_applied(array $payment): void
    {
        $booking = $this->pendingBooking();

        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'evt_bad', 'pay_test_bad', $payment))->assertOk();

        $this->assertSame('pending_downpayment', $booking->fresh()->status);
        $this->assertSame(0, Payment::where('status', 'paid')->count());
        $this->assertSame(1, AuditLog::where('action', 'PAYMONGO_WEBHOOK_PAYMENT_REJECTED')->count());
    }

    public function test_amount_mismatch_is_recorded_as_received_but_does_not_confirm(): void
    {
        $booking = $this->pendingBooking();

        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'evt_amt', 'pay_test_amt', ['amount' => 150000]))->assertOk();

        $this->assertSame('pending_downpayment', $booking->fresh()->status);
        $this->assertSame([1500.0, 'paid'], [(float) Payment::sole()->amount, Payment::sole()->status], 'Evidence kept.');
        $this->assertSame(1, AuditLog::where('action', 'PAYMONGO_WEBHOOK_AMOUNT_MISMATCH')->count());
    }

    public function test_a_second_distinct_payment_never_overwrites_the_first_and_a_known_id_is_never_moved(): void
    {
        $booking = $this->pendingBooking();
        $other = $this->pendingBooking();
        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'evt_p1', 'pay_test_first'))->assertOk();

        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'evt_p2', 'pay_test_second'))->assertOk();
        $this->postSignedPayMongoWebhook($this->paidEvent($other, 'evt_p3', 'pay_test_first'))->assertOk(); // same payment id, other booking

        $this->assertSame(['pay_test_first', 'pay_test_second'], Payment::where('booking_id', $booking->id)->where('status', 'paid')->orderBy('id')->pluck('paymongo_payment_id')->all());
        $this->assertSame(0, Payment::where('booking_id', $other->id)->where('status', 'paid')->count());
        $this->assertSame('pending_downpayment', $other->fresh()->status);
        $this->assertSame(1, AuditLog::where('action', 'PAYMONGO_WEBHOOK_PAYMENT_CONFLICT')->count());
    }

    public function test_late_payment_on_a_cancelled_booking_is_kept_without_reinstating_it(): void
    {
        $booking = $this->pendingBooking();
        $booking->update(['status' => 'cancelled_by_guest']);

        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'evt_late', 'pay_test_late'))->assertOk();

        $this->assertSame('cancelled_by_guest', $booking->fresh()->status);
        $this->assertSame('paid', Payment::where('paymongo_payment_id', 'pay_test_late')->sole()->status);
        $this->assertSame(1, AuditLog::where('action', 'PAYMONGO_WEBHOOK_LATE_PAYMENT')->count());
    }

    // ------------------------------------------------------------ refund events

    public function test_refund_events_follow_the_refund_status_and_never_flip_the_payment(): void
    {
        $posts = [];
        $this->fakeRefunds(['pending'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $payment = $this->receipt($booking, 4250, 'paid', 'pay_test_rev', 'full');
        $case = app(\App\Services\Refunds\RefundCaseService::class)->approve($this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL), 425000, $this->staff(), 'ok');
        app(RefundExecutor::class)->execute($case, $this->staff());
        $this->assertSame(RefundAttempt::PROVIDER_PENDING, RefundAttempt::sole()->status);

        // Delayed redelivery order: "pending" again, then "succeeded"
        $this->postSignedPayMongoWebhook($this->refundEvent('evt_r1', 'ref_test_1', 'pay_test_rev', 425000, 'pending'))->assertOk();
        $this->postSignedPayMongoWebhook($this->refundEvent('evt_r2', 'ref_test_1', 'pay_test_rev', 425000, 'succeeded'))->assertOk();
        $this->postSignedPayMongoWebhook($this->refundEvent('evt_r3', 'ref_test_1', 'pay_test_rev', 425000, 'failed'))->assertOk(); // late contradiction

        $this->assertSame(RefundAttempt::SUCCEEDED, RefundAttempt::sole()->status, 'A finished refund is never changed.');
        $this->assertSame(1, AuditLog::where('action', 'PAYMONGO_REFUND_EVENT_CONFLICT')->count());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(BookingRefund::COMPLETED, $case->fresh()->status);
    }

    public function test_refund_event_for_a_lost_answer_is_matched_by_operation_id(): void
    {
        $posts = [];
        $this->fakeRefunds([new \Illuminate\Http\Client\ConnectionException('Synthetic timeout')], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000, 'paid', 'pay_test_lost');
        $case = app(\App\Services\Refunds\RefundCaseService::class)->approve($this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL), 200000, $this->staff(), 'ok');
        app(RefundExecutor::class)->execute($case, $this->staff());
        $attempt = RefundAttempt::sole();
        $this->assertSame(RefundAttempt::UNKNOWN, $attempt->status);

        $this->postSignedPayMongoWebhook($this->refundEvent('evt_lost', 'ref_test_lost', 'pay_test_lost', 200000, 'succeeded', "Camp FreedivePH refund op:{$attempt->operation_id}"))->assertOk();

        $this->assertSame([RefundAttempt::SUCCEEDED, 'ref_test_lost'], [$attempt->fresh()->status, $attempt->fresh()->provider_refund_id]);
        $this->assertSame(BookingRefund::COMPLETED, $case->fresh()->status);
    }

    public function test_partial_refund_made_outside_the_system_is_recorded_once(): void
    {
        $booking = $this->booking();
        $payment = $this->receipt($booking, 4250, 'paid', 'pay_test_ext', 'full');
        $event = $this->refundEvent('evt_ext', 'ref_test_ext', 'pay_test_ext', 100000, 'succeeded');

        $this->postSignedPayMongoWebhook($event)->assertOk();
        $this->postSignedPayMongoWebhook($this->refundEvent('evt_ext_b', 'ref_test_ext', 'pay_test_ext', 100000, 'succeeded'))->assertOk();

        $attempt = RefundAttempt::sole();
        $this->assertSame(['external', RefundAttempt::SUCCEEDED, 100000], [$attempt->method, $attempt->status, $attempt->amount_centavos]);
        $position = app(RefundLedger::class)->position($booking);
        $this->assertSame([425000, 100000, 325000], [$position['paid'], $position['refunded'], $position['refundable']]);
        $this->assertSame('paid', $payment->fresh()->status, 'A partial refund does not mark the whole payment refunded.');
    }

    public function test_failed_refund_event_releases_nothing_and_counts_nothing(): void
    {
        $booking = $this->booking();
        $this->receipt($booking, 2000, 'paid', 'pay_test_fail_ref');

        $this->postSignedPayMongoWebhook($this->refundEvent('evt_fr', 'ref_test_fr', 'pay_test_fail_ref', 200000, 'failed'))->assertOk();

        $this->assertSame(0, app(RefundLedger::class)->position($booking)['refunded']);
        $this->assertSame(200000, app(RefundLedger::class)->position($booking)['refundable']);
    }
}
