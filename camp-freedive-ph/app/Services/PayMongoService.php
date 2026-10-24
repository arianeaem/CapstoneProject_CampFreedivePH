<?php

namespace App\Services;

use App\Services\ExternalApi\ExternalApiClient;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PayMongo payments (QR Ph, GCash, Maya, cards, BPI).
 *
 * - creates checkout sessions and payment intents
 * - refunds
 * - checks the webhook signature (Paymongo-Signature)
 * - calls go through ExternalApiClient so we get the limits and retries
 * - if there are no API keys (local/testing) it makes a fake session so checkout still works
 */
class PayMongoService
{
    protected ?string $secretKey;
    protected ?string $publicKey;
    protected ?string $webhookSecret;
    protected string $baseUrl;
    protected string $currency;
    protected ExternalApiClient $apiClient;

    public function __construct(?ExternalApiClient $apiClient = null)
    {
        $this->secretKey = config('paymongo.secret_key') ?: config('services.paymongo.secret_key');
        $this->publicKey = config('paymongo.public_key') ?: config('services.paymongo.public_key');
        $this->webhookSecret = config('paymongo.webhook_signature_secret') ?: config('services.paymongo.webhook_secret');
        $this->baseUrl = config('paymongo.base_url') ?: config('services.paymongo.base_url', 'https://api.paymongo.com/v1');
        $this->currency = config('paymongo.currency', 'PHP');
        $this->apiClient = $apiClient ?? app(ExternalApiClient::class);
    }

    /**
     * Send a request to PayMongo through ExternalApiClient. TLS certificate and hostname verification always stay
     * on: requests carry the secret key, so there is no option to turn verification off.
     */
    protected function request(string $method, string $url, array $payload = [], int $timeout = 15, array $extra = [])
    {
        unset($extra['without_verifying']);

        return $this->apiClient->execute('paymongo', $method, $url, array_merge([
            'json' => $payload,
            'basic_auth' => [$this->secretKey ?? '', ''],
            'timeout' => $timeout,
        ], $extra));
    }

    /**
     * Create a PayMongo checkout session (QR Ph, GCash, BPI, cards, Maya).
     *
     * @param array $lineItems [['name' => ..., 'amount' => in centavos, 'quantity' => ..., 'currency' => 'PHP']]
     * @param array $options description, success_url, cancel_url, payment_method_types, metadata, billing, reference_number
     * @return array
     */
    public function createCheckoutSession(array $lineItems, array $options = []): array
    {
        if (empty($this->secretKey) || str_contains($this->secretKey, 'your_secret_key')) {
            return [
                'success' => true,
                'checkout_id' => 'cs_test_' . bin2hex(random_bytes(10)),
                'checkout_url' => $options['success_url'] ?? route('landing'),
                'simulated' => true,
            ];
        }

        try {
            $paymentMethodTypes = $options['payment_method_types'] ?? config('paymongo.payment_method_types', ['qrph', 'gcash', 'paymaya', 'card', 'grab_pay']);

            $attributes = [
                'send_email_receipt' => true,
                'show_description' => true,
                'show_line_items' => true,
                'pass_on_fees' => true,
                'line_items' => $lineItems,
                'payment_method_types' => $paymentMethodTypes,
                'description' => $options['description'] ?? 'Camp FreedivePH Booking Downpayment',
            ];

            if (!empty($options['reference_number'])) {
                $attributes['reference_number'] = $options['reference_number'];
            }

            if (!empty($options['success_url'])) {
                $attributes['success_url'] = $options['success_url'];
            }

            if (!empty($options['cancel_url'])) {
                $attributes['cancel_url'] = $options['cancel_url'];
            }

            if (!empty($options['billing'])) {
                $attributes['billing'] = array_filter([
                    'name' => $options['billing']['name'] ?? null,
                    'email' => $options['billing']['email'] ?? null,
                    'phone' => $options['billing']['phone'] ?? null,
                ]);
            }

            if (!empty($options['metadata'])) {
                $attributes['metadata'] = $options['metadata'];
            }

            $payload = [
                'data' => [
                    'attributes' => $attributes,
                ],
            ];

            // Creating a session is never retried automatically: after a timeout or 5xx PayMongo may already have
            // created it, and a blind retry would leave a second session for the same checkout.
            $noRetry = ['max_retries' => 0];

            // Use the v2 checkout_sessions endpoint
            $response = $this->request('POST', "https://api.paymongo.com/v2/checkout_sessions", $payload, 15, $noRetry);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'checkout_id' => $data['data']['id'] ?? null,
                    'checkout_url' => $data['data']['attributes']['checkout_url'] ?? null,
                    'data' => $data,
                ];
            }

