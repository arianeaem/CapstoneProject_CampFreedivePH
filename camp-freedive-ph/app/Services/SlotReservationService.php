<?php

namespace App\Services;

use App\Models\BookingParticipant;
use App\Models\CheckoutIntent;
use Carbon\Carbon;
use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Holds slots while a participant is checking out, so we don't go over
 * 45 people per weekend batch (boat limit and 1 coach for every 4 participants).
 */
class SlotReservationService
{
    /**
     * Default max people per batch.
     */
    public const MAX_CAPACITY = 45;

    /**
     * How long to hold the slots during checkout (15 minutes).
     */
    public const DEFAULT_HOLD_TTL_SECONDS = 900;

    /** Exception code thrown by assertLockStillHeld() when the capacity lock lease was lost. */
    public const LOCK_LOST = 4091;

    public function __construct(
        protected ?SystemSettingService $settingService = null
    ) {
        $this->settingService ??= app(SystemSettingService::class);
    }

    /**
     * Get the max people per batch.
     */
    public function getMaxCapacity(): int
    {
        return (int) ($this->settingService?->get('camp_operations.max_batch_capacity', self::MAX_CAPACITY) ?? self::MAX_CAPACITY);
    }

    /**
     * Run the callback while holding a lock for the date, so two checkouts
     * can't take the same slots at the same time.
     *
     * @param string $startDate YYYY-MM-DD
     * @param callable $callback
     * @param int $lockSeconds how long the lock lasts
     * @param int $blockSeconds how long to wait for the lock before giving up
     * @return mixed
     */
    public function withLock(string $startDate, callable $callback, int $lockSeconds = 10, int $blockSeconds = 5): mixed
    {
        $lockKey = "slot_allocation_lock:{$startDate}";
        $lock = Cache::lock($lockKey, $lockSeconds);

        return $lock->block($blockSeconds, $callback);
    }

    /**
     * Same date lock as withLock() (so it excludes the booking form and checkout start), but the
     * callback receives the lock, so it can check before each commit that the lease is still held
     * (assertLockStillHeld). The lease can run out; it is not trusted on its own.
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException when the lock is not free within $blockSeconds
     */
    public function withCapacityLock(string $startDate, callable $callback, int $leaseSeconds, int $blockSeconds): mixed
    {
        $date = Carbon::parse($startDate)->format('Y-m-d');
        $lock = Cache::lock("slot_allocation_lock:{$date}", $leaseSeconds);

        return $lock->block($blockSeconds, fn () => $callback($lock));
    }

