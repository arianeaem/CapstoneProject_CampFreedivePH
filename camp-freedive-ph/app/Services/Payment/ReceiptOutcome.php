<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\ProviderPaymentIdentity;

/**
 * What ReceiptRecorder did with a provider payment. Only RECORDED and ATTACHED wrote a receipt change; the two
 * "already recorded" outcomes are verified replays (every fact and the owner matched). Everything else is a refusal
 * that the caller must never treat as a duplicate.
 */
final class ReceiptOutcome
{
    public const NOT_RECORDED = 'not_recorded';                     // new provider payment (inspect only)
    public const RECORDED = 'recorded';
    public const ALREADY_RECORDED = 'already_recorded';
    public const ALREADY_RECORDED_LEGACY = 'already_recorded_legacy'; // a receipt from before identities, same owner and amount; nothing claimed
    public const ATTACHED = 'attached';
    public const PROVIDER_MISMATCH = 'conflict_provider_mismatch';
    public const OWNER_MISMATCH = 'conflict_owner_mismatch';
    public const HISTORICAL = 'conflict_historical';                // duplicate or unverified historical id, or receipt missing
    public const ATTACH_BLOCKED = 'attach_blocked';

    public function __construct(
        public readonly string $status,
        public readonly ?Payment $payment = null,
        public readonly ?ProviderPaymentIdentity $identity = null,
        public readonly ?string $reason = null,
    ) {
    }

    public function isReplay(): bool
    {
        return in_array($this->status, [self::ALREADY_RECORDED, self::ALREADY_RECORDED_LEGACY], true);
    }

    public function isConflict(): bool
    {
        return in_array($this->status, [self::PROVIDER_MISMATCH, self::OWNER_MISMATCH, self::HISTORICAL], true);
    }

    /** Short reason for review events and audit lines. */
    public function describe(): string
    {
        return $this->reason !== null ? "{$this->status}:{$this->reason}" : $this->status;
    }
}
