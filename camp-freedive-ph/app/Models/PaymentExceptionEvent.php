<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history of a payment exception (database trigger forbids changes). */
class PaymentExceptionEvent extends Model
{
    public const UPDATED_AT = null;

    public const OPENED = 'opened';
    public const BATCH_CANCELLED = 'batch_cancelled_by_camp';
    public const BOOKING_CANCELLED = 'booking_cancelled_by_camp';
    public const BATCH_RESCHEDULED = 'batch_rescheduled_by_camp';

    /** Guest choice history (Batch 3-II B2.1); details carry the choice id, never evidence text. */
    public const CHOICE_RECORDED = 'guest_choice_recorded';
    public const CHOICE_VERIFIED = 'guest_choice_verified';
    public const CHOICE_REJECTED = 'guest_choice_rejected';
    public const CHOICE_WITHDRAWN = 'guest_choice_withdrawn';
    public const CHOICE_SUPERSEDED = 'guest_choice_superseded';
    public const CHOICE_ACTED_ON = 'guest_choice_acted_on';
    public const CHOICE_ACTION_FAILED = 'guest_choice_action_failed';
    public const REFUND_REVIEW_ROUTED = 'refund_review_routed';
    public const REFUND_APPROVED = 'refund_approved';
    public const REFUND_REJECTED = 'refund_rejected';
    public const ALLOCATED = 'allocated';
    public const ALLOCATION_FAILED = 'allocation_failed';
    public const ALLOCATION_BLOCKED = 'allocation_blocked';
    public const EVIDENCE_VIEWED = 'evidence_viewed';

    protected $fillable = ['payment_exception_id', 'type', 'actor_id', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
