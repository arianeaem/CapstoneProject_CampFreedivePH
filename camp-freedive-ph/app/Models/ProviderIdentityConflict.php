<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A provider payment that was refused as a match (different facts or owner, historical duplicate or unverified id). */
class ProviderIdentityConflict extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['provider', 'provider_payment_id', 'identity_id', 'payment_id', 'reason', 'source', 'attempted', 'fingerprint'];

    protected function casts(): array
    {
        return ['attempted' => 'array'];
    }
}
