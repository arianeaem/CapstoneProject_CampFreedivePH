<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachAvailability extends Model
{
    use HasFactory;

    protected $table = 'coach_availabilities';

    protected $fillable = [
        'coach_id',
        'date',
        'status',
        'notes',
        'staff_confirmed_by',
        'staff_confirmed_at',
    ];

    protected $casts = [
        'date' => 'date',
        'staff_confirmed_at' => 'datetime',
    ];

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function staffConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_confirmed_by');
    }

    public function isStaffConfirmed(): bool
    {
        return $this->staff_confirmed_by !== null;
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            'available' => [
                'label' => 'Available',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'assigned' => [
                'label' => 'Assigned',
                'class' => 'bg-blue-50 text-blue-700',
            ],
            'unavailable' => [
                'label' => 'Unavailable',
                'class' => 'bg-gray-100 text-gray-700',
            ],
            default => [
                'label' => ucfirst($this->status),
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }
}
