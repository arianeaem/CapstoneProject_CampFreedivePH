<?php

namespace App\Services\Checkout;

use Carbon\CarbonImmutable;

/**
 * A PayMongo payment that passed every verification check for one checkout intent.
 * Holds only what finalization needs; no raw provider response, no card data.
 */
final class VerifiedPayment
{
    public function __construct(
        public readonly int $checkoutIntentId,
        public readonly string $checkoutSessionId,
        public readonly string $paymentId,
        public readonly int $amountCentavos,
        public readonly string $currency,
        public readonly string $status,
        public readonly bool $livemode,
        public readonly CarbonImmutable $verifiedAt,
        public readonly bool $sessionRecovered,
        public readonly ?CarbonImmutable $paidAt = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paymentIntentId = null,
        public readonly ?int $feeCentavos = null,
    ) {
    }

    public function amount(): float
    {
        return $this->amountCentavos / 100;
    }

    /** Non-sensitive evidence, safe to store on the intent (`verification`) for later audit. */
    public function evidence(string $source): array
    {
        return [
            'source' => $source,
            'verified_at' => $this->verifiedAt->toIso8601String(),
            'checkout_session_id' => $this->checkoutSessionId,
            'session_recovered' => $this->sessionRecovered,
            'payment_id' => $this->paymentId,
            'payment_intent_id' => $this->paymentIntentId,
            'status' => $this->status,
            'amount_centavos' => $this->amountCentavos,
            'currency' => $this->currency,
            'fee_centavos' => $this->feeCentavos,
            'paid_at' => $this->paidAt?->toIso8601String(),
            'payment_method' => $this->paymentMethod,
            'livemode' => $this->livemode,
        ];
    }
}