            // Log the v2 error
            Log::warning('PayMongo Create v2 Checkout Session Failed: ' . $response->body());

            // Only a definite rejection (a 4xx that is not a timeout, conflict or rate limit) proves no session was
            // created, so only then is v1 tried. After a 5xx or 408/409/429 the session may exist: report failure.
            $status = $response->status();
            if ($status < 400 || $status >= 500 || in_array($status, [408, 409, 429], true)) {
                return [
                    'success' => false,
                    'error' => $response->json() ?? $response->body(),
                ];
            }

            // Try v1 instead
            $responseV1 = $this->request('POST', "https://api.paymongo.com/v1/checkout_sessions", $payload, 15, $noRetry);
            if ($responseV1->successful()) {
                $dataV1 = $responseV1->json();
                return [
                    'success' => true,
                    'checkout_id' => $dataV1['data']['id'] ?? null,
                    'checkout_url' => $dataV1['data']['attributes']['checkout_url'] ?? null,
                    'data' => $dataV1,
                ];
            }

            return [
                'success' => false,
                'error' => $response->json() ?? $response->body(),
            ];
        } catch (Exception $e) {
            Log::error('PayMongo Create Checkout Session Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a payment intent (for our own checkout page).
     *
     * @param float $amountInPesos
     * @param array $paymentMethods
     * @param string $description
     * @param array $metadata
     * @return array
     */
    public function createPaymentIntent(float $amountInPesos, array $paymentMethods = ['gcash', 'card', 'paymaya'], string $description = '', array $metadata = []): array
    {
        $amountInCentavos = (int) round($amountInPesos * 100);

        if (empty($this->secretKey) || str_contains($this->secretKey, 'your_secret_key')) {
            return [
                'success' => true,
                'payment_intent_id' => 'pi_test_' . bin2hex(random_bytes(10)),
                'client_key' => 'pi_client_key_simulated',
                'status' => 'awaiting_payment_method',
                'simulated' => true,
            ];
        }

        try {
            $response = $this->request('POST', "{$this->baseUrl}/payment_intents", [
                'data' => [
                    'attributes' => [
                        'amount' => $amountInCentavos,
                        'payment_method_allowed' => $paymentMethods,
                        'payment_method_options' => [
                            'card' => ['request_three_d_secure' => 'any'],
                        ],
                        'currency' => $this->currency,
                        'description' => $description ?: 'Camp FreedivePH Booking Downpayment',
                        'statement_descriptor' => 'CAMP FREEDIVEPH',
                        'metadata' => $metadata,
                    ],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'payment_intent_id' => $data['data']['id'] ?? null,
                    'client_key' => $data['data']['attributes']['client_key'] ?? null,
                    'status' => $data['data']['attributes']['status'] ?? null,
                    'data' => $data,
                ];
            }

            Log::warning('PayMongo Create Payment Intent Error: ' . $response->body());
            return [
                'success' => false,
                'error' => $response->json() ?? $response->body(),
            ];
        } catch (Exception $e) {
            Log::error('PayMongo Create Payment Intent Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create a payment method (e.g. card).
     */
    public function createPaymentMethod(string $type, array $details, array $billing = []): array
    {
        if (empty($this->secretKey) || str_contains($this->secretKey, 'your_secret_key')) {
            return [
                'success' => true,
                'payment_method_id' => 'pm_test_' . bin2hex(random_bytes(10)),
                'type' => $type,
                'simulated' => true,
            ];
        }

        try {
            $response = $this->request('POST', "{$this->baseUrl}/payment_methods", [
                'data' => [
                    'attributes' => [
                        'type' => $type,
                        'details' => $details,
                        'billing' => $billing,
                    ],
                ],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'payment_method_id' => $data['data']['id'] ?? null,
                    'data' => $data,
                ];
            }

            Log::warning('PayMongo Create Payment Method Error: ' . $response->body());
            return [
                'success' => false,
                'error' => $response->json() ?? $response->body(),
            ];
        } catch (Exception $e) {
            Log::error('PayMongo Create Payment Method Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Attach a payment method to a payment intent.
     */
    public function attachPaymentIntent(string $paymentIntentId, string $paymentMethodId, ?string $returnUrl = null, ?string $clientKey = null): array
    {
        if (empty($this->secretKey) || str_contains($this->secretKey, 'your_secret_key')) {
            return [
                'success' => true,
                'status' => 'succeeded',
                'payment_id' => 'pay_test_' . bin2hex(random_bytes(10)),
                'simulated' => true,
            ];
        }

        try {
            $payload = [
                'data' => [
                    'attributes' => [
                        'payment_method' => $paymentMethodId,
                        'return_url' => $returnUrl ?: url('/'),
                    ],
                ],
            ];

            if ($clientKey) {
                $payload['data']['attributes']['client_key'] = $clientKey;
            }

            $response = $this->request('POST', "{$this->baseUrl}/payment_intents/{$paymentIntentId}/attach", $payload);

            if ($response->successful()) {
                $data = $response->json();
                $status = $data['data']['attributes']['status'] ?? 'succeeded';
                $payments = $data['data']['attributes']['payments'] ?? [];
                $paymentId = !empty($payments) ? ($payments[0]['id'] ?? null) : null;
                $nextAction = $data['data']['attributes']['next_action'] ?? null;

                return [
                    'success' => true,
                    'status' => $status,
                    'payment_id' => $paymentId,
                    'next_action' => $nextAction,
                    'data' => $data,
                ];
            }

            Log::warning('PayMongo Attach Payment Intent Error: ' . $response->body());
            return [
                'success' => false,
                'error' => $response->json() ?? $response->body(),
            ];
        } catch (Exception $e) {
            Log::error('PayMongo Attach Payment Intent Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send ONE refund to PayMongo (POST /v1/refunds). Never retried: if the answer is lost the refund may still have
     * been created, so a second POST could pay twice. No idempotency header is relied on (not established for this
     * account). Only a verifiable answer is believed:
     *  - 2xx whose data is a "ref_" refund for exactly this payment and amount (PHP): succeeded / provider_pending /
     *    failed from its status; anything else in a 2xx is "unknown";
     *  - 4xx (except 429): PayMongo rejected it, nothing was refunded: "failed" (incl. 404, "already refunded",
     *    "above maximum"; never mapped to success);
     *  - 429, 5xx, timeouts, connection errors: "unknown" (staff resolve it; it is never re-sent automatically).
     * No refund id is ever made up. Logs carry the outcome and error code only.
     *
     * @return array{outcome: string, provider_refund_id?: string, provider_status?: string, error_code?: string, http_status?: int}
     */
    public function submitRefund(string $paymentId, int $amountCentavos, string $notes): array
    {
        if ($this->isSimulated() || ! str_starts_with($paymentId, 'pay_') || $amountCentavos <= 0) {
            return ['outcome' => 'not_configured'];
        }

        try {
            $response = $this->request('POST', "{$this->baseUrl}/refunds", [
                'data' => ['attributes' => [
                    'amount' => $amountCentavos,
                    'payment_id' => $paymentId,
                    'reason' => 'requested_by_customer',
                    'notes' => $notes,
                ]],
            ], 20, ['max_retries' => 0]);
        } catch (\Throwable $e) {
            Log::warning('PayMongo refund: no answer; outcome unknown.', ['exception' => class_basename($e)]);

            return ['outcome' => 'unknown', 'error_code' => 'no_response'];
        }

        $status = $response->status();
        if ($status >= 400 && $status < 500 && $status !== 429) {
            $code = $response->json('errors.0.code');
            $errorCode = is_string($code) ? substr($code, 0, 64) : "http_{$status}";
            // A 404/409, or an error about the payment's refunds (e.g. "above the refundable amount": something may
            // already be refunded), proves nothing about this refund: unknown, kept reserved for reconciliation
            $about = strtolower($errorCode . ' ' . (string) $response->json('errors.0.detail'));
            if (in_array($status, [404, 409], true) || str_contains($about, 'refund')) {
                return ['outcome' => 'unknown', 'error_code' => $errorCode, 'http_status' => $status];
            }

            return ['outcome' => 'failed', 'error_code' => $errorCode, 'http_status' => $status];
        }
        if (! $response->successful()) {
            return ['outcome' => 'unknown', 'error_code' => "http_{$status}", 'http_status' => $status];
        }

        $data = $response->json('data');
        $attributes = is_array($data) ? ($data['attributes'] ?? null) : null;
        $verifiable = is_array($attributes)
            && is_string($data['id'] ?? null) && str_starts_with($data['id'], 'ref_')
            && ($attributes['amount'] ?? null) === $amountCentavos
            && ($attributes['payment_id'] ?? null) === $paymentId
            && (! isset($attributes['currency']) || $attributes['currency'] === 'PHP');
        if (! $verifiable) {
            return ['outcome' => 'unknown', 'error_code' => 'unverifiable_response', 'http_status' => $status];
        }

        $outcome = match ($attributes['status'] ?? null) {
            'succeeded' => 'succeeded',
            'pending', 'processing' => 'provider_pending',
            'failed' => 'failed',
            default => 'unknown',
        };

        return ['outcome' => $outcome, 'provider_refund_id' => $data['id'], 'provider_status' => (string) ($attributes['status'] ?? ''), 'http_status' => $status];
    }

    /**
     * Look one refund up (PayMongo "Retrieve a Refund", GET /refunds/{id}). Read-only and never cached: it is the
     * evidence for settling an unclear refund outcome. Only a 2xx answer for the same refund id counts as "found";
     * any error, 404 or timeout is "unavailable" and proves nothing either way.
     *
     * @return array{outcome: 'found'|'unavailable', error_code?: string,
     *     refund?: array{id: string, status: mixed, amount: mixed, currency: mixed, payment_id: mixed, notes: string, livemode: mixed}}
     */
    public function retrieveRefund(string $refundId): array
    {
        if ($this->isSimulated() || ! preg_match('/^ref_[A-Za-z0-9_]+$/', $refundId)) {
            return ['outcome' => 'unavailable', 'error_code' => 'not_configured'];
        }

        try {
            $response = $this->request('GET', "{$this->baseUrl}/refunds/{$refundId}", [], 15, ['max_retries' => 0]);
        } catch (\Throwable $e) {
            Log::warning('PayMongo refund lookup: no answer.', ['exception' => class_basename($e)]);

            return ['outcome' => 'unavailable', 'error_code' => 'no_response'];
        }
        if (! $response->successful()) {
            return ['outcome' => 'unavailable', 'error_code' => 'http_' . $response->status()];
        }

        $data = $response->json('data');
        $attributes = is_array($data) ? ($data['attributes'] ?? null) : null;
        if (! is_array($attributes) || ($data['id'] ?? null) !== $refundId) {
            return ['outcome' => 'unavailable', 'error_code' => 'unverifiable_response'];
        }

        return ['outcome' => 'found', 'refund' => [
            'id' => $refundId,
            'status' => $attributes['status'] ?? null,
            'amount' => $attributes['amount'] ?? null,
            'currency' => $attributes['currency'] ?? null,
            'payment_id' => $attributes['payment_id'] ?? null,
            'notes' => (string) ($attributes['notes'] ?? ''),
            'livemode' => $attributes['livemode'] ?? null,
        ]];
    }

    /**
     * Get a payment from PayMongo (cached).
     */
    public function getPayment(string $paymentId): ?array
    {
        if (empty($this->secretKey) || str_contains($this->secretKey, 'your_secret_key')) {
            return null;
        }

        $cacheTtl = (int) config('external_apis.paymongo.cache_ttl_seconds', 300);
        return Cache::remember("paymongo_payment_{$paymentId}", $cacheTtl, function () use ($paymentId) {
            try {
                $response = $this->request('GET', "{$this->baseUrl}/payments/{$paymentId}", [], 10);
                if ($response->successful()) {
                    return $response->json();
                }
            } catch (Exception $e) {
                Log::error('PayMongo Get Payment Exception: ' . $e->getMessage());
            }
            return null;
        });
    }

    /**
     * True when no real API key is set, so checkouts are simulated (local/testing only).
     */
    public function isSimulated(): bool
    {
        return empty($this->secretKey) || str_contains($this->secretKey, 'your_secret_key');
    }

    /**
     * The first paid payment on a checkout session, read fresh from PayMongo (never cached,
     * because the session may have been fetched before it was paid). Null if not paid.
     */
    public function paidPaymentForCheckout(string $checkoutId): ?array
    {
        if ($this->isSimulated()) {
            return null;
        }

        try {
            $response = $this->request('GET', "{$this->baseUrl}/checkout_sessions/{$checkoutId}", [], 10);
        } catch (Exception $e) {
            Log::error('PayMongo Get Checkout Session Exception: ' . $e->getMessage());
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        foreach ($response->json('data.attributes.payments') ?? [] as $payment) {
            if (($payment['attributes']['status'] ?? null) === 'paid') {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Read a checkout session straight from PayMongo for payment verification.
     *
     * Never cached (a cached "unpaid" answer must not hide a newer payment), no client-side
     * retries (callers retry), and TLS verification stays on. Uses the documented
     * GET /v1/checkout_sessions/{id}. Response bodies are never logged.
     *
     * @return array{outcome: 'ok'|'not_configured'|'unavailable'|'not_found'|'rejected'|'malformed', session?: array, http_status?: int}
     */
    public function fetchCheckoutSessionForVerification(string $checkoutId): array
    {
        if ($this->isSimulated()) {
            return ['outcome' => 'not_configured'];
        }

        try {
            $response = $this->apiClient->execute('paymongo', 'GET', 'https://api.paymongo.com/v1/checkout_sessions/' . rawurlencode($checkoutId), [
                'basic_auth' => [$this->secretKey, ''],
                'timeout' => 10,
                'max_retries' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PayMongo checkout session could not be read for verification.', ['exception' => class_basename($e)]);

            return ['outcome' => 'unavailable'];
        }

        $status = $response->status();
        if ($status === 404) {
            return ['outcome' => 'not_found', 'http_status' => 404];
        }
        if ($status === 429 || $status >= 500) {
            return ['outcome' => 'unavailable', 'http_status' => $status];
        }
        if (! $response->successful()) {
            return ['outcome' => 'rejected', 'http_status' => $status]; // e.g. 401: key or environment problem
        }

        $data = $response->json('data');
        if (! is_array($data) || ! is_string($data['id'] ?? null) || ! is_array($data['attributes'] ?? null)) {
            return ['outcome' => 'malformed', 'http_status' => $status];
        }

        return ['outcome' => 'ok', 'session' => $data, 'http_status' => $status];
    }

    /**
     * Ask PayMongo to close (expire) a checkout session, using the documented POST /v1/checkout_sessions/{id}/expire.
     * Verified in test mode (Batch 2C.2.2A) for unpaid v1- and v2-created sessions.
     *
     * The answer of this call is never proof on its own: callers must re-read the session (fresh GET) and decide
     * from its status and payments. TLS verification stays on, there is no automatic retry, and bodies are not logged.
     *
     * @return array{outcome: 'accepted'|'rejected'|'unavailable'|'not_configured', http_status?: int}
     *  - accepted:       PayMongo answered 2xx (still re-read the session)
     *  - rejected:       a 4xx, or an invalid session id (nothing sent); e.g. the session cannot be expired
     *  - unavailable:    timeout, connection error, 429 or 5xx: state unknown
     *  - not_configured: no usable secret key (simulated mode)
     */
    public function expireCheckoutSession(string $checkoutId): array
    {
        if ($this->isSimulated()) {
            return ['outcome' => 'not_configured'];
        }
        if (! preg_match('/^cs_[A-Za-z0-9_]{1,64}$/', $checkoutId)) { // nothing that could change the URL path
            return ['outcome' => 'rejected'];
        }

        try {
            $response = $this->apiClient->execute('paymongo', 'POST', 'https://api.paymongo.com/v1/checkout_sessions/' . $checkoutId . '/expire', [
                'basic_auth' => [$this->secretKey, ''],
                'timeout' => 10,
                'max_retries' => 0,
            ]);
        } catch (\Throwable $e) {
            Log::warning('PayMongo checkout session could not be expired.', ['exception' => class_basename($e)]);

            return ['outcome' => 'unavailable'];
        }

        $status = $response->status();
        if ($response->successful()) {
            return ['outcome' => 'accepted', 'http_status' => $status];
        }
        if ($status === 429 || $status >= 500) {
            return ['outcome' => 'unavailable', 'http_status' => $status];
        }

        return ['outcome' => 'rejected', 'http_status' => $status];
    }

    /**
     * Whether the configured secret key is a live key (sk_live_), a test key (sk_test_), or unknown (null).
     * Key prefixes as documented by PayMongo (API keys).
     */
    public function configuredLivemode(): ?bool
    {
        $key = (string) $this->secretKey;

        return match (true) {
            str_starts_with($key, 'sk_live_') => true,
            str_starts_with($key, 'sk_test_') => false,
            default => null,
        };
    }

    /**
     * Get a checkout session by id (cached).
     */
    public function getCheckoutSession(string $checkoutId): ?array
    {
        if (empty($this->secretKey) || str_contains($this->secretKey, 'your_secret_key')) {
            return null;
        }

        $cacheTtl = (int) config('external_apis.paymongo.cache_ttl_seconds', 300);
        return Cache::remember("paymongo_session_{$checkoutId}", $cacheTtl, function () use ($checkoutId) {
            try {
                $response = $this->request('GET', "{$this->baseUrl}/checkout_sessions/{$checkoutId}", [], 10);
                if ($response->successful()) {
                    return $response->json();
                }
            } catch (Exception $e) {
                Log::error('PayMongo Get Checkout Session Exception: ' . $e->getMessage());
            }
            return null;
        });
    }

    /**
     * Check the PayMongo webhook signature.
     *
     * @param string $payload raw JSON body
     * @param string $signatureHeader Paymongo-Signature header (t=timestamp,te=test_sig,li=live_sig)
     * @return bool
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        // No secret means we cannot tell PayMongo from anyone else, so reject
        if (empty($this->webhookSecret)) {
            Log::error('PayMongo webhook rejected: PAYMONGO_WEBHOOK_SIGNATURE_SECRET is not set.');
            return false;
        }

        // Live events are signed in "li", test events in "te"
        $livemode = (bool) (json_decode($payload, true)['data']['attributes']['livemode'] ?? false);
        $signatureKey = $livemode ? 'li' : 'te';

        $parts = explode(',', $signatureHeader);
        $timestamp = null;
        $signature = null;

        foreach ($parts as $part) {
            [$key, $value] = explode('=', trim($part), 2) + [null, null];
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === $signatureKey) {
                $signature = $value;
            }
        }

        if (!$timestamp || !$signature) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', "{$timestamp}.{$payload}", $this->webhookSecret);
        return hash_equals($expectedSignature, $signature);
    }
}
