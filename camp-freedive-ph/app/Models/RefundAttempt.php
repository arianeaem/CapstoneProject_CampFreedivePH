<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refund of one payment. Status meaning:
 *  prepared                 allocated, nothing sent yet (safe to submit)
 *  submitting               claimed for the single PayMongo POST; if it stays here the outcome is unknown
 *  provider_pending         PayMongo accepted it but has not paid it out yet
 *  unknown                  no reliable answer (timeout, 5xx, malformed): never re-sent, staff resolve it
 *  succeeded                PayMongo confirmed the refund
 *  failed                   PayMongo rejected it, or staff verified no refund happened (releases the amount)
 *  offline_awaiting_payout  to be paid back outside PayMongo (cash, bank)
 *  offline_confirmed        staff documented the offline payout
 */
class RefundAttempt extends Model
{
    public const PREPARED = 'prepared';
    public const SUBMITTING = 'submitting';
    public const PROVIDER_PENDING = 'provider_pending';
    public const UNKNOWN = 'unknown';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const OFFLINE_AWAITING = 'offline_awaiting_payout';
    public const OFFLINE_CONFIRMED = 'offline_confirmed';

    /** Paid back for certain. */
    public const COMPLETED = [self::SUCCEEDED, self::OFFLINE_CONFIRMED];

    /** Not paid back yet, but held so nothing is submitted twice. */
    public const RESERVED = [self::PREPARED, self::SUBMITTING, self::PROVIDER_PENDING, self::UNKNOWN, self::OFFLINE_AWAITING];

    /**
     * PayMongo outcomes still open. They stay reserved (never re-sent) until PayMongo itself settles them: its answer,
     * a signed webhook, or a lookup of the refund (RefundCaseService::verifyAttempt). Staff notes never settle them.
     */
    public const VERIFIABLE = [self::SUBMITTING, self::UNKNOWN, self::PROVIDER_PENDING];

    /** How a PayMongo outcome was settled (resolution_source); offline payouts are documented by staff. */
    public const SOURCE_PROVIDER_RESPONSE = 'provider_response';
    public const SOURCE_PROVIDER_WEBHOOK = 'provider_webhook';
    public const SOURCE_PROVIDER_VERIFICATION = 'provider_verification';
    public const SOURCE_OFFLINE_STAFF = 'offline_staff';

    protected $fillable = [
        'booking_refund_id', 'payment_id', 'operation_id', 'method', 'amount_centavos', 'currency', 'status',
        'provider_refund_id', 'provider_status', 'error_code', 'submitted_at', 'resolved_at', 'created_by',
        'resolved_by', 'evidence_note', 'resolution_source', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_centavos' => 'integer',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function bookingRefund(): BelongsTo
    {
        return $this->belongsTo(BookingRefund::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
