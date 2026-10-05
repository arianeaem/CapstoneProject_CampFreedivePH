<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Coach extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'full_name',
        'email',
        'phone',
        'certification_level',
        'certification_number',
        'certification_expiry',
        'specialties_notes',
        'status',
        'photo_path',
        'joined_at',
    ];

    protected $casts = [
        'certification_expiry' => 'date',
        'joined_at' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(CoachAvailability::class)->orderBy('day_of_week');
    }

    public function blackoutDates(): HasMany
    {
        return $this->hasMany(CoachBlackoutDate::class)->orderBy('blackout_date');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(CoachAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(CoachAssignment::class)->whereNull('unassigned_at');
    }

    public function deactivationRequests(): HasMany
    {
        return $this->hasMany(DeactivationRequest::class)->latest();
    }

    /**
     * Check if the certification is expired on a date (default today).
     */
    public function isExpired(?Carbon $asOfDate = null): bool
    {
        $date = ($asOfDate ? $asOfDate->copy() : now())->startOfDay();
        return $this->certification_expiry->startOfDay()->lt($date);
    }

    /**
     * Check if the certification expires within 30 days.
     */
    public function isExpiringSoon(?Carbon $asOfDate = null): bool
    {
        $date = ($asOfDate ? $asOfDate->copy() : now())->startOfDay();
        return !$this->isExpired($date) && $this->certification_expiry->startOfDay()->lte($date->copy()->addDays(30));
    }

    public function getCertificationStatusBadgeAttribute(): array
    {
        if ($this->isExpired()) {
            return [
                'label' => 'Expired (' . $this->certification_expiry->format('M d, Y') . ')',
                'class' => 'bg-red-50 text-red-700',
                'state' => 'expired',
            ];
        }

        if ($this->isExpiringSoon()) {
            $days = now()->startOfDay()->diffInDays($this->certification_expiry->startOfDay());
            return [
                'label' => "Expiring in {$days}d (" . $this->certification_expiry->format('M d, Y') . ')',
                'class' => 'bg-amber-50 text-amber-800',
                'state' => 'expiring_soon',
            ];
        }

        return [
            'label' => 'Valid until ' . $this->certification_expiry->format('M d, Y'),
            'class' => 'bg-emerald-50 text-emerald-700',
            'state' => 'valid',
        ];
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            'active' => [
                'label' => 'Active',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'pending_deactivation' => [
                'label' => 'Pending Deactivation',
                'class' => 'bg-amber-50 text-amber-800',
            ],
            'on_leave' => [
                'label' => 'On Leave',
                'class' => 'bg-blue-50 text-blue-700',
            ],
            'inactive' => [
                'label' => 'Inactive',
                'class' => 'bg-gray-100 text-gray-700',
            ],
            default => [
                'label' => ucfirst(str_replace('_', ' ', $this->status)),
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }
}
