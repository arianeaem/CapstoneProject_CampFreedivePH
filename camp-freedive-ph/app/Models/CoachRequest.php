<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CoachRequest extends Model
{
    use HasFactory;

    protected $table = 'coach_requests';

    protected $fillable = [
        'opening_id',
        'batch_id',
        'coach_id',
        'status',
        'reviewed_by',
        'reviewed_at',
        'notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function opening(): BelongsTo
    {
        return $this->belongsTo(CoachOpening::class, 'opening_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            'pending' => [
                'label' => 'Pending Review',
                'class' => 'bg-amber-50 text-amber-800',
            ],
            'approved' => [
                'label' => 'Approved & Assigned',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'not_selected' => [
                'label' => 'Not Selected',
                'class' => 'bg-gray-100 text-gray-700',
            ],
            default => [
                'label' => ucfirst($this->status),
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }
}
