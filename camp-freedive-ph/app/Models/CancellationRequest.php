<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CancellationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'calculated_refund_amount',
        'reason',
        'admin_notes',
        'force_majeure_flag',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'calculated_refund_amount' => 'decimal:2',
        'force_majeure_flag' => 'boolean',
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
