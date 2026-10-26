<?php

namespace App\Services\Payment;

use App\Models\ProviderPaymentIdentity;
use App\Services\Checkout\VerifiedPayment;

/** The provider-verified facts of one payment, as ReceiptRecorder compares them. Integer centavos, no floats. */
final class ProviderPayment
{
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly int $amountCentavos,
        public readonly string $currency,
        public readonly bool $livemode,
    ) {
    }

    public static function fromVerified(VerifiedPayment $payment): self
    {
        return new self(ProviderPaymentIdentity::PROVIDER_PAYMONGO, $payment->paymentId, $payment->amountCentavos, $payment->currency, $payment->livemode);
    }

    /** What a refused event claimed, for the conflict record (no personal data). */
    public function facts(): array
    {
        return ['amount_centavos' => $this->amountCentavos, 'currency' => $this->currency, 'livemode' => $this->livemode];
    }
}
