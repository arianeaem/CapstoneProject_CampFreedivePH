<?php

namespace App\Enums;

/**
 * Every value the bookings.status column can hold.
 * Use BookingStatus::Confirmed->value instead of typing 'confirmed' by hand.
 */
enum BookingStatus: string
{
    case PendingDownpayment = 'pending_downpayment';
    case Confirmed = 'confirmed';
    case RescheduleRequested = 'reschedule_requested';
    case CancellationRequested = 'cancellation_requested';
    case Rescheduled = 'rescheduled';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case CancelledByCamp = 'cancelled_by_camp';
    case CancelledByGuest = 'cancelled_by_guest';

    /** Paid and still going on the trip. */
    public const GOING = [self::Confirmed, self::RescheduleRequested, self::CancellationRequested, self::Rescheduled];

    /** Any kind of cancellation. */
    public const CANCELLED = [self::Cancelled, self::CancelledByCamp, self::CancelledByGuest];

    /** Bookings that do not take a seat on the boat (cancelled or not paid yet). */
    public const INACTIVE = [self::CancelledByCamp, self::CancelledByGuest, self::Cancelled, self::PendingDownpayment];

    /** @param  array<int, self>  $cases
     *  @return array<int, string> */
    public static function values(array $cases): array
    {
        return array_map(fn (self $c) => $c->value, $cases);
    }

    public function isCancelled(): bool
    {
        return in_array($this, self::CANCELLED, true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingDownpayment => 'Waiting for downpayment',
            self::Confirmed, self::Rescheduled => 'Paid & going',
            self::RescheduleRequested => 'Asked to reschedule',
            self::CancellationRequested => 'Asked to cancel',
            self::Completed => 'Finished trip',
            self::NoShow => 'No-show',
            self::Cancelled, self::CancelledByCamp, self::CancelledByGuest => 'Cancelled',
        };
    }
}
