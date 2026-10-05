<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\Payment;
use App\Services\Gateways\PayMongoGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayMongoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

        $response = $this->post(route('paymongo.checkout', ['booking' => $booking->id]));

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

        $response = $this->get(route('paymongo.success', ['booking' => $booking->id]));

        $response->assertRedirect(route('booking.create', ['confirmed' => $booking->booking_number, 'pin' => $booking->pin]));
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

        $response = $this->get(route('paymongo.cancel', ['booking' => $booking->id]));

        $response->assertRedirect(route('manage.show', ['booking_number' => $booking->booking_number, 'pin' => $booking->pin]));
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
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson(route('paymongo.webhook.api'), $payload);

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
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        // First delivery: processes successfully
        $firstResponse = $this->postJson(route('paymongo.webhook.api'), $payload);
        $firstResponse->assertStatus(200);
        $firstResponse->assertJson(['received' => true]);

        $this->assertEquals('confirmed', $booking->fresh()->status);
        $paymentCount = Payment::where('booking_id', $booking->id)->count();
        $this->assertEquals(1, $paymentCount);

        // Same event sent again: ignored
        $secondResponse = $this->postJson(route('paymongo.webhook.api'), $payload);
        $secondResponse->assertStatus(200);
        $secondResponse->assertJson([
            'received' => true,
            'status' => 'duplicate_ignored',
        ]);

        // Assert payment count was not duplicated
        $this->assertEquals(1, Payment::where('booking_id', $booking->id)->count());
    }
}
