<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner-review item for a verified refund choice on a payment exception (Batch 3-II B2.3, P5). The exception stays open
 * and its money held; no amount is set here. Immutable in B2 (database trigger); B3 adds the owner decision.
 */
class PaymentExceptionRefundReview extends Model
{
    public const AWAITING_OWNER_REVIEW = 'awaiting_owner_review';

    protected $fillable = [
        'payment_exception_id', 'choice_id', 'primary_payment_id', 'provider_payment_identity_id', 'checkout_intent_id', 'booking_id',
        'existing_booking_refund_id', 'received_centavos', 'status', 'routed_by', 'routed_at',
    ];

    protected function casts(): array
    {
        return ['received_centavos' => 'integer', 'routed_at' => 'datetime'];
    }

    public function paymentException(): BelongsTo
    {
        return $this->belongsTo(PaymentException::class);
    }

    public function choice(): BelongsTo
    {
        return $this->belongsTo(PaymentExceptionChoice::class, 'choice_id');
    }
}
