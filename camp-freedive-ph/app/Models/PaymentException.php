<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Verified money that could not simply be applied, one per primary receipt (Batch 3-II B1). Opened by
 * PaymentExceptionRecorder; B1 never resolves one (the B2 guest-choice workflow will). While unresolved, its receipts
 * are held: no ordinary booking refund spends them, and the booking cannot be placed, moved or restored around it.
 */
class PaymentException extends Model
{
    public const KIND_INTENT_UNALLOCATED = 'intent_unallocated';
    public const KIND_LEGACY_UNALLOCATED = 'legacy_unallocated';
    public const KIND_PAID_AFTER_CANCELLATION = 'paid_after_cancellation';
    public const KIND_AMOUNT_MISMATCH = 'amount_mismatch';
    public const KIND_PAID_AGAIN = 'paid_again';

    public const KINDS = [
        self::KIND_INTENT_UNALLOCATED => 'Paid checkout, no place was left',
        self::KIND_LEGACY_UNALLOCATED => 'Paid after the date filled up',
        self::KIND_PAID_AFTER_CANCELLATION => 'Paid after the booking was cancelled',
        self::KIND_AMOUNT_MISMATCH => 'Amount differs from the downpayment',
        self::KIND_PAID_AGAIN => 'Paid again (booking already settled)',
    ];

    public const OPEN = 'open';

    /** Statuses that still hold their receipts. */
    public const UNRESOLVED_STATUSES = [self::OPEN];

    protected $fillable = [
        'kind', 'status', 'primary_payment_id', 'checkout_intent_id', 'booking_id', 'amount_centavos', 'currency',
        'provider_payment_id', 'evidence', 'opened_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_centavos' => 'integer',
            'evidence' => 'array',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', self::UNRESOLVED_STATUSES);
    }

    public function primaryPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'primary_payment_id');
    }

    public function receipts(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'payment_exception_receipts');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function checkoutIntent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentExceptionEvent::class)->orderBy('id');
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? str_replace('_', ' ', $this->kind);
    }
}
