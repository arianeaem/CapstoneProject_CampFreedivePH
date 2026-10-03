<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ParticipantAssignment extends Model
{
    use HasFactory;

    protected $table = 'participant_assignments';

    protected $fillable = [
        'participant_id',
        'booking_id',
        'coach_id',
        'batch_id',
        'dive_date',
        'assigned_by',
        'assigned_at',
        'status',
        'is_ratio_override',
    ];

    protected $casts = [
        'dive_date' => 'date',
        'assigned_at' => 'datetime',
        'is_ratio_override' => 'boolean',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(BookingParticipant::class, 'participant_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coach_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function assignedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
