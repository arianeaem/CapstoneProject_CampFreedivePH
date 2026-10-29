<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * S1: one row = one coach committed to one batch on one date.
 *
 * The unique constraint on (coach_id, date) is the authoritative conflict-prevention mechanism.
 * Two-day camps produce two rows. Unassignment deletes the row.
 */
class CoachDateCommitment extends Model
{
    protected $fillable = [
        'coach_id', 'batch_id', 'date', 'assigned_by', 'assigned_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'assigned_at' => 'datetime',
        ];
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(Coach::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
