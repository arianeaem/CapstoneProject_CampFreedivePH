<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'booking_id',
        'requested_by',
        'requested_at',
        'eligibility_calculated',
        'status',
        'reviewed_by',
        'reviewed_at',
        'paymongo_refund_id',
        'forfeit_reason',
        'notes',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'eligibility_calculated' => 'array',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function getForfeitReasonLabelAttribute(): string
    {
        return match ($this->forfeit_reason) {
            'cancellation_outside_policy_window' => 'Cancellation Outside Policy Window (< 1 Week)',
            'customer_no_show' => 'Customer No-Show (Departure Forfeiture)',
            'unapproved_late_withdrawal' => 'Unapproved Late Withdrawal',
            'custom_administrative_decision' => 'Administrative Management Decision',
            default => $this->forfeit_reason ?: 'N/A',
        };
    }
}
