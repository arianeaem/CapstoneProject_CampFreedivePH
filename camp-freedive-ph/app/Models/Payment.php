<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A payment for a booking (downpayment or balance).
 * Has the PayMongo ids and can be refunded, partly refunded or forfeited (no-show).
 *
 * @property int $id
 * @property int $booking_id
 * @property string $payment_method gcash, maya, card, bpi_bank_transfer, cash
 * @property string $transaction_id
 * @property string $paymongo_payment_id
 * @property float $amount total amount in PHP
 * @property float $fee_amount payment provider fee
 * @property float $net_amount amount we actually get
 * @property string $payment_type downpayment, balance_settlement, full
 * @property string $status completed, paid, refund_requested, refunded, partially_refunded, forfeited, failed
 */
class Payment extends Model
{
    use HasFactory;

    /**
     * Money actually received (online "paid", manual "completed"), including payments later refunded or forfeited
     * under the old single-status model. "pending" and "failed" are never receipts.
     */
    public const RECEIPT_STATUSES = ['paid', 'completed', 'refund_requested', 'refunded', 'partially_refunded', 'forfeited'];

    /** Receipts that can still be refunded (a forfeited payment was kept by decision). */
    public const REFUNDABLE_STATUSES = ['paid', 'completed', 'refund_requested', 'refunded', 'partially_refunded'];

    /** Old model: refunds recorded on the payment row itself (status + amount_refunded), before the refund ledger. */
    public const LEGACY_REFUNDED_STATUSES = ['refunded', 'partially_refunded'];

    protected $fillable = [
        'booking_id',
        'payment_method',
        'transaction_id',
        'paymongo_payment_id',
        'paymongo_resource_id',
        'amount',
        'fee_amount',
        'net_amount',
        'payment_type',
        'status',
        'paymongo_refund_id',
        'amount_refunded',
        'review_reason',
        'review_flagged_at',
        'review_resolved_at',
        'review_resolved_by',
        'refund_reason',
        'is_forfeited',
        'forfeited_amount',
        'forfeit_reason',
        'paid_at',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'amount_refunded' => 'decimal:2',
        'forfeited_amount' => 'decimal:2',
        'is_forfeited' => 'boolean',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
        'review_flagged_at' => 'datetime',
        'review_resolved_at' => 'datetime',
    ];

    /** Why a received payment waits for staff (Batch 3-II A); it stays a receipt meanwhile. */
    public const REVIEW_CAPACITY_UNAVAILABLE = 'capacity_unavailable';
    public const REVIEW_PAID_AFTER_CANCELLATION = 'paid_after_cancellation';
    public const REVIEW_AMOUNT_MISMATCH = 'amount_mismatch';
    public const REVIEW_PAID_AGAIN = 'paid_again';

    /** Payments flagged for staff and not yet resolved. */
    public function scopeAwaitingReview($query)
    {
        return $query->whereNotNull('review_reason')->whereNull('review_resolved_at');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(PaymentStatusLog::class)->orderBy('created_at', 'desc');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function latestRefundRequest(): HasOne
    {
        return $this->hasOne(RefundRequest::class)->latestOfMany();
    }

    public function refundAttempts(): HasMany
    {
        return $this->hasMany(RefundAttempt::class);
    }

    public function amountCentavos(): int
    {
        return (int) round((float) $this->amount * 100);
    }

    /**
     * Refund recorded under the old model (before the ledger). The ledger never writes these columns, so nothing is
     * counted twice. A "refunded" row without an amount is taken as fully refunded (safe for further refunds; the
     * reports flag it for reconciliation).
     */
    public function legacyRefundedCentavos(): int
    {
        if (! in_array($this->status, self::LEGACY_REFUNDED_STATUSES, true)) {
            return 0;
        }
        $recorded = (int) round((float) $this->amount_refunded * 100);

        return min($this->amountCentavos(), $recorded > 0 ? $recorded : $this->amountCentavos());
    }

    public function getFormattedPaymentMethodAttribute(): string
    {
        return match ($this->payment_method) {
            'gcash' => 'GCash',
            'bpi_bank_transfer' => 'BPI Bank Transfer',
            'card' => 'Credit / Debit Card',
            'maya' => 'Maya',
            'cash' => 'Cash at Camp',
            default => ucfirst(str_replace('_', ' ', $this->payment_method)),
        };
    }

    public function getPaymentStageLabelAttribute(): string
    {
        return match ($this->payment_type) {
            'downpayment' => 'Initial Downpayment',
            'balance_settlement' => 'Balance Settlement',
            'full' => 'Full Payment',
            default => ucfirst(str_replace('_', ' ', $this->payment_type)),
        };
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            'completed', 'paid' => [
                'label' => 'Paid & Verified',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'refund_requested' => [
                'label' => 'Refund Requested',
                'class' => 'bg-amber-50 text-amber-700',
            ],
            'refunded' => [
                'label' => 'Refunded',
                'class' => 'bg-blue-50 text-blue-700',
            ],
            'partially_refunded' => [
                'label' => 'Partially Refunded',
                'class' => 'bg-indigo-50 text-indigo-700',
            ],
            'forfeited' => [
                'label' => 'Forfeited',
                'class' => 'bg-purple-50 text-purple-700',
            ],
            'failed' => [
                'label' => 'Failed / Cancelled',
                'class' => 'bg-red-50 text-red-700',
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $this->status)),
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }
}
