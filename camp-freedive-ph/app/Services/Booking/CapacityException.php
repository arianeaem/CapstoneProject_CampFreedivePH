<?php

namespace App\Services\Booking;

use RuntimeException;

/** A capacity-changing booking action was refused; nothing was written. The message is meant for staff/guests. */
class CapacityException extends RuntimeException
{
    public static function full(string $date, int $pax, int $left, int $max): self
    {
        return new self("No room on {$date}: {$pax} " . ($pax === 1 ? 'person needs' : 'people need') . " a place but only {$left} of {$max} "
            . ($left === 1 ? 'is' : 'are') . ' left (bookings and guests paying right now count). Nothing was changed.');
    }

    public static function busy(): self
    {
        return new self('Another booking for this date is being saved right now. Nothing was changed; please try again in a moment.');
    }
}
