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
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
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
