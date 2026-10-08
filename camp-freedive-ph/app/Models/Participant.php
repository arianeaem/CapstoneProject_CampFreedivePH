<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A real person in the Participant Directory, with their history across bookings.
 * Each booking keeps its own snapshot in booking_participants.
 */
class Participant extends Model
{
    public const CANCELLED_STATUSES = ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled'];

    protected $fillable = [
        'full_name',
        'name_key',
        'birthdate',
        'gender',
        'latest_swimmer_status',
        'latest_health_condition',
        'health_updated_at',
        'last_email',
        'last_phone',
        'last_dive_date',
        'merged_into_id',
        'anonymized_at',
    ];

    protected $casts = [
        'birthdate' => 'date',
        'health_updated_at' => 'datetime',
        'last_dive_date' => 'date',
        'anonymized_at' => 'datetime',
    ];

    /** Each booking this person joined (snapshots). */
    public function bookingParticipants(): HasMany
    {
        return $this->hasMany(BookingParticipant::class, 'participant_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    /** Records shown in the directory (not merged away, not anonymised). */
    public function scopeListed(Builder $query): Builder
    {
        return $query->whereNull('merged_into_id')->whereNull('anonymized_at');
    }

    public function getAgeAttribute(): ?int
    {
        return $this->birthdate?->age;
    }

    public function isMinor(): bool
    {
        return $this->age !== null && $this->age < 18;
    }

    /** Bookings that count toward the history (not cancelled). */
    public function activeBookingParticipants(): HasMany
    {
        return $this->bookingParticipants()
            ->whereHas('booking', fn ($q) => $q->whereNotIn('status', self::CANCELLED_STATUSES));
    }
}
