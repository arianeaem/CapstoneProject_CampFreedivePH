<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BookingParticipant;
use App\Models\PricingRule;
use App\Support\DemandRules;
use Carbon\Carbon;

/**
 * Pricing rules (discounts and surcharges).
 *
 * - looks at season, how full the batch is, the demand forecast and how early people book
 * - all adjustments together are kept between -30% and +30% of the base price
 * - discounts do not stack: only the single biggest discount is applied (premiums still add up)
 * - a rule with max_fill_percent only applies while the batch is less than that % full
 * - Peak / Shoulder / Off-Peak months come from config/demand.php (based on the 553 real
 *   registration records), not from this file
 */
class PricingRuleEngine
{
    /**
     * Max total adjustment (+/- 30% of the base price).
     */
    public const ADJUSTMENT_PERCENTAGE_CAP = 0.30;

    /**
     * Default base prices per class.
     */
    public const BASE_PRICES = [
        'discovery' => 4250.00,
        'fundive_certified' => 2500.00,
        'fundive_non_certified' => 3300.00,
        'refinement' => 4100.00,
    ];

    public function __construct(
        protected ?DemandForecastService $demandForecastService = null,
        protected ?SystemSettingService $settingService = null
    ) {
        $this->demandForecastService ??= app(DemandForecastService::class);
        $this->settingService ??= app(SystemSettingService::class);
    }

    /**
     * Get the base price for a class (certified divers pay less for fundive).
     */
    public function getBasePrice(string $classType, bool $isCertified = false): float
    {
        $classKey = strtolower($classType);
        if ($classKey === 'fundive') {
            return $isCertified
                ? (float) ($this->settingService?->get('program_pricing.base_price_fundive_cert', self::BASE_PRICES['fundive_certified']) ?? self::BASE_PRICES['fundive_certified'])
                : (float) ($this->settingService?->get('program_pricing.base_price_fundive_noncert', self::BASE_PRICES['fundive_non_certified']) ?? self::BASE_PRICES['fundive_non_certified']);
        }

        $settingKey = match ($classKey) {
            'discovery' => 'program_pricing.base_price_discovery',
            'refinement' => 'program_pricing.base_price_refinement',
            default => null,
        };

        if ($settingKey) {
            $val = $this->settingService?->get($settingKey);
            if ($val !== null) {
                return (float) $val;
            }
        }

        return self::BASE_PRICES[$classKey] ?? self::BASE_PRICES['discovery'];
    }

    /**
     * Get the max adjustment as a decimal (e.g. 0.30 = 30%).
     */
    public function getAdjustmentCap(): float
    {
        $capPercent = $this->settingService?->get('program_pricing.dynamic_pricing_cap_percent');
        if ($capPercent !== null && is_numeric($capPercent)) {
            return (float) $capPercent / 100.0;
        }

        return self::ADJUSTMENT_PERCENTAGE_CAP;
    }

    /**
     * Season for a date: 'peak', 'shoulder' or 'off_peak'.
     *
     * Uses the demand forecast's season_period if there is a forecast for the date,
     * otherwise config/demand.php (App\Support\DemandRules).
     */
    public function getSeasonForDate(string|Carbon $date): string
    {
        if ($this->demandForecastService) {
            $forecast = $this->demandForecastService->getForecastForDate($date);
            if (!empty($forecast['season_period'])) {
                $period = DemandRules::seasonSlug((string) $forecast['season_period']);
                if (in_array($period, ['peak', 'shoulder', 'off_peak'], true)) {
                    return $period;
                }
            }
        }

        return DemandRules::seasonSlug(DemandRules::seasonForDate($date));
    }

    /**
     * Demand level for a date: 'high', 'medium' or 'low'.
     *
     * 1. Start with the forecast's demand_level for that date.
     * 2. Real bookings can only make it higher, never lower (uses the same thresholds).
     * 3. If there's no forecast for the date, only real bookings are used.
     */
    public function getDemandForDate(string|Carbon $date): string
    {
        $bookedCount = $this->getBookedCountForDate($date);

        $rank = [DemandRules::LOW => 1, DemandRules::MEDIUM => 2, DemandRules::HIGH => 3];

        $level = DemandRules::demandLevel($bookedCount);

        if ($this->demandForecastService) {
            $forecast = $this->demandForecastService->getForecastForDate($date);
            $forecastLevel = DemandRules::normalizeLevel($forecast['demand_level'] ?? null);

            if ($forecastLevel !== null) {
                // Real bookings can only raise the level, not lower it
                $level = ($rank[$level] > $rank[$forecastLevel]) ? $level : $forecastLevel;
            }
        }

        return strtolower($level);
    }

    /**
     * Participants in active bookings starting on this date.
     */
    public function getBookedCountForDate(string|Carbon $date): int
    {
        $dateStr = Carbon::parse($date)->format('Y-m-d');

        return BookingParticipant::whereHas('booking', function ($q) use ($dateStr) {
            $q->whereDate('start_date', $dateStr)
              ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment']);
        })->count();
    }

    /**
     * How full the batch on this date is (0-100), using the batch capacity or the camp max (45).
     */
    public function getFillPercentForDate(string|Carbon $date): float
    {
        $batch = Batch::whereDate('start_date', Carbon::parse($date)->format('Y-m-d'))->first();
        $capacity = $batch?->computed_capacity
            ?: (int) ($this->settingService?->get('camp_operations.max_batch_capacity', 45) ?? 45);

        return $capacity > 0 ? round($this->getBookedCountForDate($date) / $capacity * 100, 1) : 0.0;
    }

