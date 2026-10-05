<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * A batch is one 2-day camp weekend (Saturday to Sunday) in Mabini, Batangas.
 *
 * - max 45 people per batch (boat limit / Coast Guard rules)
 * - 1 coach for every 4 divers (45 divers = up to 12 coaches)
 *
 * @property int $id
 * @property string $name
 * @property string $batch_code
 * @property Carbon $start_date Saturday start date
 * @property Carbon $end_date Sunday end date
 * @property string $lifecycle_status open, closing_soon, sold_out, completed, archived
 * @property string $risk_classification very_safe, safe, moderate, high_risk, critical_risk
 * @property int $max_capacity max people (default 45)
 * @property string $status confirmed, open, completed, rescheduled, cancelled_by_camp
 */
class Batch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'batch_code',
        'start_date',
        'end_date',
        'lifecycle_status',
        'risk_classification',
        'max_capacity',
        'status',
        'capacity_note',
        'notes',
        'created_by',
        'closed_at',
        'cancelled_at',
        'cancellation_reason',
        'completed_at',
        'archived_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'completed_at' => 'datetime',
        'archived_at' => 'datetime',
        'max_capacity' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(BatchStatusLog::class)->orderBy('created_at', 'desc');
    }

    public function participantAssignments(): HasMany
    {
        return $this->hasMany(ParticipantAssignment::class, 'batch_id');
    }

    public function coachAssignments(): HasMany
    {
        return $this->hasMany(ParticipantAssignment::class, 'batch_id');
    }

    public function activeParticipantAssignments(): HasMany
    {
        return $this->hasMany(ParticipantAssignment::class, 'batch_id')->where('status', 'assigned');
    }

    public function openings(): HasMany
    {
        return $this->hasMany(CoachOpening::class, 'batch_id');
    }

    public function coachRequests(): HasMany
    {
        return $this->hasMany(CoachRequest::class, 'batch_id');
    }

    public function riskAssessments(): HasMany
    {
        return $this->hasMany(BatchRiskAssessment::class, 'batch_id')->orderBy('assessed_at', 'desc');
    }

    public function riskAssessment(): HasOne
    {
        return $this->hasOne(BatchRiskAssessment::class, 'batch_id')->latestOfMany('assessed_at');
    }

    public function manualOverrides(): HasMany
    {
        return $this->hasMany(ManualOverride::class, 'batch_id')->orderBy('created_at', 'desc');
    }

    public function latestManualOverride(): HasOne
    {
        return $this->hasOne(ManualOverride::class, 'batch_id')->latestOfMany();
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class, 'batch_id')->orderBy('sent_at', 'desc');
    }

    public function releaseRequests(): HasMany
    {
        return $this->hasMany(AssignmentReleaseRequest::class, 'batch_id')->orderBy('requested_at', 'desc');
    }

    public function getLatestDay1AssessmentAttribute(): ?BatchRiskAssessment
    {
        return $this->riskAssessments()
            ->where('day_number', 1)
            ->with(['amHourlyAssessments', 'pmHourlyAssessments'])
            ->first();
    }

    public function getLatestDay2AssessmentAttribute(): ?BatchRiskAssessment
    {
        return $this->riskAssessments()
            ->where('day_number', 2)
            ->with(['amHourlyAssessments', 'pmHourlyAssessments'])
            ->first();
    }

    public function getLatestManualOverrideAttribute(): ?ManualOverride
    {
        return $this->latestManualOverride()->first();
    }

    /**
     * Name shown for the batch (uses the batch number).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->batch_number;
    }

    /**
     * Coaches assigned to this batch (no duplicates).
     */
    public function getAssignedCoachesAttribute(): Collection
    {
        // Use the already loaded assignments if we have them (saves queries on list pages)
        $assignments = $this->relationLoaded('activeParticipantAssignments')
            ? $this->activeParticipantAssignments->loadMissing('coach')
            : $this->activeParticipantAssignments()->with('coach')->get();

        $fromAssignments = $assignments
            ->pluck('coach')
            ->unique('id')
            ->filter();

        // Also get coaches set for this date even if there are no participants yet
        $startDateStr = $this->start_date ? $this->start_date->format('Y-m-d') : null;
        if ($startDateStr && $this->preloadedAvailabilityCoaches !== null) {
            return $fromAssignments->merge($this->preloadedAvailabilityCoaches)->unique('id')->values();
        }
        if ($startDateStr) {
            $batchNum = $this->batch_number;
            $batchCode = $this->batch_code;

            $fromAvailabilities = User::where('role', 'coach')
                ->whereHas('coachAvailabilities', function ($q) use ($startDateStr, $batchNum, $batchCode) {
                    $q->whereDate('date', $startDateStr)
                      ->where('status', 'assigned')
                      ->where(function ($sub) use ($batchNum, $batchCode) {
                          if ($batchNum) $sub->where('notes', 'like', "%{$batchNum}%");
                          if ($batchCode) $sub->orWhere('notes', 'like', "%{$batchCode}%");
                      });
                })->get();

            return $fromAssignments->merge($fromAvailabilities)->unique('id')->values();
        }

        return $fromAssignments;
    }

    /** Filled by preloadAssignedCoaches() so list pages don't run one query per batch. */
    protected ?Collection $preloadedAvailabilityCoaches = null;

    /**
     * Load the coaches for many batches in one query
     * (same rules as getAssignedCoachesAttribute()).
     */
    public static function preloadAssignedCoaches(iterable $batches): void
    {
        $batches = collect($batches)->filter(fn ($b) => $b->start_date);
        if ($batches->isEmpty()) {
            return;
        }

        $dates = $batches->map(fn ($b) => $b->start_date->format('Y-m-d'));
        $availabilities = CoachAvailability::query()
            ->where('status', 'assigned')
            ->whereDate('date', '>=', $dates->min())
            ->whereDate('date', '<=', $dates->max())
            ->whereHas('coach', fn ($q) => $q->where('role', 'coach'))
            ->with('coach')
            ->get();

        foreach ($batches as $batch) {
            $dateStr = $batch->start_date->format('Y-m-d');
            $num = $batch->batch_number;
            $code = $batch->batch_code;

            $batch->preloadedAvailabilityCoaches = $availabilities
                ->filter(fn ($a) => $a->date && $a->date->format('Y-m-d') === $dateStr)
                ->filter(fn ($a) => (!$num && !$code)
                    || ($num && str_contains((string) $a->notes, $num))
                    || ($code && str_contains((string) $a->notes, $code)))
                ->pluck('coach')
                ->unique('id')
                ->values();
        }
    }

    public const MAX_CAPACITY = 45;

    public function getAssignedCoachesCountAttribute(): int
    {
        return $this->assigned_coaches->count();
    }

    /**
     * Max people per batch (45).
     */
    public function getComputedCapacityAttribute(): int
    {
        return $this->max_capacity ?: self::MAX_CAPACITY;
    }

    /**
     * True if the batch has bookings but no coach yet.
     */
    public function getIsCoachPendingAttribute(): bool
    {
        if ($this->total_participants_count === 0) {
            return false;
        }

        return $this->assigned_coaches_count === 0;
    }

    /**
     * Total participants in active bookings.
     */
    public const INACTIVE_BOOKING_STATUSES = ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment'];

    public function getTotalParticipantsCountAttribute(): int
    {
        // 1. Already computed by scopeWithActiveParticipantsTotal()
        if (array_key_exists('active_participants_total', $this->attributes)) {
            return (int) $this->attributes['active_participants_total'];
        }

        // 2. Already loaded bookings.participants (saves one query per batch)
        if ($this->relationLoaded('bookings') && $this->bookings->every(fn ($b) => $b->relationLoaded('participants'))) {
            return (int) $this->bookings
                ->whereNotIn('status', self::INACTIVE_BOOKING_STATUSES)
                ->sum(fn ($b) => $b->participants->count());
        }

        return (int) $this->bookings()
            ->whereNotIn('status', self::INACTIVE_BOOKING_STATUSES)
            ->withCount('participants')
            ->get()
            ->sum('participants_count');
    }

    /**
     * Adds an active_participants_total column so we don't need another query.
     */
    public function scopeWithActiveParticipantsTotal(Builder $query): Builder
    {
        return $query->addSelect(['active_participants_total' => BookingParticipant::query()
            ->selectRaw('count(*)')
            ->join('bookings', 'bookings.id', '=', 'booking_participants.booking_id')
            ->whereColumn('bookings.batch_id', 'batches.id')
            ->whereNotIn('bookings.status', self::INACTIVE_BOOKING_STATUSES)]);
    }

    /**
     * Slots left (out of 45).
     */
    public function getRemainingCapacityAttribute(): int
    {
        return max(0, $this->computed_capacity - $this->total_participants_count);
    }

    /**
     * How full the batch is in % (out of 45).
     */
    public function getOccupancyPercentageAttribute(): ?int
    {
        if ($this->computed_capacity === 0) {
            return null;
        }

        return (int) min(100, round(($this->total_participants_count / $this->computed_capacity) * 100));
    }

    /**
     * Date range text:
     * - same year: Oct 12 - Oct 13, 2026
     * - different year: Dec 31, 2026 - Jan 1, 2027
     * - one day: Oct 12, 2026
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

    /**
     * Total expected income from active bookings.
     */
    public function getTotalRevenueAttribute(): float
    {
        return (float) $this->bookings
            ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment'])
            ->sum('total_amount');
    }

    /**
     * Amount already paid for bookings in this batch.
     */
    public function getCollectedRevenueAttribute(): float
    {
        $activeBookings = $this->bookings
            ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment']);

        $activeBookingIds = $activeBookings->pluck('id');

        $paymentSum = (float) Payment::whereIn('booking_id', $activeBookingIds)
            ->whereIn('status', ['completed', 'paid'])
            ->sum('amount');

        if ($paymentSum === 0.0 && $activeBookings->isNotEmpty()) {
            $calculatedCollected = $activeBookings->sum(function ($b) {
                return max(0.0, (float) ($b->total_amount - $b->balance_amount));
            });
            return (float) max(0.0, $calculatedCollected);
        }

        return $paymentSum;
    }

    /**
     * Same as the one above.
     */
    public function getTotalCollectedAmountAttribute(): float
    {
        return $this->collected_revenue;
    }

    /**
     * Active bookings that still have a balance.
     */
    public function getOutstandingBalanceBookingsAttribute(): Collection
    {
        return $this->bookings
            ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment'])
            ->filter(fn($b) => (float) $b->balance_amount > 0)
            ->values();
    }

    /**
     * Number of bookings with a balance.
     */
    public function getOutstandingBalanceBookingsCountAttribute(): int
    {
        return $this->outstanding_balance_bookings->count();
    }

    /**
     * Number of pending refunds in this batch.
     */
    public function getPendingRefundsCountAttribute(): int
    {
        return RefundRequest::whereIn('booking_id', $this->bookings()->pluck('id'))
            ->where('status', 'pending')
            ->count();
    }

    /**
     * True if the weather is High or Critical Risk.
     */
    public function getIsCriticalOrHighRiskAttribute(): bool
    {
        return in_array($this->risk_classification, ['high_risk', 'critical_risk']);
    }

    /**
     * True if the batch needs attention (Critical weather, or coming up soon with no coach).
     */
    public function getNeedsAttentionAttribute(): bool
    {
        if ($this->is_critical_or_high_risk) {
            return true;
        }

        if ($this->is_coach_pending && $this->start_date <= Carbon::now()->addDays(7) && in_array($this->status, ['confirmed', 'open'])) {
            return true;
        }

        return false;
    }

    public function getBatchNumberAttribute(): string
    {
        if (!empty($this->batch_code) && preg_match('/(\d+)/', $this->batch_code, $matches)) {
            return 'Batch ' . $matches[1];
        }
        if (!empty($this->name) && preg_match('/(\d+)/', $this->name, $matches)) {
            return 'Batch ' . $matches[1];
        }
        return 'Batch ' . $this->id;
    }

    public function getStatusBadgeAttribute(): array
    {
        $st = $this->status ?: $this->lifecycle_status ?: 'confirmed';
        return match ($st) {
            'confirmed', 'open' => [
                'label' => 'Confirmed',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'completed' => [
                'label' => 'Completed',
                'class' => 'bg-gray-100 text-gray-700',
            ],
            'rescheduled' => [
                'label' => 'Rescheduled',
                'class' => 'bg-amber-50 text-amber-800',
            ],
            'cancelled_by_camp', 'cancelled' => [
                'label' => 'Cancelled by Camp',
                'class' => 'bg-rose-50 text-rose-700',
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $st)),
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }

    public function getRiskBadgeAttribute(): array
    {
        $key = strtolower(str_replace([' ', '-'], '_', $this->risk_classification ?: 'safe'));
        return match ($key) {
            'very_safe' => [
                'label' => 'Very Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'safe' => [
                'label' => 'Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'moderate' => [
                'label' => 'Moderate',
                'class' => 'bg-amber-50 text-amber-800',
            ],
            'high_risk' => [
                'label' => 'High Risk',
                'class' => 'bg-rose-50 text-rose-700',
            ],
            'critical_risk' => [
                'label' => 'Critical',
                'class' => 'bg-red-100 text-red-800',
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $this->risk_classification ?: 'Safe')),
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }
}
