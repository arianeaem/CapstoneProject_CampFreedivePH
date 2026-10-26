<?php

namespace App\Services\Payment;

use RuntimeException;

/**
 * An allocation of a verified alternative date was refused for a recorded reason (Batch 3-II B2.4); nothing of the
 * allocation was written. outcome "failed": the date cannot be given (the consent is spent, P6); "blocked": staff
 * review (price, receipt or instructor capacity not provable), the verified choice stays usable.
 */
class AllocationRefused extends RuntimeException
{
    public function __construct(public readonly string $outcome, public readonly string $reason, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }

    public static function failed(string $reason, string $message, array $details = []): self
    {
        return new self('failed', $reason, $message, $details);
    }

    public static function blocked(string $reason, string $message, array $details = []): self
    {
        return new self('blocked', $reason, $message, $details);
    }
}
