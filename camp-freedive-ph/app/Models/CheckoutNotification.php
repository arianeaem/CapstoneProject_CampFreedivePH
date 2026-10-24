<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One checkout message for one recipient, in the durable outbox (see CheckoutNotifier). */
class CheckoutNotification extends Model
{
    public const PENDING = 'pending';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const FAILED = 'failed'; // retries exhausted: kept for staff, never retried automatically

    public const CUSTOMER_BOOKING_CONFIRMED = 'customer_booking_confirmed';
    public const CUSTOMER_PAYMENT_RECEIVED = 'customer_payment_received';
    public const STAFF_NEW_BOOKING = 'staff_new_booking';
    public const STAFF_PAID_UNALLOCATED = 'staff_paid_unallocated';

    protected $fillable = [
        'checkout_intent_id', 'kind', 'recipient', 'recipient_hash', 'status', 'attempts',
        'available_at', 'claim_token', 'claimed_at', 'sent_at', 'last_error',
    ];

    protected $hidden = ['recipient', 'recipient_hash', 'claim_token'];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function checkoutIntent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class);
    }

    public function isForCustomer(): bool
    {
        return str_starts_with($this->kind, 'customer_');
    }
}