    /**
     * Days from today until the dive.
     */
    public function getLeadTimeDays(string|Carbon $date): int
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $target = Carbon::parse($date)->startOfDay();

        return (int) $today->diffInDays($target, false);
    }

    /**
     * Apply all matching active pricing rules for a class and date.
     * Rules run by priority and the total is kept within +/- 30%.
     */
    public function evaluate(string $classType, string|Carbon $diveDate, bool $isCertified = false, int $paxCount = 1): array
    {
        $basePrice = $this->getBasePrice($classType, $isCertified);
        $normalizedClass = strtolower($classType);
        $season = $this->getSeasonForDate($diveDate);
        $demand = $this->getDemandForDate($diveDate);
        $leadTimeDays = $this->getLeadTimeDays($diveDate);
        $fillPercent = null; // only looked up if a rule needs it

        // Get the matching active rules
        $rules = PricingRule::active()
            ->where(function ($q) use ($normalizedClass) {
                $q->where('applies_to', 'all')
                  ->orWhere('applies_to', $normalizedClass);
            })
            ->orderBy('priority', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $appliedAdjustments = [];
        $rawTotalDelta = 0.0;

        foreach ($rules as $rule) {
            $matched = false;

            switch ($rule->rule_type) {
                case 'demand':
                    $matched = ($rule->condition_value === $demand);
                    break;

                case 'seasonality':
                    $matched = ($rule->condition_value === $season);
                    break;

                case 'lead_time':
                    $targetDays = (int) $rule->condition_value;
                    $op = $rule->condition_operator ?: '<=';

                    $matched = match ($op) {
                        '<=' => ($leadTimeDays <= $targetDays),
                        '>=' => ($leadTimeDays >= $targetDays),
                        '<' => ($leadTimeDays < $targetDays),
                        '>' => ($leadTimeDays > $targetDays),
                        '==' => ($leadTimeDays === $targetDays),
                        default => ($leadTimeDays <= $targetDays),
                    };
                    break;
            }

            // Optional extra condition: only while the batch is less than X% full
            if ($matched && $rule->max_fill_percent !== null) {
                $fillPercent ??= $this->getFillPercentForDate($diveDate);
                $matched = $fillPercent < (float) $rule->max_fill_percent;
            }

            if ($matched) {
                // Change per person
                $amount = ($rule->adjustment_method === 'percentage')
                    ? ($basePrice * ((float) $rule->adjustment_value / 100.0))
                    : (float) $rule->adjustment_value;

                $delta = ($rule->adjustment_type === 'increase') ? $amount : -$amount;

                $appliedAdjustments[] = [
                    'rule_id' => $rule->id,
                    'rule_name' => $rule->name,
                    'rule_type' => $rule->rule_type,
                    'condition_summary' => $rule->condition_summary,
                    'formatted_adjustment' => $rule->formatted_adjustment,
                    'adjustment_type' => $rule->adjustment_type,
                    'delta_per_pax' => round($delta, 2),
                    'total_delta' => round($delta * $paxCount, 2),
                ];
            }
        }

        // Discounts don't stack: keep only the biggest one (first by priority if tied)
        $discounts = array_filter($appliedAdjustments, fn ($a) => $a['delta_per_pax'] < 0);
        if (count($discounts) > 1) {
            $biggest = array_key_first($discounts);
            foreach ($discounts as $key => $a) {
                if ($a['delta_per_pax'] < $discounts[$biggest]['delta_per_pax']) {
                    $biggest = $key;
                }
            }
            $appliedAdjustments = array_values(array_filter(
                $appliedAdjustments,
                fn ($a, $key) => $a['delta_per_pax'] >= 0 || $key === $biggest,
                ARRAY_FILTER_USE_BOTH
            ));
        }
        $rawTotalDelta = array_sum(array_column($appliedAdjustments, 'delta_per_pax'));

        // Keep the total within the max %
        $cap = $this->getAdjustmentCap();
        $maxAdjustment = $basePrice * $cap;
        $minAdjustment = -$basePrice * $cap;

        $clampedDelta = max($minAdjustment, min($maxAdjustment, $rawTotalDelta));
        $wasClamped = ($clampedDelta !== $rawTotalDelta);

        $adjustedPricePerPax = max(500.00, round($basePrice + $clampedDelta, 2));

        $forecastData = $this->demandForecastService?->getForecastForDate($diveDate);

        return [
            'class_type' => $classType,
            'is_certified' => $isCertified,
            'dive_date' => Carbon::parse($diveDate)->format('Y-m-d'),
            'season' => $season,
            'season_label' => ucfirst(str_replace('_', '-', $season)) . ' Season',
            'demand' => $demand,
            'demand_label' => ucfirst($demand) . ' Demand',
            'forecast_source' => $forecastData ? 'ml_predictive' : 'historical_headcount',
            'predicted_participants' => $forecastData['predicted_participants'] ?? null,
            'predicted_bookings' => $forecastData['predicted_bookings'] ?? null,
            'lead_time_days' => $leadTimeDays,
            'base_price_per_pax' => $basePrice,
            'adjusted_price_per_pax' => $adjustedPricePerPax,
            'delta_per_pax' => round($clampedDelta, 2),
            'was_clamped' => $wasClamped,
            'adjustments' => $appliedAdjustments,
            'has_adjustments' => count($appliedAdjustments) > 0,
            'pax_count' => $paxCount,
            'subtotal' => round($adjustedPricePerPax * $paxCount, 2),
            'base_subtotal' => round($basePrice * $paxCount, 2),
            'total_savings_or_surcharge' => round($clampedDelta * $paxCount, 2),
        ];
    }
}
