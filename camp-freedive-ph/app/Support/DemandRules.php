<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Reads the demand and season rules from config/demand.php
 * (which reads demand-forecast/demand_thresholds.json, made by the ML script
 * from the 553 real records). Use this class instead of writing the thresholds again.
 */
class DemandRules
{
    public const LOW = 'Low';
    public const MEDIUM = 'Medium';
    public const HIGH = 'High';

    public const PEAK = 'Peak';
    public const SHOULDER = 'Shoulder';
    public const OFF_PEAK = 'Off-Peak';

    public static function lowMax(): float
    {
        return (float) config('demand.low_max', 10.0);
    }

    public static function mediumMax(): float
    {
        return (float) config('demand.medium_max', 20.3);
    }

    /** Demand level for a number of participants in ONE batch. */
    public static function demandLevel(float|int $participants): string
    {
        if ($participants <= self::lowMax()) {
            return self::LOW;
        }

        if ($participants <= self::mediumMax()) {
            return self::MEDIUM;
        }

        return self::HIGH;
    }

    /** Season for a calendar month (1-12). */
    public static function seasonForMonth(int $month): string
    {
        $map = (array) config('demand.season_by_month', []);

        return $map[(string) $month] ?? $map[$month] ?? self::SHOULDER;
    }

    public static function seasonForDate(string|Carbon $date): string
    {
        return self::seasonForMonth((int) Carbon::parse($date)->format('n'));
    }

    /** 'peak', 'shoulder' or 'off_peak' (format used by pricing rules). */
    public static function seasonSlug(string $season): string
    {
        return strtolower(str_replace(['-', ' '], '_', $season));
    }

    /** Turn 'high' / 'HIGH' / 'High' into 'High' (or null if unknown). */
    public static function normalizeLevel(?string $level): ?string
    {
        $l = ucfirst(strtolower(trim((string) $level)));

        return in_array($l, [self::LOW, self::MEDIUM, self::HIGH], true) ? $l : null;
    }

    /** Month numbers (1-12) in a season, e.g. 'Peak' => [3, 4, 6, 8]. */
    public static function monthsForSeason(string $season): array
    {
        $wanted = self::seasonSlug($season);
        $months = [];
        foreach (range(1, 12) as $m) {
            if (self::seasonSlug(self::seasonForMonth($m)) === $wanted) {
                $months[] = $m;
            }
        }

        return $months;
    }

    /** Month names for a season, e.g. 'Mar, Apr, Jun, Aug'. */
    public static function monthsLabel(string $season): string
    {
        $names = array_map(fn ($m) => date('M', mktime(0, 0, 0, $m, 1)), self::monthsForSeason($season));

        return $names ? implode(', ', $names) : 'none';
    }

    public static function rulesLoaded(): bool
    {
        return (bool) config('demand.rules_loaded', false);
    }

    public static function summary(): array
    {
        return [
            'rules_loaded' => self::rulesLoaded(),
            'source' => config('demand.source'),
            'generated_at' => config('demand.generated_at'),
            'batches_used' => config('demand.batches_used'),
            'low_max' => self::lowMax(),
            'medium_max' => self::mediumMax(),
            'season_by_month' => (array) config('demand.season_by_month', []),
            'seasonal_index_by_month' => (array) config('demand.seasonal_index_by_month', []),
            'peak_index' => (float) config('demand.peak_index'),
            'offpeak_index' => (float) config('demand.offpeak_index'),
        ];
    }
}
