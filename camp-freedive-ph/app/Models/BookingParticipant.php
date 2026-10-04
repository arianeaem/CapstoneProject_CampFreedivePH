<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'name',
        'birthdate',
        'gender',
        'age',
        'health_condition',
        'swimmer_status',
        'price_per_person',
    ];

    protected $casts = [
        'age' => 'integer',
        'birthdate' => 'date',
        'price_per_person' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function assignments()
    {
        return $this->hasMany(ParticipantAssignment::class, 'participant_id');
    }

    public function assignment()
    {
        return $this->hasOne(ParticipantAssignment::class, 'participant_id');
    }

    public function activeAssignment()
    {
        return $this->hasOne(ParticipantAssignment::class, 'participant_id')->where('status', 'assigned');
    }

    public function coach()
    {
        return $this->hasOneThrough(
            User::class,
            ParticipantAssignment::class,
            'participant_id',
            'id',
            'id',
            'coach_id'
        )->where('participant_assignments.status', 'assigned');
    }
}
