<?php

namespace App\Services\Checkout;

/**
 * Outcome of verifying a checkout intent's payment with PayMongo.
 *
 *  - verified:     every check passed; `payment` is set.
 *  - not_paid:     PayMongo answered clearly and there is no successful payment (yet).
 *  - needs_review: a definite mismatch or ambiguous ownership; never turn this into a booking automatically.
 *  - retryable:    PayMongo unavailable, misconfigured or incomplete; try again later (NOT "unpaid").
 *
 * `details` only holds non-sensitive context (ids, expected/actual amounts, reason codes).
 */
final class PaymentVerificationResult
{
    public const VERIFIED = 'verified';
    public const NOT_PAID = 'not_paid';
    public const NEEDS_REVIEW = 'needs_review';
    public const RETRYABLE = 'retryable';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?VerifiedPayment $payment = null,
        public readonly array $details = [],
    ) {
    }

    public static function verified(VerifiedPayment $payment): self
    {
        return new self(self::VERIFIED, 'verified', $payment);
    }

    public static function notPaid(string $reason, array $details = []): self
    {
        return new self(self::NOT_PAID, $reason, null, $details);
    }

    public static function needsReview(string $reason, array $details = []): self
    {
        return new self(self::NEEDS_REVIEW, $reason, null, $details);
    }

    public static function retryable(string $reason, array $details = []): self
    {
        return new self(self::RETRYABLE, $reason, null, $details);
    }

    public function isVerified(): bool
    {
        return $this->outcome === self::VERIFIED;
    }

    public function requiresReview(): bool
    {
        return $this->outcome === self::NEEDS_REVIEW;
    }

    public function isRetryable(): bool
    {
        return $this->outcome === self::RETRYABLE;
    }

    public function isNotPaid(): bool
    {
        return $this->outcome === self::NOT_PAID;
    }
}
