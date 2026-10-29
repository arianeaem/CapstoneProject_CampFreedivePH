<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message for one recipient, in the durable notification outbox (see CheckoutNotifier, NotificationOutbox). */
class CheckoutNotification extends Model
{
    public const PENDING = 'pending';
    public const SENDING = 'sending';
    public const SENT = 'sent';
    public const FAILED = 'failed'; // retries exhausted: kept for staff, never retried automatically

    // Checkout outcomes (CheckoutNotifier)
    public const CUSTOMER_BOOKING_CONFIRMED = 'customer_booking_confirmed';
    public const CUSTOMER_PAYMENT_RECEIVED = 'customer_payment_received';
    public const STAFF_NEW_BOOKING = 'staff_new_booking';
    public const STAFF_PAID_UNALLOCATED = 'staff_paid_unallocated';

    // Guest request lifecycle (B6 — NotificationOutbox)
    public const GUEST_CANCELLATION_REQUESTED = 'guest_cancellation_requested';
    public const GUEST_CANCELLATION_APPROVED = 'guest_cancellation_approved';
    public const GUEST_CANCELLATION_REJECTED = 'guest_cancellation_rejected';
    public const GUEST_RESCHEDULE_REQUESTED = 'guest_reschedule_requested';
    public const GUEST_RESCHEDULE_APPROVED = 'guest_reschedule_approved';
    public const GUEST_RESCHEDULE_REJECTED = 'guest_reschedule_rejected';

    // Batch lifecycle (B6 — NotificationOutbox)
    public const GUEST_BATCH_CANCELLED = 'guest_batch_cancelled';
    public const GUEST_BATCH_RESCHEDULED = 'guest_batch_rescheduled';
    public const GUEST_BOOKING_UPDATED = 'guest_booking_updated';

    // Weather (B6 — NotificationOutbox)
    public const GUEST_WEATHER_CRITICAL = 'guest_weather_critical';
    public const GUEST_WEATHER_HIGH = 'guest_weather_high';

    // Staff operational (B6 — NotificationOutbox)
    public const STAFF_ADMIN_ALERT = 'staff_admin_alert';
    public const STAFF_COACH_SCHEDULE = 'staff_coach_schedule';

    // Payment exception (B6 — NotificationOutbox)
    public const STAFF_PAYMENT_EXCEPTION = 'staff_payment_exception';

    protected $fillable = [
        'checkout_intent_id', 'booking_id', 'batch_id', 'payment_exception_id',
        'kind', 'event_key', 'context', 'recipient', 'recipient_hash', 'status', 'attempts',
        'available_at', 'claim_token', 'claimed_at', 'sent_at', 'last_error',
    ];

    protected $hidden = ['recipient', 'recipient_hash', 'claim_token'];

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted',
            'context' => 'array',
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

    /** Set instead of checkout_intent_id for a legacy booking's confirmation (Batch 3-II A). */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function paymentException(): BelongsTo
    {
        return $this->belongsTo(PaymentException::class);
    }

    public function isForCustomer(): bool
    {
        return str_starts_with($this->kind, 'customer_');
    }

    public function isForGuest(): bool
    {
        return str_starts_with($this->kind, 'guest_') || $this->isForCustomer();
    }

    // --- Operational visibility scopes ---

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::FAILED);
    }

    public function scopeStuck($query)
    {
        return $query->where('status', self::SENDING)
            ->where('claimed_at', '<=', now()->subMinutes((int) config('checkout.notifications.stale_claim_minutes')));
    }
}
