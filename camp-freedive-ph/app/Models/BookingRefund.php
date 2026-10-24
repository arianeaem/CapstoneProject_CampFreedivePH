<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One refund case for a cancelled booking (see RefundCaseService). Separate dimensions:
 *  - why (reason_category, confirmed by staff) and whether the activity had started (staff-confirmed, never inferred);
 *  - the guest's choice (refund or move to another date);
 *  - what policy allows (computed by RefundLedger) versus what staff approved (approved_centavos);
 *  - what was actually paid back (refund_attempts, only provider-confirmed or documented offline payouts count).
 * Approving never moves money; RefundExecutor does, and only for an approved case.
 */
class BookingRefund extends Model
{
    public const AWAITING_GUEST_CHOICE = 'awaiting_guest_choice';
    public const AWAITING_STAFF_REVIEW = 'awaiting_staff_review';
    public const APPROVED = 'approved';
    public const CLOSED_NO_REFUND = 'closed_no_refund';
    public const COMPLETED = 'completed';

    public const OPEN_STATUSES = [self::AWAITING_GUEST_CHOICE, self::AWAITING_STAFF_REVIEW, self::APPROVED];

    public const REASON_GUEST_VOLUNTARY = 'guest_voluntary';
    public const REASON_WEATHER_PRE_DIVE = 'weather_pre_dive';
    public const REASON_WEATHER_IN_PROGRESS = 'weather_in_progress';
    public const REASON_CAMP_OPERATIONAL = 'camp_operational';
    public const REASON_UNKNOWN = 'unknown';

    public const REASONS = [
        self::REASON_GUEST_VOLUNTARY => 'Guest cancelled (voluntary)',
        self::REASON_WEATHER_PRE_DIVE => 'Weather, before diving started',
        self::REASON_WEATHER_IN_PROGRESS => 'Weather, after the activity started',
        self::REASON_CAMP_OPERATIONAL => 'Camp operational reason (coach, boat, too few guests)',
        self::REASON_UNKNOWN => 'Not yet classified',
    ];

    protected $fillable = [
        'booking_id', 'cancellation_request_id', 'source', 'reason_category', 'reason_confirmed_by', 'reason_confirmed_at',
        'activity_started', 'policy_version', 'guest_choice', 'guest_choice_recorded_by', 'guest_choice_at', 'status',
        'historical_decision', 'historical_decided_by', 'historical_decided_at', 'historical_rationale', 'quoted_centavos', 'requested_centavos', 'balance_review', 'approved_centavos',
        'approved_by', 'approved_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'reason_confirmed_at' => 'datetime',
            'guest_choice_at' => 'datetime',
            'approved_at' => 'datetime',
            'historical_decided_at' => 'datetime',
            'historical_decided_by' => 'integer',
            'quoted_centavos' => 'integer',
            'requested_centavos' => 'integer',
            'approved_centavos' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function cancellationRequest(): BelongsTo
    {
        return $this->belongsTo(CancellationRequest::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(RefundAttempt::class);
    }

    /** Booking made under the earlier published terms (no stamped policy version): never repriced automatically. */
    public function isHistorical(): bool
    {
        return $this->policy_version === null;
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason_category] ?? $this->reason_category;
    }

    /**
     * The approved (confirmed) part is paid back, but money paid beyond it is still unresolved: shown as such, never as
     * "completed" or settled (the case stays approved and open).
     */
    public function confirmedPartRefundedBalanceOpen(): bool
    {
        return $this->status === self::APPROVED && $this->balance_review === 'unresolved'
            && (int) $this->attempts()->whereIn('status', RefundAttempt::COMPLETED)->sum('amount_centavos') >= (int) $this->approved_centavos;
    }
}
