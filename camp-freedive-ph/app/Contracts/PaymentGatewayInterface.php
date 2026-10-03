<?php

namespace App\Contracts;

use App\Models\Booking;

interface PaymentGatewayInterface
{
    /**
     * Create a secure checkout session for booking downpayment or balance.
     *
     * @param Booking $booking
     * @param float $amount
     * @param array $options
     * @return array ['success' => bool, 'checkout_url' => string, 'checkout_id' => string, ...]
     */
    public function createCheckoutSession(Booking $booking, float $amount, array $options = []): array;

    /**
     * Verify payment status directly with the gateway provider.
     *
     * @param string $paymentId
     * @return array|null
     */
    public function verifyPayment(string $paymentId): ?array;

    /**
     * Execute a refund against a previous transaction.
     *
     * @param string $paymentId
     * @param float $amount
     * @param string $reason
     * @param string|null $notes
     * @return array ['success' => bool, 'refund_id' => string, ...]
     */
    public function refundPayment(string $paymentId, float $amount, string $reason = 'requested_by_customer', ?string $notes = null): array;

    /**
     * Process and verify an incoming webhook payload from the provider.
     *
     * @param string $payload
     * @param string $signatureHeader
     * @return array ['verified' => bool, 'event_type' => string, 'data' => array]
     */
    public function processWebhook(string $payload, string $signatureHeader): array;
}
