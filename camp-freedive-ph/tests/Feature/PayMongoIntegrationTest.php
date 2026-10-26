<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Gateways\PayMongoGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PayMongoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsk_synthetic_test_secret';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests(); // never reach the real PayMongo API; tests stub what they need
        config(['paymongo.webhook_signature_secret' => self::WEBHOOK_SECRET]);
    }

    /** Send a webhook signed the way PayMongo signs it (t=..,te=..,li=..). */
    protected function postSignedWebhook(array $payload, bool $live = false, string $secret = self::WEBHOOK_SECRET)
    {
        // Paid events must match the configured key's mode (Batch 3-I); a synthetic test-mode key, never a real one
        config(['paymongo.secret_key' => 'sk_test_synthetic']);
        $body = json_encode($payload);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$body}", $secret);
        $header = $live ? "t={$t},te=,li={$sig}" : "t={$t},te={$sig},li=";

        return $this->call('POST', route('paymongo.webhook.api'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_PAYMONGO_SIGNATURE' => $header,
        ], $body);
    }

    protected function signedUrl(string $route, Booking $booking): string
    {
        return PayMongoGateway::signedReturnUrl($route, $booking);
    }

    protected function pendingPayment(Booking $booking, string $checkoutId = 'cs_test_session_123'): Payment
    {
        return Payment::create([
            'booking_id' => $booking->id,
            'payment_type' => 'downpayment',
            'status' => 'pending',
            'payment_method' => 'paymongo',
            'transaction_id' => 'TXN-' . strtoupper(substr(md5($checkoutId), 0, 8)),
            'paymongo_resource_id' => $checkoutId,
            'amount' => 3000.00,
            'fee_amount' => 0.00,
            'net_amount' => 3000.00,
        ]);
    }

    protected function createTestBatch(array $attributes = []): Batch
    {
        return Batch::create(array_merge([
            'batch_code' => 'BAT-' . date('Ymd') . '-TEST' . rand(100, 999),
            'start_date' => '2026-09-05',
            'end_date' => '2026-09-06',
            'lifecycle_status' => 'open_for_booking',
            'max_capacity' => 12,
        ], $attributes));
    }

    protected function createTestBooking(Batch $batch, array $attributes = []): Booking
    {
        return Booking::create(array_merge([
            'batch_id' => $batch->id,
            'booking_number' => 'CF-2026-TEST' . rand(100, 999),
            'pin' => '1234',
            'start_date' => $batch->start_date,
            'end_date' => $batch->end_date,
            'contact_name' => 'John Doe',
            'contact_email' => 'john@example.com',
            'contact_phone' => '09171234567',
            'class_type' => 'discovery',
            'student_count' => 2,
            'downpayment_amount' => 6000.00,
            'balance_amount' => 2500.00,
            'total_price' => 8500.00,
            'status' => 'pending_downpayment',
        ], $attributes));
    }

    public function test_gateway_interface_is_bound_and_resolvable(): void
    {
        $gateway = app(PaymentGatewayInterface::class);
        $this->assertInstanceOf(PaymentGatewayInterface::class, $gateway);
        $this->assertInstanceOf(PayMongoGateway::class, $gateway);
    }

    public function test_customer_can_initiate_paymongo_checkout_session(): void
    {
        $batch = $this->createTestBatch();
        $booking = $this->createTestBooking($batch, [
            'booking_number' => 'CF-2026-TEST01',
            'downpayment_amount' => 6000.00,
        ]);

        // The participant has opened this booking with its number + PIN
        $response = $this->withSession(['auth_booking_id' => $booking->id])
            ->post(route('paymongo.checkout', ['booking' => $booking->id]));

        // Should either redirect to PayMongo hosted checkout or return 302
        $response->assertStatus(302);

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'payment_type' => 'downpayment',
            'status' => 'pending',
            'amount' => 6000.00,
        ]);
    }

    public function test_customer_success_return_confirms_payment_and_booking(): void
    {
        $batch = $this->createTestBatch();
        $booking = $this->createTestBooking($batch, [
            'booking_number' => 'CF-2026-TEST02',
            'status' => 'pending_downpayment',
            'downpayment_amount' => 3000.00,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'payment_type' => 'downpayment',
            'status' => 'pending',
            'payment_method' => 'paymongo',
            'transaction_id' => 'TXN-TEST-1234',
            'paymongo_resource_id' => 'cs_test_session_123',
            'amount' => 3000.00,
            'fee_amount' => 0.00,
            'net_amount' => 3000.00,
        ]);

        // PayMongo reports the session paid (synthetic test key). A missing key is never treated as paid (Batch 3-II A).
        config(['paymongo.secret_key' => 'sk_test_synthetic']);
        Http::fake(['*/checkout_sessions/cs_test_session_123' => Http::response(['data' => ['id' => 'cs_test_session_123', 'attributes' => [
            'payments' => [['id' => 'pay_test_return_123', 'attributes' => ['status' => 'paid', 'amount' => 300000, 'currency' => 'PHP', 'livemode' => false]]]]]])]);

        $response = $this->get($this->signedUrl('paymongo.success', $booking));

        $response->assertRedirect(route('booking.create', ['confirmed' => $booking->booking_number]));
        $response->assertSessionHas('success');
        $response->assertSessionHas('auth_booking_id', $booking->id);

        $this->assertEquals('paid', $payment->fresh()->status);
        $this->assertEquals('confirmed', $booking->fresh()->status);
    }

    public function test_customer_cancel_return_shows_cancellation_notice(): void
    {
        $batch = $this->createTestBatch();
        $booking = $this->createTestBooking($batch, [
            'booking_number' => 'CF-2026-TEST03',
            'status' => 'pending_downpayment',
        ]);

        $response = $this->get($this->signedUrl('paymongo.cancel', $booking));

        $response->assertRedirect(route('manage.show', ['booking_number' => $booking->booking_number]));
        $response->assertSessionHas('error');
        $response->assertSessionHas('auth_booking_id', $booking->id);
    }

    public function test_paymongo_webhook_handles_paid_checkout_session_event(): void
    {
        $batch = $this->createTestBatch();
        $booking = $this->createTestBooking($batch, [
            'booking_number' => 'CF-2026-WBTEST',
            'status' => 'pending_downpayment',
            'downpayment_amount' => 3000.00,
        ]);

        $payload = [
            'data' => [
                'id' => 'evt_test_12345',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_test_9999',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'amount' => 300000, // 3000.00 PHP in centavos
                            'fee' => 7500, // 75.00 PHP in centavos
                            'payment_method_type' => 'gcash',
                            'metadata' => [
                                'booking_id' => $booking->id,
                                'booking_number' => $booking->booking_number,
                            ],
                            'payments' => [
                                [
                                    'id' => 'pay_test_webhook_paid_777',
                                    'attributes' => [
                                        'status' => 'paid',
                                        'amount' => 300000,
                                        'currency' => 'PHP',
                                        'livemode' => false,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postSignedWebhook($payload);

        $response->assertStatus(200);
        $response->assertJson(['received' => true]);

        $this->assertEquals('confirmed', $booking->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'status' => 'paid',
            'payment_method' => 'gcash',
            'paymongo_payment_id' => 'pay_test_webhook_paid_777',
        ]);
    }

    public function test_paymongo_webhook_deduplicates_repeated_retry_events(): void
    {
        $batch = $this->createTestBatch();
        $booking = $this->createTestBooking($batch, [
            'booking_number' => 'CF-2026-DEDUP',
            'status' => 'pending_downpayment',
            'downpayment_amount' => 3000.00,
        ]);

        $payload = [
            'data' => [
                'id' => 'evt_dedup_unique_999',
                'type' => 'event',
                'attributes' => [
                    'type' => 'checkout_session.payment.paid',
                    'data' => [
                        'id' => 'cs_dedup_session_888',
                        'type' => 'checkout_session',
                        'attributes' => [
                            'amount' => 300000,
                            'fee' => 7500,
                            'payment_method_type' => 'gcash',
                            'metadata' => [
                                'booking_id' => $booking->id,
                                'booking_number' => $booking->booking_number,
                            ],
                            'payments' => [
                                [
                                    'id' => 'pay_dedup_001',
                                    'attributes' => [
                                        'status' => 'paid',
                                        'amount' => 300000,
                                        'currency' => 'PHP',
                                        'livemode' => false,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // First delivery: processes successfully
        $firstResponse = $this->postSignedWebhook($payload);
        $firstResponse->assertStatus(200);
        $firstResponse->assertJson(['received' => true]);

        $this->assertEquals('confirmed', $booking->fresh()->status);
        $paymentCount = Payment::where('booking_id', $booking->id)->count();
        $this->assertEquals(1, $paymentCount);

        // Same event sent again: ignored
        $secondResponse = $this->postSignedWebhook($payload);
        $secondResponse->assertStatus(200);
        $secondResponse->assertJson([
            'received' => true,
            'status' => 'duplicate_ignored',
        ]);

        // Assert payment count was not duplicated
        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count());
    }

    // ---------------------------------------------------------------- security regression (audit F-01, F-02, F-03, F-04)

    public function test_checkout_requires_the_participants_booking_session(): void
    {
        $booking = $this->createTestBooking($this->createTestBatch(), ['booking_number' => 'CF-2026-SEC01']);

        $this->post(route('paymongo.checkout', ['booking' => $booking->id]))->assertRedirect(route('manage.index'));
        $this->assertDatabaseMissing('payments', ['booking_id' => $booking->id]);
    }

    public function test_unsigned_tampered_or_expired_return_urls_are_rejected(): void
    {
        $booking = $this->createTestBooking($this->createTestBatch(), ['booking_number' => 'CF-2026-SEC02']);
        $other = $this->createTestBooking($this->createTestBatch(), ['booking_number' => 'CF-2026-SEC03']);
        $this->pendingPayment($booking);

        $this->get(route('paymongo.success', ['booking' => $booking->id]))->assertForbidden();
        $this->get(route('paymongo.cancel', ['booking' => $booking->id]))->assertForbidden();

        // Signature for one booking reused on another booking's id
        $tampered = str_replace("/booking/{$booking->id}/", "/booking/{$other->id}/", $this->signedUrl('paymongo.cancel', $booking));
        $this->get($tampered)->assertForbidden()->assertSessionMissing('auth_booking_id');

        $expired = $this->signedUrl('paymongo.success', $booking);
        $this->travel(49)->hours();
        $this->get($expired)->assertForbidden();

        $this->assertSame('pending_downpayment', $booking->fresh()->status);
    }

    public function test_success_return_waits_for_payment_when_paymongo_reports_unpaid(): void
    {
        config(['paymongo.secret_key' => 'sk_test_synthetic']);
        Http::fake(['*/checkout_sessions/cs_unpaid_1' => Http::response([
            'data' => ['id' => 'cs_unpaid_1', 'attributes' => ['status' => 'active', 'payments' => []]],
        ])]);

        $booking = $this->createTestBooking($this->createTestBatch(), ['booking_number' => 'CF-2026-SEC04']);
        $payment = $this->pendingPayment($booking, 'cs_unpaid_1');

        $response = $this->get($this->signedUrl('paymongo.success', $booking));

        $response->assertRedirect(route('manage.show', ['booking_number' => $booking->booking_number]));
        $response->assertSessionHas('info');
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pending_downpayment', $booking->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/checkout_sessions/cs_unpaid_1'));
    }

    public function test_success_return_confirms_when_paymongo_reports_paid(): void
    {
        config(['paymongo.secret_key' => 'sk_test_synthetic']);
        Http::fake(['*/checkout_sessions/cs_paid_1' => Http::response([
            'data' => ['id' => 'cs_paid_1', 'attributes' => ['status' => 'active', 'payments' => [[
                'id' => 'pay_sandbox_paid_1',
                'attributes' => ['status' => 'paid', 'amount' => 300000, 'currency' => 'PHP', 'livemode' => false, 'fee' => 7500, 'source' => ['type' => 'gcash']],
            ]]]],
        ])]);

        $booking = $this->createTestBooking($this->createTestBatch(), ['booking_number' => 'CF-2026-SEC05']);
        $payment = $this->pendingPayment($booking, 'cs_paid_1');

        $this->get($this->signedUrl('paymongo.success', $booking))
            ->assertRedirect(route('booking.create', ['confirmed' => $booking->booking_number]));

        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('pay_sandbox_paid_1', $payment->paymongo_payment_id);
        $this->assertSame('gcash', $payment->payment_method);
        $this->assertSame('confirmed', $booking->fresh()->status);
    }

    public function test_confirmation_page_shows_pin_only_to_the_paying_session(): void
    {
        $booking = $this->createTestBooking($this->createTestBatch(), ['booking_number' => 'CF-2026-SEC06', 'pin' => '4826']);
        $this->pendingPayment($booking);

        $this->get($this->signedUrl('paymongo.success', $booking));
        $this->get(route('booking.create', ['confirmed' => $booking->booking_number]))->assertSee('4826');

        $this->flushSession();
        $this->get(route('booking.create', ['confirmed' => $booking->booking_number]))->assertDontSee('4826');
    }

    public function test_webhook_rejects_wrong_signature_and_accepts_live_signature(): void
    {
        $booking = $this->createTestBooking($this->createTestBatch(), ['booking_number' => 'CF-2026-SEC07']);
        $payload = ['data' => ['id' => 'evt_sec_live_1', 'attributes' => [
            'type' => 'payment.paid', 'livemode' => true,
            'data' => ['id' => 'pay_live_1', 'attributes' => ['amount' => 300000, 'metadata' => ['booking_id' => $booking->id]]],
        ]]];

        $this->postSignedWebhook($payload, true, 'wrong_secret')->assertStatus(400);
        // A test-mode signature must not pass for a live event
        $this->postSignedWebhook($payload, false)->assertStatus(400);
        $this->assertSame('pending_downpayment', $booking->fresh()->status);

        // A genuinely signed live event is accepted, but this app runs on a test key: the payment is not applied
        $this->postSignedWebhook($payload, true)->assertOk();
        $this->assertSame('pending_downpayment', $booking->fresh()->status);
        $this->assertDatabaseMissing('payments', ['paymongo_payment_id' => 'pay_live_1']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'PAYMONGO_WEBHOOK_PAYMENT_REJECTED']);
    }
}
