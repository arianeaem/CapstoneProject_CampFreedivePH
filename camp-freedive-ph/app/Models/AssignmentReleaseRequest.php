<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentReleaseRequest extends Model
{
    use HasFactory;

    protected $table = 'assignment_release_requests';

    protected $fillable = [
        'coach_id',
        'batch_id',
        'dive_date',
        'reason',
        'status',
        'requested_at',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'dive_date' => 'date',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
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
                'label' => 'Approved & Released',
                'class' => 'bg-emerald-50 text-emerald-700',
            ],
            'rejected' => [
                'label' => 'Rejected',
                'class' => 'bg-rose-50 text-rose-700',
            ],
            default => [
                'label' => ucfirst($this->status),
                'class' => 'bg-gray-100 text-gray-700',
            ],
        };
    }
}
