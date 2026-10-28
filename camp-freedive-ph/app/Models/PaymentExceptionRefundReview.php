<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Owner-review item for a verified refund choice on a payment exception (Batch 3-II B2.3, P5). The exception stays open
 * and its money held. B3: an owner or admin approves the full verified entitlement (approved_awaiting_payout: reserved,
 * nothing paid, nothing resolved) or rejects an invalid request with a reason. The database allows only those two
 * transitions, once each review; payout and "refunded" come with provider execution.
 */
class PaymentExceptionRefundReview extends Model
{
    public const AWAITING_OWNER_REVIEW = 'awaiting_owner_review';
    public const APPROVED_AWAITING_PAYOUT = 'approved_awaiting_payout';
    public const REJECTED_INVALID = 'rejected_invalid';

    protected $fillable = [
        'payment_exception_id', 'choice_id', 'primary_payment_id', 'provider_payment_identity_id', 'checkout_intent_id', 'booking_id',
        'existing_booking_refund_id', 'received_centavos', 'status', 'routed_by', 'routed_at',
        'approved_centavos', 'approved_currency', 'approved_by', 'approver_role', 'approved_at', 'approval_note',
        'rejected_by', 'rejected_at', 'rejection_reason', 'lock_version',
    ];

    protected function casts(): array
    {
        return ['received_centavos' => 'integer', 'routed_at' => 'datetime', 'approved_centavos' => 'integer', 'approved_at' => 'datetime',
            'rejected_at' => 'datetime', 'lock_version' => 'integer'];
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
