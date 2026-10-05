<?php

namespace App\Contracts;

use App\Models\Booking;

interface PaymentGatewayInterface
{
    /**
     * Create a checkout session for the downpayment or balance.
     *
     * @param Booking $booking
     * @param float $amount
     * @param array $options
     * @return array ['success' => bool, 'checkout_url' => string, 'checkout_id' => string, ...]
     */
    public function createCheckoutSession(Booking $booking, float $amount, array $options = []): array;

    /**
     * Check the payment status with the payment provider.
     *
     * @param string $paymentId
     * @return array|null
     */
    public function verifyPayment(string $paymentId): ?array;

    /**
     * Refund a payment.
     *
     * @param string $paymentId
     * @param float $amount
     * @param string $reason
     * @param string|null $notes
     * @return array ['success' => bool, 'refund_id' => string, ...]
     */
    public function refundPayment(string $paymentId, float $amount, string $reason = 'requested_by_customer', ?string $notes = null): array;

    /**
     * Check and read a webhook from the provider.
     *
     * @param string $payload
     * @param string $signatureHeader
     * @return array ['verified' => bool, 'event_type' => string, 'data' => array]
     */
    public function processWebhook(string $payload, string $signatureHeader): array;
}
