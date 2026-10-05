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
     * Send a request to PayMongo through ExternalApiClient.
     */
    protected function request(string $method, string $url, array $payload = [], int $timeout = 15)
    {
        return $this->apiClient->execute('paymongo', $method, $url, [
            'json' => $payload,
            'basic_auth' => [$this->secretKey ?? '', ''],
            'timeout' => $timeout,
            'without_verifying' => true,
        ]);
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

            // Use the v2 checkout_sessions endpoint
            $response = $this->request('POST', "https://api.paymongo.com/v2/checkout_sessions", $payload);

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
            
            // Try v1 instead
            $responseV1 = $this->request('POST', "https://api.paymongo.com/v1/checkout_sessions", $payload);
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
     * Refund a PayMongo payment.
     *
     * @param string $paymentId PayMongo payment id (pay_xxx)
     * @param float $amountInPesos
     * @param string $reason requested_by_customer, duplicate, fraudulent or others
     * @param string|null $notes
     * @return array
     */
    public function refund(string $paymentId, float $amountInPesos, string $reason = 'requested_by_customer', ?string $notes = null): array
    {
        $amountInCentavos = (int) round($amountInPesos * 100);

        // We have a secret key and it's a real PayMongo payment id
        if (!empty($this->secretKey) && !str_contains($this->secretKey, 'your_secret_key') && str_starts_with($paymentId, 'pay_')) {
            try {
                $response = $this->request('POST', "{$this->baseUrl}/refunds", [
                    'data' => [
                        'attributes' => [
                            'amount' => $amountInCentavos,
                            'payment_id' => $paymentId,
                            'reason' => $reason,
                            'notes' => $notes ?: 'Camp FreedivePH Admin Approved Refund',
                        ],
                    ],
                ]);

                if ($response->successful()) {
                    $responseData = $response->json();
                    return [
                        'success' => true,
                        'refund_id' => $responseData['data']['id'] ?? ('ref_' . bin2hex(random_bytes(10))),
                        'status' => $responseData['data']['attributes']['status'] ?? 'succeeded',
                        'data' => $responseData,
                    ];
                }

                $errorBody = $response->json();
                $errorDetail = $errorBody['errors'][0]['detail'] ?? 'PayMongo refund API returned an error.';
                $errorCode = $errorBody['errors'][0]['code'] ?? 'unknown_error';

                Log::warning("PayMongo Refund API Error for payment {$paymentId}: {$errorDetail} (Code: {$errorCode})");

                // Already refunded on PayMongo, get the existing refund
                if (str_contains(strtolower($errorDetail), 'refundable') || str_contains(strtolower($errorDetail), 'refunded') || $errorCode === 'parameter_above_maximum') {
                    $paymentData = $this->getPayment($paymentId);
                    $existingRefunds = $paymentData['data']['attributes']['refunds'] ?? [];
                    if (!empty($existingRefunds)) {
                        $latestRef = end($existingRefunds);
                        return [
                            'success' => true,
                            'refund_id' => $latestRef['id'] ?? ('ref_' . bin2hex(random_bytes(10))),
                            'status' => $latestRef['attributes']['status'] ?? 'succeeded',
                            'data' => $latestRef,
                            'already_refunded' => true,
                            'message' => 'Payment was already refunded on PayMongo.',
                        ];
                    }
                }

                // Payment not found on PayMongo (e.g. seeder data), do an offline refund
                if ($errorCode === 'resource_not_found' || str_contains(strtolower($errorDetail), 'not found')) {
                    $simulatedRefundId = 'ref_offline_' . strtolower(bin2hex(random_bytes(8)));
                    return [
                        'success' => true,
                        'refund_id' => $simulatedRefundId,
                        'status' => 'succeeded',
                        'simulated' => true,
                        'message' => 'Payment was recorded offline or in local seeder; refund recorded locally.',
                    ];
                }

                return [
                    'success' => false,
                    'error' => $errorDetail,
                ];

            } catch (Exception $e) {
                Log::error('PayMongo Refund Exception: ' . $e->getMessage());
                return [
                    'success' => false,
                    'error' => 'Connection error communicating with PayMongo: ' . $e->getMessage(),
                ];
            }
        }

        // Offline or test payment
        $simulatedRefundId = 'ref_offline_' . strtolower(bin2hex(random_bytes(8)));
        return [
            'success' => true,
            'refund_id' => $simulatedRefundId,
            'status' => 'succeeded',
            'simulated' => true,
            'message' => 'Refund processed for offline / test record.',
        ];
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
        if (empty($this->webhookSecret)) {
            return true; // no webhook secret set, skip the check
        }

        $parts = explode(',', $signatureHeader);
        $timestamp = null;
        $signature = null;

        foreach ($parts as $part) {
            [$key, $value] = explode('=', trim($part), 2) + [null, null];
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'te' || $key === 'li') {
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
