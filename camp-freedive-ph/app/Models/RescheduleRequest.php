<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RescheduleRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'current_start_date',
        'current_end_date',
        'requested_start_date',
        'requested_end_date',
        'reason',
        'admin_notes',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'current_start_date' => 'date',
        'current_end_date' => 'date',
        'requested_start_date' => 'date',
        'requested_end_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
