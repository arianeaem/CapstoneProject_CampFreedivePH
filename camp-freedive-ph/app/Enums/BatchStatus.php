<?php

namespace App\Enums;

/**
 * Every value the batches.status column can hold.
 */
enum BatchStatus: string
{
    case Open = 'open';
    case Confirmed = 'confirmed';
    case Full = 'full';
    case Rescheduled = 'rescheduled';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case CancelledByCamp = 'cancelled_by_camp';

    public const CANCELLED = [self::Cancelled, self::CancelledByCamp];

    /** Done or cancelled, nothing else happens to this batch. */
    public const CLOSED = [self::Completed, self::Cancelled, self::CancelledByCamp];

    /** @param  array<int, self>  $cases
     *  @return array<int, string> */
    public static function values(array $cases): array
    {
        return array_map(fn (self $c) => $c->value, $cases);
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open for booking',
            self::Confirmed => 'Confirmed',
            self::Full => 'Full',
            self::Rescheduled => 'Rescheduled',
            self::Completed => 'Finished',
            self::Cancelled, self::CancelledByCamp => 'Cancelled',
        };
    }
}
