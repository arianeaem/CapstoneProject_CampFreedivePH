<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Checkout\BookingFinalizer;
use App\Services\SlotReservationService;
use Carbon\Carbon;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The one way to change who takes a place on a dive date (Batch 3-II A). Same protocol as checkout start and
 * finalization (CheckoutService, BookingFinalizer), so every path excludes every other:
 *
 *   1. cache lease "slot_allocation_lock:{date}" for each date, in sorted date order (also excludes the legacy form);
 *   2. one transaction whose first statements take the PostgreSQL advisory lock for each date, in the same sorted
 *      order (held until commit, cannot expire), with lock_timeout bounding the wait;
 *   3. the caller locks its own rows, rechecks, writes; the lease is checked again before commit.
 * Network calls never happen inside: callers do them after commit.
 *
 * Capacity is the configured global limit per start date (camp_operations.max_batch_capacity), counted as
 * SlotReservationService does: bookings that take a place + live checkout-intent holds + legacy cache holds.
 */
class CapacityCoordinator
{
    public const LEASE_SECONDS = 60;
    public const WAIT_SECONDS = 5;

    public function __construct(protected SlotReservationService $slots)
    {
    }

    public static function takesPlace(string $status): bool
    {
        return ! in_array($status, BookingStatus::values(BookingStatus::INACTIVE), true);
    }

    /**
     * Run $callback inside the capacity locks of $dates (one transaction). Throws CapacityException when the dates are
     * busy (lock wait timed out or the lease was lost): nothing was written, the caller may try again.
     */
    public function transaction(array $dates, callable $callback): mixed
    {
        $dates = array_values(array_unique(array_map(fn ($d) => Carbon::parse($d)->format('Y-m-d'), array_filter($dates))));
        sort($dates);

        try {
            return $this->leased($dates, [], function (array $locks) use ($dates, $callback) {
                return DB::transaction(function () use ($dates, $callback, $locks) {
                    foreach ($dates as $i => $date) {
                        $i === 0
                            ? BookingFinalizer::lockCapacityInTransaction($this->slots, $date) // also sets lock_timeout
                            : $this->slots->lockDateInTransaction($date);
                    }
                    $result = $callback();
                    foreach ($locks as $lock) {
                        $this->slots->assertLockStillHeld($lock);
                    }

                    return $result;
                });
            });
        } catch (LockTimeoutException) {
            throw CapacityException::busy();
        } catch (QueryException|\RuntimeException $e) {
            if (BookingFinalizer::isCapacityLockFailure($e)) {
                throw CapacityException::busy();
            }
            throw $e;
        }
    }

    /**
     * Places still free on $date. $excluding leaves that booking's own place (if it takes one on this date) and its own
     * legacy checkout hold out of the count, so re-checking a booking never counts it twice. May be negative.
     */
    public function remaining(string $date, ?Booking $excluding = null): int
    {
        $date = Carbon::parse($date)->format('Y-m-d');
        $used = $this->slots->getEffectiveCommittedPax($date);
        if ($excluding !== null) {
            if (self::takesPlace($excluding->status) && $excluding->start_date?->format('Y-m-d') === $date) {
                $used -= $excluding->participants()->count();
            }
            $used -= $this->slots->getHoldPax($date, $excluding->booking_number);
        }

        return $this->slots->getMaxCapacity() - $used;
    }

    /** Throws CapacityException unless $pax more people fit on $date (call inside transaction()). */
    public function assertRoom(string $date, int $pax, ?Booking $excluding = null): void
    {
        $left = $this->remaining($date, $excluding);
        if ($pax > $left) {
            throw CapacityException::full(Carbon::parse($date)->format('M d, Y'), $pax, max(0, $left), $this->slots->getMaxCapacity());
        }
    }

    /** Nested cache leases, one per date in order; each callback level receives the leases taken so far. */
    private function leased(array $dates, array $locks, callable $inner): mixed
    {
        if ($dates === []) {
            return $inner($locks);
        }
        $date = array_shift($dates);

        return $this->slots->withCapacityLock($date, function (Lock $lock) use ($dates, $locks, $inner) {
            return $this->leased($dates, [...$locks, $lock], $inner);
        }, self::LEASE_SECONDS, (int) config('checkout.finalization_lock_wait_seconds', self::WAIT_SECONDS));
    }
}
