<?php

namespace App\Services\Checkout;

use App\Models\Booking;

/**
 * Outcome of BookingFinalizer::finalize().
 *
 *  - finalized:          this call created the official booking.
 *  - already_finalized:  the booking already existed for this same payment (repeat call); nothing new written.
 *  - paid_unallocated:   payment verified and kept, but no capacity: no booking (staff resolve it later).
 *  - payment_review:     the payment cannot be safely matched; no booking.
 *  - retryable:          nothing final happened (verification unavailable, lock busy/lost, booking creation
 *                        failed and rolled back); the intent is kept so a later attempt can finish.
 *  - not_paid:           PayMongo reports no successful payment; nothing changed.
 */
final class FinalizationResult
{
    public const FINALIZED = 'finalized';
    public const ALREADY_FINALIZED = 'already_finalized';
    public const PAID_UNALLOCATED = 'paid_unallocated';
    public const PAYMENT_REVIEW = 'payment_review';
    public const RETRYABLE = 'retryable';
    public const NOT_PAID = 'not_paid';

    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly ?Booking $booking = null,
    ) {
    }

    public static function finalized(Booking $booking): self
    {
        return new self(self::FINALIZED, 'finalized', $booking);
    }

    public static function alreadyFinalized(?Booking $booking): self
    {
        return new self(self::ALREADY_FINALIZED, 'already_finalized', $booking);
    }

    public static function paidUnallocated(string $reason): self
    {
        return new self(self::PAID_UNALLOCATED, $reason);
    }

    public static function paymentReview(string $reason): self
    {
        return new self(self::PAYMENT_REVIEW, $reason);
    }

    public static function retryable(string $reason): self
    {
        return new self(self::RETRYABLE, $reason);
    }

    public static function notPaid(string $reason): self
    {
        return new self(self::NOT_PAID, $reason);
    }

    /** True when an official booking exists for this checkout (created now or earlier). */
    public function hasBooking(): bool
    {
        return $this->booking !== null && in_array($this->outcome, [self::FINALIZED, self::ALREADY_FINALIZED], true);
    }
}
