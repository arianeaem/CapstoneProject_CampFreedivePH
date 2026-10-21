<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A checkout attempt before payment. It is NOT an official booking: no booking number, PIN,
 * participants, batch or price-adjustment rows exist until the payment is verified and the
 * intent is finalized. Personal and health details are only stored in the encrypted payload.
 */
class CheckoutIntent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_FAILED = 'failed';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_PAID = 'paid';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_PAID_UNALLOCATED = 'paid_unallocated';
    public const STATUS_PAYMENT_REVIEW = 'payment_review';

    /**
     * Statuses whose live hold takes capacity: unpaid checkouts, verified payments awaiting finalization, and
     * checkouts under payment review (the customer may have paid), each only until its own hold_expires_at.
     */
    public const HOLDING_STATUSES = [self::STATUS_PENDING, self::STATUS_PAID, self::STATUS_PAYMENT_REVIEW];

    public const HOLD_MINUTES = 15;

    /** A payment verified while its hold is live keeps the hold at most this long for finalization (never indefinitely). */
    public const FINALIZATION_GRACE_MINUTES = 10;

    /** Unambiguous characters for references (no 0/O, 1/I/L). */
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'reference', 'status', 'source',
        'start_date', 'end_date', 'pax_count', 'class_type', 'pickup_option',
        'currency', 'total_amount', 'downpayment_amount',
        'payload', 'contact_email_hash',
        'hold_expires_at', 'checkout_expires_at',
        'paymongo_checkout_session_id', 'paymongo_payment_id',
        'paid_amount', 'paid_currency', 'fee_amount', 'payment_method', 'paid_at', 'verification', 'last_event_id',
        'booking_id', 'batch_id',
        'finalized_at', 'failed_at', 'failure_reason', 'expired_at',
        'unallocated_reason', 'resolution_status', 'resolution_note', 'resolved_by', 'resolved_at',
        'refund_reference', 'refund_status', 'refunded_amount', 'refunded_at',
        'staff_notified_at', 'customer_notified_at', 'retention_review_at',
    ];

    /** Never serialize personal details by accident (e.g. toArray() in a log or JSON response). */
    protected $hidden = ['payload', 'contact_email_hash'];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'verification' => 'array',
            'start_date' => 'date',
            'end_date' => 'date',
            'pax_count' => 'integer',
            'total_amount' => 'decimal:2',
            'downpayment_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'hold_expires_at' => 'datetime',
            'checkout_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'finalized_at' => 'datetime',
            'failed_at' => 'datetime',
            'expired_at' => 'datetime',
            'resolved_at' => 'datetime',
            'refunded_at' => 'datetime',
            'staff_notified_at' => 'datetime',
            'customer_notified_at' => 'datetime',
            'retention_review_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** "CHK-" + 12 cryptographically random characters. Uniqueness is enforced by the database. */
    public static function newReference(): string
    {
        $max = strlen(self::REFERENCE_ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= self::REFERENCE_ALPHABET[random_int(0, $max)];
        }

        return 'CHK-' . $code;
    }

    public static function emailHash(?string $email): ?string
    {
        return $email ? hash('sha256', strtolower(trim($email))) : null;
    }

    /**
     * Intents that take capacity: pending, paid or payment_review, with a hold that has not run out yet.
     * Expired holds, finalized (the booking counts instead), paid_unallocated, failed and expired
     * intents never do.
     */
    public function scopeHoldingCapacity(Builder $query): Builder
    {
        return $query->whereIn('status', self::HOLDING_STATUSES)->where('hold_expires_at', '>', now());
    }

    public function hasLiveHold(): bool
    {
        return in_array($this->status, self::HOLDING_STATUSES, true)
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isFuture();
    }
}
