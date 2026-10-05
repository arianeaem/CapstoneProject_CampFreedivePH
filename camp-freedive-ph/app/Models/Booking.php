<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Booking Model representing a guest or group freediving reservation.
 *
 * Domain & Financial Context:
 * - Requires a flat ₱3,000 per participant downpayment upon reservation to guarantee slot allocation.
 * - Supports self-service tracking, rescheduling, and cancellation requests via a unique booking number
 *   and 4-digit PIN authentication.
 * - Manages financial aggregates including base course fees, optional carpool transport, optional boat dive
 *   sessions, and Mabini LGU environmental fees.
 *
 * @property int $id
 * @property string $booking_number e.g. BK-2026-XXXX
 * @property string $pin 4-digit PIN for guest portal access
 * @property string $class_type discovery, fundive, refinement
 * @property bool $is_certified_diver
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property float $subtotal
 * @property float $total_amount
 * @property float $downpayment_amount
 * @property float $balance_amount Outstanding balance payable at camp
 * @property string $status confirmed, completed, rescheduled, reschedule_requested, cancellation_requested, cancelled_by_camp, cancelled_by_guest, no_show
 */
class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'booking_number',
        'pin',
        'class_type',
        'is_certified_diver',
        'start_date',
        'end_date',
        'pickup_option',
        'pickup_location',
        'carpool_fee',
        'boat_dive',
        'boat_dive_fee',
        'lgu_fee',
        'environmental_fee',
        'subtotal',
        'total_amount',
        'downpayment_amount',
        'balance_amount',
        'contact_name',
        'contact_email',
        'contact_phone',
        'contact_facebook',
        'status',
        'created_by',
    ];

    protected $casts = [
        'is_certified_diver' => 'boolean',
        'boat_dive' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
        'carpool_fee' => 'decimal:2',
        'boat_dive_fee' => 'decimal:2',
        'lgu_fee' => 'decimal:2',
        'environmental_fee' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'downpayment_amount' => 'decimal:2',
        'balance_amount' => 'decimal:2',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(BookingParticipant::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function rescheduleRequests(): HasMany
    {
        return $this->hasMany(RescheduleRequest::class);
    }

    public function priceAdjustments(): HasMany
    {
        return $this->hasMany(BookingPriceAdjustment::class)->orderBy('id', 'asc');
    }

    public function cancellationRequests(): HasMany
    {
        return $this->hasMany(CancellationRequest::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(BookingStatusLog::class)->orderBy('created_at', 'desc');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActiveConfirmed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            'confirmed',
            'rescheduled',
            'reschedule_requested',
            'cancellation_requested',
            'completed'
        ]);
    }

    public function getFormattedClassTypeAttribute(): string
    {
        return match ($this->class_type) {
            'discovery' => 'Discovery (Beginner Class)',
            'fundive' => 'Fundive ' . ($this->is_certified_diver ? '(Certified Diver)' : '(Non-Certified Diver)'),
            'refinement' => 'Refinement (Practice Dive)',
            default => ucfirst($this->class_type),
        };
    }

    public function getPaymentStatusBadgeAttribute(): array
    {
        // Use eager-loaded payments when available (list pages) instead of two queries per booking
        if ($this->relationLoaded('payments')) {
            $hasPayment = $this->payments->whereIn('status', ['completed', 'paid'])->isNotEmpty();
            $isRefunded = $this->payments->whereIn('status', ['refunded', 'refund_requested'])->isNotEmpty();
        } else {
            $hasPayment = $this->payments()->whereIn('status', ['completed', 'paid'])->exists();
            $isRefunded = $this->payments()->whereIn('status', ['refunded', 'refund_requested'])->exists();
        }

        if ($this->status === 'no_show') {
            return [
                'label' => 'Forfeited (No-Show)',
                'class' => 'bg-purple-100 text-purple-800',
            ];
        }

        if ($isRefunded || in_array($this->status, ['cancelled_by_camp', 'cancelled_by_guest'])) {
            return [
                'label' => 'Refund Pending / Processed',
                'class' => 'bg-red-50 text-red-700',
            ];
        }

        if ($hasPayment) {
            return [
                'label' => 'Downpayment Paid',
                'class' => 'bg-emerald-50 text-emerald-700',
            ];
        }

        return [
            'label' => 'Unpaid',
            'class' => 'bg-gray-100 text-gray-700',
        ];
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            'confirmed' => [
                'label' => 'Confirmed',
                'bg' => 'bg-emerald-50 text-emerald-700',
                'class' => 'bg-emerald-50 text-emerald-700',
                'color' => '#34C759',
            ],
            'completed' => [
                'label' => 'Completed',
                'bg' => 'bg-gray-100 text-gray-700',
                'class' => 'bg-gray-100 text-gray-700',
                'color' => '#6E6E73',
            ],
            'rescheduled' => [
                'label' => 'Rescheduled',
                'bg' => 'bg-amber-50 text-amber-700',
                'class' => 'bg-amber-50 text-amber-700',
                'color' => '#FF8D28',
            ],
            'reschedule_requested' => [
                'label' => 'Reschedule Requested',
                'bg' => 'bg-yellow-50 text-yellow-800',
                'class' => 'bg-yellow-50 text-yellow-800',
                'color' => '#B45309',
            ],
            'cancellation_requested' => [
                'label' => 'Cancellation Requested',
                'bg' => 'bg-rose-50 text-rose-700',
                'class' => 'bg-rose-50 text-rose-700',
                'color' => '#E11D48',
            ],
            'cancelled_by_camp' => [
                'label' => 'Cancelled by Camp',
                'bg' => 'bg-red-50 text-red-700',
                'class' => 'bg-red-50 text-red-700',
                'color' => '#FF3B3C',
            ],
            'cancelled_by_guest' => [
                'label' => 'Cancelled by Guest',
                'bg' => 'bg-rose-100 text-rose-800',
                'class' => 'bg-rose-100 text-rose-800',
                'color' => '#BE123C',
            ],
            'no_show' => [
                'label' => 'No-Show (Forfeited)',
                'bg' => 'bg-purple-50 text-purple-700',
                'class' => 'bg-purple-50 text-purple-700',
                'color' => '#7E22CE',
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $this->status)),
                'bg' => 'bg-gray-50 text-gray-700',
                'class' => 'bg-gray-50 text-gray-700',
                'color' => '#6E6E73',
            ],
        };
    }

    public function getStudentCountAttribute(): int
    {
        return $this->participants()->count() ?: 1;
    }

    public function getClassTypeLabelAttribute(): string
    {
        return $this->formatted_class_type;
    }

    /**
     * Standardized date range format:
     * - Same year: Oct 12 - Oct 13, 2026
     * - Cross year: Dec 31, 2026 - Jan 1, 2027
     * - Single date: Oct 12, 2026
     */
    public function getFormattedDateRangeAttribute(): string
    {
        if (!$this->start_date) {
            return 'N/A';
        }

        if (!$this->end_date || $this->start_date->eq($this->end_date)) {
            return $this->start_date->format('M d, Y');
        }

        if ($this->start_date->year === $this->end_date->year) {
            return $this->start_date->format('M d') . ' - ' . $this->end_date->format('M d, Y');
        }

        return $this->start_date->format('M d, Y') . ' - ' . $this->end_date->format('M d, Y');
    }

    public function getBatchDatesFormattedAttribute(): string
    {
        if ($this->start_date) {
            return $this->formatted_date_range;
        }
        return '2D1N Freediving Camp';
    }
}
