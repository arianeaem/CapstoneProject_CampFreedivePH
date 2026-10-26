<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guest's explicit choice for a payment exception (refund review or an alternative date), recorded by staff with
 * text evidence and verified by a different active owner or admin (Batch 3-II B2.1). Written only by
 * GuestChoiceService; the database enforces transitions, immutability and four-eyes verification.
 * A verified choice is consent on record, not an allocation, refund approval or permission to move money.
 */
class PaymentExceptionChoice extends Model
{
    public const REFUND_REVIEW = 'refund_review';
    public const ALTERNATIVE_DATE = 'alternative_date';
    public const CHOICES = [self::REFUND_REVIEW, self::ALTERNATIVE_DATE];

    public const RECORDED = 'recorded';
    public const VERIFIED = 'verified';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';
    public const SUPERSEDED = 'superseded';
    /** Terminal: the verified choice was acted on (refund review routed, or the date allocated); resolution_reference set once. */
    public const ACTED_ON = 'acted_on';
    /** Terminal: the verified date could not be allocated; a new date needs new consent and verification (P6). */
    public const ACTION_FAILED = 'action_failed';
    /** At most one per exception (partial unique index). */
    public const ACTIONABLE = [self::RECORDED, self::VERIFIED];

    public const CHANNELS = ['email', 'phone', 'message', 'in_person'];
    public const MATCH_METHODS = ['email_address', 'phone_number', 'booking_reference', 'in_person'];
    public const MATCHED = 'matched';
    public const NOT_MATCHED = 'not_matched';

    /** Exception kinds that accept guest choices in B2.1. */
    public const SUPPORTED_KINDS = [PaymentException::KIND_INTENT_UNALLOCATED, PaymentException::KIND_LEGACY_UNALLOCATED];

    protected $fillable = [
        'payment_exception_id', 'choice', 'status', 'requested_start_date', 'requested_end_date', 'original_total_centavos',
        'original_downpayment_centavos', 'received_centavos', 'price_snapshot_source', 'price_snapshot_source_id', 'evidence_channel',
        'evidence_at', 'evidence_reference', 'evidence_summary', 'contact_match_method', 'contact_match_outcome', 'recorded_by',
        'recorded_at', 'verified_by', 'verified_at', 'verification_note', 'closed_by', 'closed_at', 'closed_reason', 'supersedes_choice_id',
        'lock_version', 'acted_by', 'acted_at', 'resolution_reference',
    ];

    protected function casts(): array
    {
        return [
            'requested_start_date' => 'date:Y-m-d',
            'requested_end_date' => 'date:Y-m-d',
            'original_total_centavos' => 'integer',
            'original_downpayment_centavos' => 'integer',
            'received_centavos' => 'integer',
            'evidence_at' => 'datetime',
            'recorded_at' => 'datetime',
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
            'acted_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function scopeActionable(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIONABLE);
    }

    public function paymentException(): BelongsTo
    {
        return $this->belongsTo(PaymentException::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_choice_id');
    }
}
