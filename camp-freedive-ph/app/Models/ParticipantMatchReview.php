<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pair of directory records that may be the same person, waiting for an admin.
 */
class ParticipantMatchReview extends Model
{
    protected $fillable = [
        'participant_a_id',
        'participant_b_id',
        'reason',
        'status',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function participantA(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_a_id');
    }

    public function participantB(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_b_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
