<?php

namespace App\Services;

use App\Models\BookingParticipant;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service responsible for distributed slot locking and temporary inventory reservation
 * during the booking and checkout lifecycle.
 *
 * Operational Context:
 * Enforces the strict 45-pax limit per weekend batch mandated by Philippine Coast Guard (PCG)
 * outrigger banca limits and the 1:4 instructor-to-diver safety ratio.
 */
class SlotReservationService
{
    /**
     * Maximum participant capacity per weekend batch (fallback default).
     */
    public const MAX_CAPACITY = 45;

    /**
     * Default duration to hold temporary slots during checkout step 4 (15 minutes).
     */
    public const DEFAULT_HOLD_TTL_SECONDS = 900;

    public function __construct(
        protected ?SystemSettingService $settingService = null
    ) {
        $this->settingService ??= app(SystemSettingService::class);
    }

    /**
     * Get maximum participant capacity per weekend batch.
     */
    public function getMaxCapacity(): int
    {
        return (int) ($this->settingService?->get('camp_operations.max_batch_capacity', self::MAX_CAPACITY) ?? self::MAX_CAPACITY);
    }

    /**
     * Executes a callback within an atomic distributed lock for a specific dive date.
     *
     * @param string $startDate Format: YYYY-MM-DD
     * @param callable $callback Operation to perform while holding the lock
     * @param int $lockSeconds Duration the lock is held
     * @param int $blockSeconds Time to wait attempting to acquire the lock before throwing LockTimeoutException
     * @return mixed
     */
    public function withLock(string $startDate, callable $callback, int $lockSeconds = 10, int $blockSeconds = 5): mixed
    {
        $lockKey = "slot_allocation_lock:{$startDate}";
        $lock = Cache::lock($lockKey, $lockSeconds);

        return $lock->block($blockSeconds, $callback);
    }

    /**
     * Retrieves the count of confirmed participants in the database for a given start date.
     *
     * @param string $startDate Format: YYYY-MM-DD
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
     * Calculates the sum of active, unexpired temporary slot holds in the cache for a given start date.
     *
     * @param string $startDate Format: YYYY-MM-DD
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

        // Clean up any expired keys from index
        if (count($activeKeys) !== count($holdKeys)) {
            Cache::put($indexKey, $activeKeys, now()->addDay());
        }

        return $totalHeld;
    }

    /**
     * Computes the total effective committed participants (DB Confirmed + Cache Active Holds).
     *
     * @param string $startDate Format: YYYY-MM-DD
     * @return int
     */
    public function getEffectiveCommittedPax(string $startDate): int
    {
        return $this->getConfirmedPaxCount($startDate) + $this->getActiveHoldPaxCount($startDate);
    }

    /**
     * Calculates the remaining available slots for a given date.
     *
     * @param string $startDate Format: YYYY-MM-DD
     * @return int
     */
    public function getAvailableSlots(string $startDate): int
    {
        return max(0, $this->getMaxCapacity() - $this->getEffectiveCommittedPax($startDate));
    }

    /**
     * Attempts to acquire a temporary slot hold for a checkout session.
     * Must be called within an atomic lock for thread-safety.
     *
     * @param string $startDate Format: YYYY-MM-DD
     * @param string $holdKey Unique identifier (e.g. booking number or session UUID)
     * @param int $paxCount Number of seats requested
     * @param int $ttlSeconds Time-to-live in seconds (defaults to 15 minutes)
     * @return bool True if hold was successfully registered; false if capacity exceeded
     */
    public function acquireHold(string $startDate, string $holdKey, int $paxCount, int $ttlSeconds = self::DEFAULT_HOLD_TTL_SECONDS): bool
    {
        $date = Carbon::parse($startDate)->format('Y-m-d');
        $currentCommitted = $this->getEffectiveCommittedPax($date);

        if (($currentCommitted + $paxCount) > $this->getMaxCapacity()) {
            return false;
        }

        // Save individual hold with TTL
        $holdData = [
            'pax' => $paxCount,
            'hold_key' => $holdKey,
            'start_date' => $date,
            'created_at' => now()->timestamp,
            'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
        ];

        Cache::put("slot_hold:{$date}:{$holdKey}", $holdData, now()->addSeconds($ttlSeconds));

        // Register in the date's hold index
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
     * Releases an active slot hold (e.g., when payment succeeds and DB status updates to confirmed,
     * or when a customer explicitly cancels).
     *
     * @param string $startDate Format: YYYY-MM-DD
     * @param string $holdKey Unique identifier
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
