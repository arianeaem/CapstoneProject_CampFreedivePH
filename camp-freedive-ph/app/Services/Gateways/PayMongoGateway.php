<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Booking;
use App\Services\PayMongoService;
use Illuminate\Support\Facades\Log;

class PayMongoGateway implements PaymentGatewayInterface
{
    protected PayMongoService $service;

    public function __construct(PayMongoService $service)
    {
        $this->service = $service;
    }

    /**
     * Create a checkout session for a booking.
     */
    public function createCheckoutSession(Booking $booking, float $amount, array $options = []): array
    {
        $amountInCentavos = (int) round($amount * 100);
        $classLabel = $booking->formatted_class_type ?? ucfirst($booking->class_type ?? 'Discovery');
        $studentCount = $booking->participants()->count() ?: 1;
        $batchDates = ($booking->start_date && $booking->end_date) 
            ? ($booking->start_date->format('M d') . ' - ' . $booking->end_date->format('M d, Y'))
            : '2D1N Freediving Camp';

        $lineItems = [
            [
                'name' => "Camp FreedivePH - {$classLabel} Downpayment",
                'amount' => $amountInCentavos,
                'quantity' => 1,
                'currency' => config('paymongo.currency', 'PHP'),
                'description' => "Booking #{$booking->booking_number} ({$studentCount} pax) - {$batchDates}",
            ],
        ];

        $defaultSuccessUrl = route('paymongo.success', ['booking' => $booking->id]);
        $defaultCancelUrl = route('paymongo.cancel', ['booking' => $booking->id]);

        $sessionOptions = [
            'reference_number' => $booking->booking_number,
            'description' => "Downpayment for Booking #{$booking->booking_number}",
            'success_url' => $options['success_url'] ?? $defaultSuccessUrl,
            'cancel_url' => $options['cancel_url'] ?? $defaultCancelUrl,
            'payment_method_types' => $options['payment_method_types'] ?? config('paymongo.payment_method_types', ['qrph', 'gcash', 'paymaya', 'card', 'grab_pay']),
            'billing' => [
                'name' => $booking->contact_name,
                'email' => $booking->contact_email,
                'phone' => $booking->contact_phone,
            ],
            'metadata' => [
                'booking_id' => (string) $booking->id,
                'booking_number' => $booking->booking_number,
                'customer_name' => $booking->contact_name,
                'customer_email' => $booking->contact_email,
                'customer_phone' => $booking->contact_phone,
                'student_count' => (string) $studentCount,
                'class_type' => $booking->class_type,
                'type' => 'downpayment',
            ],
        ];

        return $this->service->createCheckoutSession($lineItems, $sessionOptions);
    }

    /**
     * Check the payment status with PayMongo.
     */
    public function verifyPayment(string $paymentId): ?array
    {
        return $this->service->getPayment($paymentId);
    }

    /**
     * Refund a PayMongo payment.
     */
    public function refundPayment(string $paymentId, float $amount, string $reason = 'requested_by_customer', ?string $notes = null): array
    {
        return $this->service->refund($paymentId, $amount, $reason, $notes);
    }

    /**
     * Check and read a PayMongo webhook.
     */
    public function processWebhook(string $payload, string $signatureHeader): array
    {
        $isValid = $this->service->verifyWebhookSignature($payload, $signatureHeader);

        if (!$isValid) {
            Log::warning('PayMongo Webhook Rejected: Invalid signature header.');
            return [
                'verified' => false,
                'event_type' => null,
                'data' => null,
            ];
        }

        $decoded = json_decode($payload, true);
        $eventType = $decoded['data']['attributes']['type'] ?? null;
        $eventData = $decoded['data']['attributes']['data'] ?? [];

        return [
            'verified' => true,
            'event_type' => $eventType,
            'data' => $eventData,
            'raw' => $decoded,
        ];
    }
}
