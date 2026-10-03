<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'assignment_logs';

    protected $fillable = [
        'participant_id',
        'old_coach_id',
        'new_coach_id',
        'changed_by',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(BookingParticipant::class, 'participant_id');
    }

    public function oldCoach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'old_coach_id');
    }

    public function newCoach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_coach_id');
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
