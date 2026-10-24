<?php

namespace App\Services\Gateways;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Services\PayMongoService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

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

        $defaultSuccessUrl = self::signedReturnUrl('paymongo.success', $booking);
        $defaultCancelUrl = self::signedReturnUrl('paymongo.cancel', $booking);

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
     * Create a checkout session for a checkout intent (no booking exists yet).
     *
     * The amount is the intent's server-calculated downpayment. Identity goes in `billing`;
     * the metadata only links the payment back to the intent (no booking id, no health details).
     *
     * @param array{name: string, email: string, phone: string} $contact
     */
    public function createCheckoutSessionForIntent(CheckoutIntent $intent, array $contact): array
    {
        $classLabel = ucfirst((string) $intent->class_type);
        $dates = $intent->start_date->format('M d') . ' - ' . $intent->end_date->format('M d, Y');

        return $this->service->createCheckoutSession([
            [
                'name' => "Camp FreedivePH - {$classLabel} Downpayment",
                'amount' => (int) round((float) $intent->downpayment_amount * 100),
                'quantity' => 1,
                'currency' => $intent->currency,
                'description' => "Checkout {$intent->reference} ({$intent->pax_count} pax) - {$dates}",
            ],
        ], [
            'reference_number' => $intent->reference,
            'description' => "Downpayment for checkout {$intent->reference}",
            'success_url' => self::signedIntentUrl('checkout.return', $intent),
            'cancel_url' => self::signedIntentUrl('checkout.cancel', $intent),
            'billing' => [
                'name' => $contact['name'],
                'email' => $contact['email'],
                'phone' => $contact['phone'],
            ],
            'metadata' => [
                'checkout_intent_id' => (string) $intent->id,
                'checkout_reference' => $intent->reference,
                'student_count' => (string) $intent->pax_count,
                'class_type' => (string) $intent->class_type,
                'type' => 'downpayment',
            ],
        ]);
    }

    /**
     * Check the payment status with PayMongo.
     */
    public function verifyPayment(string $paymentId): ?array
    {
        return $this->service->getPayment($paymentId);
    }

    /**
     * Refund a PayMongo payment: one POST, never retried, outcome as PayMongoService::submitRefund describes.
     * Refunds for bookings go through RefundExecutor, which records the attempt first.
     */
    public function refundPayment(string $paymentId, float $amount, string $reason = 'requested_by_customer', ?string $notes = null): array
    {
        return $this->service->submitRefund($paymentId, (int) round($amount * 100), $notes ?: 'Camp FreedivePH refund');
    }

    /**
     * Signed, expiring return URL for PayMongo. The signature covers the path only
     * (checked with 'signed:relative'), so it still works behind the Azure proxy.
     */
    public static function signedReturnUrl(string $routeName, Booking $booking): string
    {
        $hours = (int) config('paymongo.return_url_ttl_hours', 48);

        return url(URL::temporarySignedRoute($routeName, now()->addHours($hours), ['booking' => $booking->id], false));
    }

    /** Same as signedReturnUrl() for a checkout intent, identified by its random reference (never its database id). */
    public static function signedIntentUrl(string $routeName, CheckoutIntent $intent): string
    {
        $hours = (int) config('paymongo.return_url_ttl_hours', 48);

        return url(URL::temporarySignedRoute($routeName, now()->addHours($hours), ['reference' => $intent->reference], false));
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
