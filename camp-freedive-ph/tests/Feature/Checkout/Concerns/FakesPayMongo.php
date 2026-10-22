<?php

namespace Tests\Feature\Checkout\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Fake PayMongo for the checkout-intent tests. Never reaches the real API.
 *
 * Uses the real PayMongoService request path (a synthetic secret key turns off
 * simulated mode) so the tests can inspect the exact checkout-session payload.
 */
trait FakesPayMongo
{
    protected const FAKE_SECRET_KEY = 'sk_test_synthetic_not_a_real_key';
    protected const FAKE_WEBHOOK_SECRET = 'whsk_synthetic_test_secret';

    /** Every outgoing request must be stubbed; call before the app resolves PayMongoService. */
    protected function setUpFakePayMongo(): void
    {
        Http::preventStrayRequests();
        config([
            'paymongo.secret_key' => self::FAKE_SECRET_KEY,
            'paymongo.webhook_signature_secret' => self::FAKE_WEBHOOK_SECRET,
        ]);
    }

    /** PayMongo creates the checkout session successfully (v2 endpoint, as PayMongoService calls it first). */
    protected function fakeCheckoutSessionCreated(string $sessionId = 'cs_test_intent_001'): string
    {
        $checkoutUrl = "https://checkout.paymongo.test/{$sessionId}";

        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => Http::response([
                'data' => [
                    'id' => $sessionId,
                    'type' => 'checkout_session',
                    'attributes' => ['checkout_url' => $checkoutUrl, 'status' => 'active', 'payments' => []],
                ],
            ], 200),
        ]);

        return $checkoutUrl;
    }

    /** PayMongo rejects checkout-session creation on both API versions. */
    protected function fakeCheckoutSessionFails(): void
    {
        $error = ['errors' => [['code' => 'resource_failed_state', 'detail' => 'Synthetic PayMongo outage for tests.']]];

        Http::fake([
            'api.paymongo.com/v2/checkout_sessions' => Http::response($error, 500),
            'api.paymongo.com/v1/checkout_sessions' => Http::response($error, 500),
        ]);
    }

    /** The JSON body ("data.attributes") of every checkout-session request sent to PayMongo. */
    protected function sentCheckoutSessionAttributes(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/checkout_sessions'))
            ->map(fn ($pair) => $pair[0]->data()['data']['attributes'] ?? [])
            ->values()
            ->all();
    }

    /** Assert exactly one checkout session was requested and return its attributes. */
    protected function singleCheckoutSessionAttributes(): array
    {
        $sent = $this->sentCheckoutSessionAttributes();
        $this->assertCount(1, $sent, 'Expected exactly one PayMongo checkout-session request.');

        return $sent[0];
    }

    /**
     * Body of GET /v1/checkout_sessions/{id} with one paid payment for the intent.
     *
     * Shape follows PayMongo's documented Checkout Session and Payment resources only
     * (docs.paymongo.com/reference/checkout-session-resource, /payment-source): session id, type,
     * attributes.livemode, metadata (string values), status (active|expired), payments[]; payment id (pay_),
     * type, attributes.amount (centavos), currency, status (pending|failed|paid), livemode, paid_at,
     * payment_intent_id, fee, net_amount, source.type. Test mode (livemode false), synthetic ids only.
     */
    protected function paidSessionBody(\App\Models\CheckoutIntent $intent, array $paymentOverrides = [], array $sessionOverrides = [], ?string $sessionId = null): array
    {
        $amount = (int) round((float) $intent->downpayment_amount * 100);

        $payment = array_replace_recursive([
            'id' => 'pay_test_' . $intent->id . 'A',
            'type' => 'payment',
            'attributes' => [
                'amount' => $amount,
                'currency' => 'PHP',
                'status' => 'paid',
                'livemode' => false,
                'paid_at' => 1793606400,
                'payment_intent_id' => 'pi_test_' . $intent->id,
                'fee' => 7500,
                'net_amount' => $amount - 7500,
                'source' => ['id' => 'src_test_1', 'type' => 'gcash'],
            ],
        ], $paymentOverrides);

        return $this->sessionBody($intent, [$payment], $sessionOverrides, $sessionId);
    }

    /**
     * A checkout-session body for the intent with the given payment objects. The nested payment intent defaults to
     * awaiting_payment_method (nothing started; observed at creation in test mode, Batch 2C.2.7A); override
     * attributes.payment_intent.attributes.status, or set attributes.payment_intent to null to leave it out.
     */
    protected function sessionBody(\App\Models\CheckoutIntent $intent, array $payments, array $sessionOverrides = [], ?string $sessionId = null): array
    {
        return ['data' => array_replace_recursive([
            'id' => $sessionId ?? $intent->paymongo_checkout_session_id,
            'type' => 'checkout_session',
            'attributes' => [
                'livemode' => false,
                'status' => 'active',
                'metadata' => [
                    'checkout_intent_id' => (string) $intent->id,
                    'checkout_reference' => $intent->reference,
                ],
                'payments' => $payments,
                'payment_intent' => [
                    'id' => 'pi_test_' . $intent->id,
                    'type' => 'payment_intent',
                    'attributes' => ['status' => 'awaiting_payment_method', 'payments' => [], 'next_action' => null],
                ],
            ],
        ], $sessionOverrides)];
    }

    /** PayMongo answers GET /v1/checkout_sessions/{id} with this body (fresh read, as the verifier does). */
    protected function fakeSessionRetrieval(string $sessionId, array|string $body, int $status = 200): void
    {
        Http::fake(["api.paymongo.com/v1/checkout_sessions/{$sessionId}" => Http::response($body, $status)]);
    }

    /**
     * A Batch 2A "verified" result for the intent, as PaymentVerifier would return it after a matching
     * paid session (synthetic ids; amounts in centavos). For finalizer tests that do not need HTTP.
     */
    protected function verifiedResultFor(\App\Models\CheckoutIntent $intent, array $overrides = []): \App\Services\Checkout\PaymentVerificationResult
    {
        $values = array_merge([
            'checkoutIntentId' => $intent->id,
            'checkoutSessionId' => $intent->paymongo_checkout_session_id ?? ('cs_test_final_' . $intent->id),
            'paymentId' => 'pay_test_final_' . $intent->id,
            'amountCentavos' => (int) round((float) $intent->downpayment_amount * 100),
            'currency' => 'PHP',
            'status' => 'paid',
            'livemode' => false,
            'verifiedAt' => \Carbon\CarbonImmutable::now(),
            'sessionRecovered' => false,
            'paidAt' => \Carbon\CarbonImmutable::now()->subMinute(),
            'paymentMethod' => 'gcash',
            'paymentIntentId' => 'pi_test_final_' . $intent->id,
            'feeCentavos' => 7500,
        ], $overrides);

        return \App\Services\Checkout\PaymentVerificationResult::verified(new \App\Services\Checkout\VerifiedPayment(...$values));
    }

    /** The connection to PayMongo fails (timeout / DNS / reset). */
    protected function fakeSessionRetrievalConnectionFails(string $sessionId): void
    {
        Http::fake(["api.paymongo.com/v1/checkout_sessions/{$sessionId}" => Http::failedConnection('Synthetic timeout')]);
    }

    /** Send a webhook signed the way PayMongo signs it (same scheme as PayMongoIntegrationTest). For later batches. */
    protected function postSignedPayMongoWebhook(array $payload)
    {
        $body = json_encode($payload);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$body}", self::FAKE_WEBHOOK_SECRET);

        return $this->call('POST', route('paymongo.webhook.api'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => "t={$t},te={$sig},li=",
        ], $body);
    }
}
