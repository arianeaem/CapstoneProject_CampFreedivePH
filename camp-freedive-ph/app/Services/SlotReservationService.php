<?php

namespace App\Services;

use App\Models\BookingParticipant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Holds slots while a customer is checking out, so we don't go over
 * 45 people per weekend batch (boat limit and 1 coach for every 4 divers).
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
              ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment']);
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

    /**
     * Total taken slots (confirmed in DB + held in cache).
     *
     * @param string $startDate YYYY-MM-DD
     * @return int
     */
    public function getEffectiveCommittedPax(string $startDate): int
    {
        return $this->getConfirmedPaxCount($startDate) + $this->getActiveHoldPaxCount($startDate);
    }

    /**
     * Slots left for a date.
     *
     * @param string $startDate YYYY-MM-DD
     * @return int
     */
    public function getAvailableSlots(string $startDate): int
    {
        return max(0, $this->getMaxCapacity() - $this->getEffectiveCommittedPax($startDate));
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
     * Release a hold (after payment or if the customer cancels).
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
