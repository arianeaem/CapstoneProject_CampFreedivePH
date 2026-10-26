<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only outcome of an allocation attempt for a verified alternative-date choice (Batch 3-II B2.4). */
class PaymentExceptionAllocationAttempt extends Model
{
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    public const ALLOCATED = 'allocated';
    /** The date could not be given (e.g. full): the choice is spent, new consent needed (P6). */
    public const FAILED = 'failed';
    /** Stopped for staff review (price or instructor capacity unverified): the verified choice stays usable. */
    public const BLOCKED = 'blocked';

    protected $fillable = ['payment_exception_id', 'choice_id', 'outcome', 'reason', 'booking_id', 'requested_start_date', 'requested_end_date',
        'details', 'attempted_by', 'attempted_at'];

    protected function casts(): array
    {
        return ['details' => 'array', 'attempted_at' => 'datetime', 'requested_start_date' => 'date:Y-m-d', 'requested_end_date' => 'date:Y-m-d'];
    }
}
