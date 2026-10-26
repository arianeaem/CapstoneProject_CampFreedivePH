<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only history of a payment exception (database trigger forbids changes). */
class PaymentExceptionEvent extends Model
{
    public const UPDATED_AT = null;

    public const OPENED = 'opened';
    public const BATCH_CANCELLED = 'batch_cancelled_by_camp';
    public const BOOKING_CANCELLED = 'booking_cancelled_by_camp';
    public const BATCH_RESCHEDULED = 'batch_rescheduled_by_camp';

    protected $fillable = ['payment_exception_id', 'type', 'actor_id', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
