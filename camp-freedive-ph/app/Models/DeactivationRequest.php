<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeactivationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'coach_id',
        'requested_by',
        'status',
        'resolved_by',
        'resolved_at',
        'reason',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            'pending' => [
                'label' => 'Pending Owner Review',
                'class' => 'bg-amber-50 text-amber-800',
            ],
            'confirmed' => [
                'label' => 'Confirmed & Deactivated',
                'class' => 'bg-red-50 text-red-700',
            ],
            'dismissed' => [
                'label' => 'Dismissed',
                'class' => 'bg-gray-100 text-gray-700',
            ],
            default => [
                'label' => ucfirst($this->status),
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }
}
