<?php

namespace App\Services\Payment\Allocation;

final class InstructorCapacityResult
{
    private function __construct(public readonly bool $verified, public readonly string $reason, public readonly array $details = [])
    {
    }

    public static function verified(array $details = []): self
    {
        return new self(true, 'verified', $details);
    }

    public static function unverified(string $reason, array $details = []): self
    {
        return new self(false, $reason, $details);
    }
}
