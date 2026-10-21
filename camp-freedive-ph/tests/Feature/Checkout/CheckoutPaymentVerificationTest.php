<?php

namespace Tests\Feature\Checkout;

use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Services\Checkout\PaymentVerificationResult;
use App\Services\Checkout\PaymentVerifier;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2A: PaymentVerifier decides, from a fresh PayMongo read, whether a checkout intent has an
 * authentic, successful payment that belongs to it. It never changes state, creates bookings or refunds.
 */
class CheckoutPaymentVerificationTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;

    private const SESSION = 'cs_test_verify_0001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo(); // synthetic sk_test_ key: test mode expected, stray HTTP blocked
        Mail::fake();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private int $seq = 0;

    private function intent(array $overrides = []): CheckoutIntent
    {
        $n = ++$this->seq;

        return CheckoutIntent::create(array_merge([
            'reference' => sprintf('CHK-VERIFY%06d', $n),
            'status' => CheckoutIntent::STATUS_PENDING,
            'start_date' => '2026-12-19',
            'end_date' => '2026-12-20',
            'pax_count' => 2,
            'class_type' => 'discovery',
            'pickup_option' => 'own',
            'currency' => 'PHP',
            'total_amount' => 9230.00,
            'downpayment_amount' => 4300.00,
            'payload' => ['contact' => ['name' => 'Casey Fixture', 'email' => 'casey.fixture@example.test', 'phone' => '09170000001']],
            'hold_expires_at' => now()->addMinutes(15),
            'paymongo_checkout_session_id' => $n === 1 ? self::SESSION : "cs_test_verify_{$n}",
        ], $overrides));
    }

    private function verify(CheckoutIntent $intent, ?string $claimedSessionId = null): PaymentVerificationResult
    {
        return app(PaymentVerifier::class)->verify($intent, $claimedSessionId);
    }

    private function assertNeedsReview(PaymentVerificationResult $result, string $reason): void
    {
        $this->assertSame(PaymentVerificationResult::NEEDS_REVIEW, $result->outcome, "Expected needs_review ({$reason}), got {$result->outcome} ({$result->reason}).");
        $this->assertSame($reason, $result->reason);
        $this->assertNull($result->payment);
    }

    private function assertRetryable(PaymentVerificationResult $result, string $reason): void
    {
        $this->assertSame(PaymentVerificationResult::RETRYABLE, $result->outcome, "Expected retryable ({$reason}), got {$result->outcome} ({$result->reason}).");
        $this->assertSame($reason, $result->reason);
        $this->assertNull($result->payment);
    }

    /** Verification is read-only: nothing operational is created and the intent is unchanged. */
    private function assertNoSideEffects(CheckoutIntent $intent): void
    {
        foreach (['bookings', 'booking_participants', 'batches', 'booking_price_adjustments', 'payments', 'participants'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "Verification must not write to {$table}.");
        }
        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->paymongo_payment_id);
        $this->assertNull($fresh->verification);
        $this->assertNull($fresh->booking_id);
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/refunds'));
    }

    // ------------------------------------------------------ successful verification

    public function test_paid_session_matching_the_intent_is_verified_with_normalized_evidence(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent));

        $result = $this->verify($intent);

        $this->assertTrue($result->isVerified(), "Expected verified, got {$result->outcome} ({$result->reason}).");
        $payment = $result->payment;
        $this->assertSame($intent->id, $payment->checkoutIntentId);
        $this->assertSame(self::SESSION, $payment->checkoutSessionId);
        $this->assertSame('pay_test_' . $intent->id . 'A', $payment->paymentId);
        $this->assertSame(430000, $payment->amountCentavos);
        $this->assertSame(4300.0, $payment->amount());
        $this->assertSame('PHP', $payment->currency);
        $this->assertSame('paid', $payment->status);
        $this->assertFalse($payment->livemode);
        $this->assertFalse($payment->sessionRecovered);
        $this->assertSame(1793606400, $payment->paidAt?->getTimestamp());
        $this->assertSame('gcash', $payment->paymentMethod);
        $this->assertSame('pi_test_' . $intent->id, $payment->paymentIntentId);
        $this->assertSame(7500, $payment->feeCentavos);
        $this->assertTrue($payment->verifiedAt->equalTo(Carbon::now()));

        $evidence = $payment->evidence('webhook');
        $this->assertSame([
            'source', 'verified_at', 'checkout_session_id', 'session_recovered', 'payment_id', 'payment_intent_id',
            'status', 'amount_centavos', 'currency', 'fee_centavos', 'paid_at', 'payment_method', 'livemode',
        ], array_keys($evidence));
        $this->assertStringNotContainsString('casey', strtolower(json_encode($evidence)), 'Evidence must not contain personal data.');
        $this->assertStringNotContainsString('src_test_1', json_encode($evidence), 'Evidence must not copy raw provider objects.');

        // The documented v1 endpoint was read, and nothing else happened
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/' . self::SESSION);
        $this->assertNoSideEffects($intent);
    }

    public function test_verifying_again_after_the_intents_own_booking_holds_the_payment_is_still_verified(): void
    {
        // Once finalized (Batch 2B), the intent's own booking holds the payment; re-verifying must not flag it
        $intent = $this->intent();
        $booking = (new Booking())->forceFill([
            'booking_number' => 'CFP-2026-VERIF', 'pin' => '0000', 'contact_name' => 'Casey Fixture', 'contact_email' => 'casey.fixture@example.test',
            'contact_phone' => '09170000001', 'class_type' => 'discovery', 'start_date' => '2026-12-19', 'end_date' => '2026-12-20',
            'status' => 'confirmed', 'total_amount' => 9230.00, 'downpayment_amount' => 4300.00, 'balance_amount' => 4930.00,
        ]);
        $booking->save();
        $intent->update(['booking_id' => $booking->id]);
        Payment::create(['booking_id' => $booking->id, 'transaction_id' => 'TXN-OWN-1', 'payment_type' => 'downpayment', 'payment_method' => 'gcash',
            'amount' => 4300.00, 'status' => 'paid', 'paymongo_payment_id' => 'pay_test_' . $intent->id . 'A']);
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent));

        $this->assertTrue($this->verify($intent->fresh())->isVerified());
    }

    // ------------------------------------------------------------- unpaid

    public static function unpaidCases(): array
    {
        return [
            'no payments yet' => [[], 'no_successful_payment'],
            'payment pending' => [['pending'], 'payment_pending'],
            'only failed attempts' => [['failed', 'failed'], 'no_successful_payment'],
            'failed then pending' => [['failed', 'pending'], 'payment_pending'],
        ];
    }

    #[DataProvider('unpaidCases')]
    public function test_unpaid_session_is_not_verified_and_changes_nothing(array $statuses, string $reason): void
    {
        $intent = $this->intent();
        $payments = array_map(fn ($s, $i) => ['id' => "pay_test_unpaid_{$i}", 'type' => 'payment', 'attributes' => [
            'amount' => 430000, 'currency' => 'PHP', 'status' => $s, 'livemode' => false,
        ]], $statuses, array_keys($statuses));
        $this->fakeSessionRetrieval(self::SESSION, $this->sessionBody($intent, $payments));

        $result = $this->verify($intent);

        $this->assertSame(PaymentVerificationResult::NOT_PAID, $result->outcome);
        $this->assertSame($reason, $result->reason);
        $this->assertNull($result->payment);
        $this->assertNoSideEffects($intent);
    }

    // ------------------------------------------------- mismatch / fraud protection

    public function test_wrong_amount_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent, ['attributes' => ['amount' => 100]]));

        $result = $this->verify($intent);

        $this->assertNeedsReview($result, 'amount_mismatch');
        $this->assertSame(['expected_centavos' => 430000, 'actual_centavos' => 100], array_intersect_key($result->details, array_flip(['expected_centavos', 'actual_centavos'])));
        $this->assertNoSideEffects($intent);
    }

    public function test_wrong_currency_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent, ['attributes' => ['currency' => 'USD']]));

        $this->assertNeedsReview($this->verify($intent), 'currency_mismatch');
    }

    public function test_claimed_session_id_different_from_the_saved_one_needs_review(): void
    {
        $intent = $this->intent();

        $this->assertNeedsReview($this->verify($intent, 'cs_test_someone_else'), 'session_mismatch');
        Http::assertNothingSent(); // decided before asking PayMongo
    }

    public function test_provider_returning_a_different_session_id_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent, [], [], 'cs_test_other_session'));

        $this->assertNeedsReview($this->verify($intent), 'session_mismatch');
    }

    public function test_session_created_for_another_intent_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent, [], ['attributes' => ['metadata' => ['checkout_intent_id' => '999999']]]));

        $this->assertNeedsReview($this->verify($intent), 'intent_mismatch');
    }

    public function test_session_with_another_checkout_reference_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent, [], ['attributes' => ['metadata' => ['checkout_reference' => 'CHK-NOTTHISONE1']]]));

        $this->assertNeedsReview($this->verify($intent), 'reference_mismatch');
    }

    public function test_unexpected_payment_status_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent, ['attributes' => ['status' => 'refunded']]));

        $this->assertNeedsReview($this->verify($intent), 'unexpected_payment_status');
    }

    public function test_live_mode_session_against_a_test_key_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent, [], ['attributes' => ['livemode' => true]]));

        $this->assertNeedsReview($this->verify($intent), 'mode_mismatch');
    }

    public function test_live_mode_payment_inside_a_test_session_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent, ['attributes' => ['livemode' => true]]));

        $this->assertNeedsReview($this->verify($intent), 'mode_mismatch');
    }

    public function test_unrecognised_secret_key_cannot_decide_the_mode_and_is_retryable(): void
    {
        config(['paymongo.secret_key' => 'synthetic_key_without_known_prefix']);
        $intent = $this->intent();

        $this->assertRetryable($this->verify($intent), 'provider_mode_unknown');
        Http::assertNothingSent();
    }

    public function test_paid_payment_without_a_payment_id_needs_review(): void
    {
        $intent = $this->intent();
        $body = $this->paidSessionBody($intent);
        unset($body['data']['attributes']['payments'][0]['id']);
        $this->fakeSessionRetrieval(self::SESSION, $body);

        $this->assertNeedsReview($this->verify($intent), 'payment_id_missing');
    }

    public function test_payment_id_already_claimed_by_another_intent_needs_review(): void
    {
        $intent = $this->intent();
        $this->intent(['paymongo_payment_id' => 'pay_test_' . $intent->id . 'A']);
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent));

        $this->assertNeedsReview($this->verify($intent), 'payment_id_used_by_other_intent');
    }

    public function test_payment_id_already_recorded_on_another_payment_needs_review(): void
    {
        $intent = $this->intent();
        $other = (new Booking())->forceFill([
            'booking_number' => 'CFP-2026-OTHER', 'pin' => '0000', 'contact_name' => 'Other Guest', 'contact_email' => 'other@example.test',
            'contact_phone' => '09170000002', 'class_type' => 'discovery', 'start_date' => '2026-12-19', 'end_date' => '2026-12-20',
            'status' => 'confirmed', 'total_amount' => 9230.00, 'downpayment_amount' => 4300.00, 'balance_amount' => 4930.00,
        ]);
        $other->save();
        Payment::create(['booking_id' => $other->id, 'transaction_id' => 'TXN-OTHER-1', 'payment_type' => 'downpayment', 'payment_method' => 'gcash',
            'amount' => 4300.00, 'status' => 'paid', 'paymongo_payment_id' => 'pay_test_' . $intent->id . 'A']);
        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent));

        $this->assertNeedsReview($this->verify($intent), 'payment_id_used_by_payment_record');
    }

    public function test_two_successful_payments_on_one_session_need_review(): void
    {
        $intent = $this->intent();
        $paid = fn (string $id) => ['id' => $id, 'type' => 'payment', 'attributes' => ['amount' => 430000, 'currency' => 'PHP', 'status' => 'paid', 'livemode' => false]];
        $this->fakeSessionRetrieval(self::SESSION, $this->sessionBody($intent, [$paid('pay_test_first'), $paid('pay_test_second')]));

        $result = $this->verify($intent);

        $this->assertNeedsReview($result, 'multiple_paid_payments');
        $this->assertSame(2, $result->details['paid_count']);
    }

    public function test_failed_attempt_followed_by_one_paid_payment_is_verified(): void
    {
        $intent = $this->intent();
        $paid = $this->paidSessionBody($intent)['data']['attributes']['payments'][0];
        $failed = ['id' => 'pay_test_failed', 'type' => 'payment', 'attributes' => ['amount' => 430000, 'currency' => 'PHP', 'status' => 'failed', 'livemode' => false]];
        $this->fakeSessionRetrieval(self::SESSION, $this->sessionBody($intent, [$failed, $paid]));

        $result = $this->verify($intent);

        $this->assertTrue($result->isVerified());
        $this->assertSame($paid['id'], $result->payment->paymentId);
    }

    // ------------------------------------------------------------- session recovery

    public function test_unsaved_session_is_recovered_only_when_metadata_matches_intent_and_reference(): void
    {
        $intent = $this->intent(['paymongo_checkout_session_id' => null]);
        $this->fakeSessionRetrieval('cs_test_recovered', $this->paidSessionBody($intent, [], [], 'cs_test_recovered'));

        $result = $this->verify($intent, 'cs_test_recovered');

        $this->assertTrue($result->isVerified(), "Expected verified, got {$result->outcome} ({$result->reason}).");
        $this->assertTrue($result->payment->sessionRecovered);
        $this->assertSame('cs_test_recovered', $result->payment->checkoutSessionId);
        $this->assertTrue($result->payment->evidence('webhook')['session_recovered']);

        // Recovery only reports the session id; it is not saved and no booking exists
        $this->assertNull($intent->fresh()->paymongo_checkout_session_id);
        $this->assertNoSideEffects($intent);
    }

    public function test_recovery_with_wrong_reference_needs_review(): void
    {
        $intent = $this->intent(['paymongo_checkout_session_id' => null]);
        $this->fakeSessionRetrieval('cs_test_recovered', $this->paidSessionBody($intent, [], ['attributes' => ['metadata' => ['checkout_reference' => 'CHK-WRONGREF001']]], 'cs_test_recovered'));

        $this->assertNeedsReview($this->verify($intent, 'cs_test_recovered'), 'reference_mismatch');
    }

    public function test_recovery_with_wrong_intent_id_needs_review(): void
    {
        $intent = $this->intent(['paymongo_checkout_session_id' => null]);
        $this->fakeSessionRetrieval('cs_test_recovered', $this->paidSessionBody($intent, [], ['attributes' => ['metadata' => ['checkout_intent_id' => '424242']]], 'cs_test_recovered'));

        $this->assertNeedsReview($this->verify($intent, 'cs_test_recovered'), 'intent_mismatch');
    }

    public function test_recovery_still_requires_amount_currency_status_and_mode(): void
    {
        $intent = $this->intent(['paymongo_checkout_session_id' => null]);
        $cases = [
            ['cs_test_rec_amount', ['attributes' => ['amount' => 1]], [], 'amount_mismatch'],
            ['cs_test_rec_currency', ['attributes' => ['currency' => 'USD']], [], 'currency_mismatch'],
            ['cs_test_rec_status', ['attributes' => ['status' => 'refunded']], [], 'unexpected_payment_status'],
            ['cs_test_rec_mode', [], ['attributes' => ['livemode' => true]], 'mode_mismatch'],
        ];

        foreach ($cases as [$sessionId, $paymentOverrides, $sessionOverrides, $reason]) {
            $this->fakeSessionRetrieval($sessionId, $this->paidSessionBody($intent, $paymentOverrides, $sessionOverrides, $sessionId));
            $this->assertNeedsReview($this->verify($intent, $sessionId), $reason);
        }
        $this->assertNoSideEffects($intent);
    }

    public function test_no_saved_and_no_claimed_session_needs_review(): void
    {
        $intent = $this->intent(['paymongo_checkout_session_id' => null]);

        $this->assertNeedsReview($this->verify($intent), 'no_checkout_session');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- provider errors

    public static function providerErrorCases(): array
    {
        return [
            'HTTP 500' => [500, 'provider_unavailable'],
            'HTTP 503' => [503, 'provider_unavailable'],
            'HTTP 429 (rate limited)' => [429, 'provider_unavailable'],
            'HTTP 401 (key/environment)' => [401, 'provider_rejected_request'],
        ];
    }

    #[DataProvider('providerErrorCases')]
    public function test_provider_http_errors_are_retryable_not_unpaid(int $status, string $reason): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, ['errors' => [['code' => 'synthetic', 'detail' => 'Synthetic error']]], $status);

        $this->assertRetryable($this->verify($intent), $reason);
        $this->assertNoSideEffects($intent);
    }

    public function test_connection_failure_is_retryable(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrievalConnectionFails(self::SESSION);

        $this->assertRetryable($this->verify($intent), 'provider_unavailable');
        $this->assertNoSideEffects($intent);
    }

    public function test_malformed_response_is_retryable(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, 'this is not json');

        $this->assertRetryable($this->verify($intent), 'provider_response_malformed');

        $other = $this->intent();
        $this->fakeSessionRetrieval($other->paymongo_checkout_session_id, ['data' => ['id' => $other->paymongo_checkout_session_id]]);
        $this->assertRetryable($this->verify($other), 'provider_response_malformed');
    }

    public function test_temporarily_missing_payment_data_is_retryable(): void
    {
        $intent = $this->intent();
        $body = $this->paidSessionBody($intent);
        unset($body['data']['attributes']['payments']);
        $this->fakeSessionRetrieval(self::SESSION, $body);
        $this->assertRetryable($this->verify($intent), 'provider_response_incomplete');

        $second = $this->intent();
        $body = $this->paidSessionBody($second);
        unset($body['data']['attributes']['payments'][0]['attributes']['amount']);
        $this->fakeSessionRetrieval($second->paymongo_checkout_session_id, $body);
        $this->assertRetryable($this->verify($second), 'provider_response_incomplete');

        $third = $this->intent();
        $body = $this->paidSessionBody($third);
        unset($body['data']['attributes']['livemode']);
        $this->fakeSessionRetrieval($third->paymongo_checkout_session_id, $body);
        $this->assertRetryable($this->verify($third), 'provider_response_incomplete');
    }

    public function test_unknown_session_at_paymongo_needs_review(): void
    {
        $intent = $this->intent();
        $this->fakeSessionRetrieval(self::SESSION, ['errors' => [['code' => 'resource_not_found_state', 'detail' => 'Not found']]], 404);

        $this->assertNeedsReview($this->verify($intent), 'session_not_found');
    }

    public function test_simulated_mode_without_a_secret_key_is_retryable(): void
    {
        config(['paymongo.secret_key' => null]);
        $intent = $this->intent();

        $this->assertRetryable($this->verify($intent), 'provider_mode_unknown');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ cache safety

    public function test_stale_cached_unpaid_responses_never_override_a_fresh_successful_payment(): void
    {
        $intent = $this->intent();
        $paymentId = 'pay_test_' . $intent->id . 'A';

        // What the existing cached helpers would return (getCheckoutSession / getPayment cache keys)
        Cache::put('paymongo_session_' . self::SESSION, $this->sessionBody($intent, []), 300);
        Cache::put('paymongo_payment_' . $paymentId, ['data' => ['id' => $paymentId, 'attributes' => ['status' => 'pending']]], 300);

        $this->fakeSessionRetrieval(self::SESSION, $this->paidSessionBody($intent));

        $this->assertTrue($this->verify($intent)->isVerified());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.paymongo.com/v1/checkout_sessions/' . self::SESSION);
    }

    public function test_every_verification_reads_paymongo_again(): void
    {
        $intent = $this->intent();
        Http::fake(['api.paymongo.com/v1/checkout_sessions/' . self::SESSION => Http::sequence()
            ->push($this->sessionBody($intent, []))
            ->push($this->paidSessionBody($intent))]);

        $this->assertTrue($this->verify($intent)->isNotPaid());
        $this->assertTrue($this->verify($intent)->isVerified(), 'A newer payment must be seen on the next verification.');
        Http::assertSentCount(2);
    }
}