    /**
     * Call first inside a capacity-changing transaction. On PostgreSQL this takes a transaction-scoped
     * advisory lock for the date: it is held until commit/rollback and cannot expire, so two such
     * transactions for the same date never overlap even if the cache lease runs out.
     * SQLite has no row/advisory locks; it serialises all writes to the database instead.
     */
    public function lockDateInTransaction(string $startDate): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $key = crc32('checkout_capacity:' . Carbon::parse($startDate)->format('Y-m-d'));
        DB::select('select pg_advisory_xact_lock(?)', [$key]);
    }

    /**
     * Throws (RuntimeException with code LOCK_LOST) when the cache lease was lost (expired or taken
     * over), so the caller rolls back instead of committing.
     */
    public function assertLockStillHeld(Lock $lock): void
    {
        if (! $lock->isOwnedByCurrentProcess()) {
            throw new \RuntimeException('The capacity lock lease was lost before commit.', self::LOCK_LOST);
        }
    }

    /**
     * Number of confirmed participants saved in the database for a date.
     *
     * @param string $startDate YYYY-MM-DD
     * @return int
     */
    public function getConfirmedPaxCount(string $startDate): int
    {
        $date = Carbon::parse($startDate)->format('Y-m-d');

        return BookingParticipant::whereHas('booking', function ($q) use ($date) {
            $q->whereDate('start_date', $date)
              ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment', 'paid_unallocated']);
        })->count();
    }

    /**
     * Number of people currently held in the cache for a date.
     *
     * @param string $startDate YYYY-MM-DD
     * @return int
     */
    public function getActiveHoldPaxCount(string $startDate): int
    {
        $date = Carbon::parse($startDate)->format('Y-m-d');
        $indexKey = "slot_holds_index:{$date}";
        $holdKeys = Cache::get($indexKey, []);

        if (empty($holdKeys) || !is_array($holdKeys)) {
            return 0;
        }

        $totalHeld = 0;
        $activeKeys = [];

        foreach ($holdKeys as $holdKey) {
            $holdData = Cache::get("slot_hold:{$date}:{$holdKey}");
            if ($holdData && is_array($holdData)) {
                $totalHeld += (int) ($holdData['pax'] ?? 0);
                $activeKeys[] = $holdKey;
            }
        }

        // Remove expired holds from the list
        if (count($activeKeys) !== count($holdKeys)) {
            Cache::put($indexKey, $activeKeys, now()->addDay());
        }

        return $totalHeld;
    }

    /** People held in the cache by one legacy checkout ($holdKey = its booking number); 0 once the hold ran out. */
    public function getHoldPax(string $startDate, string $holdKey): int
    {
        $date = Carbon::parse($startDate)->format('Y-m-d');

        return (int) (Cache::get("slot_hold:{$date}:{$holdKey}")['pax'] ?? 0);
    }

    /**
     * People held by unpaid checkout intents for a date. Read from the database, not a cache index:
     * a pending intent counts only until its hold_expires_at, even if the cleanup job has not run.
     *
     * @param string $startDate YYYY-MM-DD
     * @return int
     */
    public function getCheckoutIntentHoldPaxCount(string $startDate, ?int $excludingIntentId = null): int
    {
        $date = Carbon::parse($startDate)->format('Y-m-d');

        return (int) CheckoutIntent::holdingCapacity()
            ->whereDate('start_date', $date)
            ->when($excludingIntentId, fn ($q) => $q->whereKeyNot($excludingIntentId))
            ->sum('pax_count');
    }

    /**
     * Total taken slots: confirmed in DB + checkout-intent holds + older cache holds.
     * Intents never create cache holds and the older flow never creates intents, so nothing is counted twice.
     *
     * @param string $startDate YYYY-MM-DD
     * @return int
     */
    public function getEffectiveCommittedPax(string $startDate, ?int $excludingIntentId = null): int
    {
        return $this->getConfirmedPaxCount($startDate)
            + $this->getCheckoutIntentHoldPaxCount($startDate, $excludingIntentId)
            + $this->getActiveHoldPaxCount($startDate);
    }

    /**
     * Slots left for a date.
     *
     * @param string $startDate YYYY-MM-DD
     * @param int|null $excludingIntentId leave this checkout intent's own hold out (used when finalizing it)
     * @return int
     */
    public function getAvailableSlots(string $startDate, ?int $excludingIntentId = null): int
    {
        return max(0, $this->getMaxCapacity() - $this->getEffectiveCommittedPax($startDate, $excludingIntentId));
    }

    /**
     * Try to hold slots for a checkout. Call this inside withLock().
     *
     * @param string $startDate YYYY-MM-DD
     * @param string $holdKey e.g. booking number or session id
     * @param int $paxCount number of people
     * @param int $ttlSeconds how long to hold (default 15 minutes)
     * @return bool false if there are not enough slots
     */
    public function acquireHold(string $startDate, string $holdKey, int $paxCount, int $ttlSeconds = self::DEFAULT_HOLD_TTL_SECONDS): bool
    {
        $date = Carbon::parse($startDate)->format('Y-m-d');
        $currentCommitted = $this->getEffectiveCommittedPax($date);

        if (($currentCommitted + $paxCount) > $this->getMaxCapacity()) {
            return false;
        }

        // Save the hold with an expiry
        $holdData = [
            'pax' => $paxCount,
            'hold_key' => $holdKey,
            'start_date' => $date,
            'created_at' => now()->timestamp,
            'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
        ];

        Cache::put("slot_hold:{$date}:{$holdKey}", $holdData, now()->addSeconds($ttlSeconds));

        // Add it to the list of holds for the date
        $indexKey = "slot_holds_index:{$date}";
        $holdKeys = Cache::get($indexKey, []);
        if (!in_array($holdKey, $holdKeys)) {
            $holdKeys[] = $holdKey;
            Cache::put($indexKey, $holdKeys, now()->addDay());
        }

        Log::info("Acquired temporary slot hold for batch {$date}: {$paxCount} pax (Hold ID: {$holdKey}, TTL: {$ttlSeconds}s)");

        return true;
    }

    /**
     * Release a hold (after payment or if the participant cancels).
     *
     * @param string $startDate YYYY-MM-DD
     * @param string $holdKey
     * @return void
     */
    public function releaseHold(string $startDate, string $holdKey): void
    {
        $date = Carbon::parse($startDate)->format('Y-m-d');

        Cache::forget("slot_hold:{$date}:{$holdKey}");

        $indexKey = "slot_holds_index:{$date}";
        $holdKeys = Cache::get($indexKey, []);

        if (is_array($holdKeys) && in_array($holdKey, $holdKeys)) {
            $holdKeys = array_values(array_filter($holdKeys, fn($k) => $k !== $holdKey));
            Cache::put($indexKey, $holdKeys, now()->addDay());
        }

        Log::info("Released slot hold for batch {$date} (Hold ID: {$holdKey})");
    }
}
