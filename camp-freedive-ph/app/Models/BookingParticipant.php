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

    /**
     * Every new booking participant is linked to the Participant Directory, and the
     * person's latest details follow edits to their booking.
     */
    protected static function booted(): void
    {
        static::created(function (BookingParticipant $bp) {
            app(\App\Services\ParticipantDirectoryService::class)->link($bp);
        });

        static::updated(function (BookingParticipant $bp) {
            if ($bp->participant_id && $bp->wasChanged(['health_condition', 'swimmer_status', 'gender'])) {
                app(\App\Services\ParticipantDirectoryService::class)->refresh($bp->participant);
            }
        });
    }

    /**
     * True when the health note says something staff should act on.
     * "None", "Fit for diving" and similar all-clear answers don't count.
     */
    public function getHasHealthNoteAttribute(): bool
    {
        $note = strtolower(rtrim(trim($this->health_condition ?? ''), '.'));

        if ($note === '' || in_array($note, ['none', 'none declared', 'no', 'n/a', 'na', 'nil', 'normal'], true)) {
            return false;
        }

        foreach (['fit for diving', 'none', 'cleared medical waiver', 'first time freediving', 'certified aida', 'working on frenzel'] as $allClear) {
            if (str_starts_with($note, $allClear)) {
                return false;
            }
        }

        return true;
    }

    /** Gender as shown to staff. */
    public function getGenderLabelAttribute(): string
    {
        return ['male' => 'Male', 'female' => 'Female', 'prefer_not_to_say' => 'Prefer not to say'][$this->gender] ?? 'Not given';
    }

    /** The person in the Participant Directory. */
    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class, 'participant_id');
    }

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
