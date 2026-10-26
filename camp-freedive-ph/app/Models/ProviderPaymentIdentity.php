<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One provider payment (provider + provider payment id, unique) and the facts the provider verified when it was
 * recorded. Written only by ReceiptRecorder; verified facts are permanent (database trigger).
 *
 * verification: provider_verified (amount, currency and mode came from the verified provider payment) or
 * historical_unverified (reserved for a later approved backfill of rows recorded before identities existed;
 * currency and livemode stay NULL = unknown).
 */
class ProviderPaymentIdentity extends Model
{
    public const PROVIDER_PAYMONGO = 'paymongo';
    public const CLAIMED = 'claimed';
    public const PROVIDER_VERIFIED = 'provider_verified';
    public const HISTORICAL_UNVERIFIED = 'historical_unverified';

    protected $fillable = [
        'provider', 'provider_payment_id', 'amount_centavos', 'currency', 'livemode', 'checkout_intent_id', 'booking_id',
        'payment_id', 'status', 'verification', 'source',
    ];

    protected function casts(): array
    {
        return [
            'amount_centavos' => 'integer',
            'livemode' => 'boolean',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isVerified(): bool
    {
        return $this->verification === self::PROVIDER_VERIFIED && $this->currency !== null && $this->livemode !== null;
    }
}
