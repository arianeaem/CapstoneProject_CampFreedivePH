<?php

namespace App\Services;

use App\Models\BookingParticipant;
use App\Models\PricingRule;
use App\Support\DemandRules;
use Carbon\Carbon;

/**
 * Dynamic Yield Management & Pricing Rule Engine.
 *
 * Business Model & Economic Rationale:
 * 1. Multi-Factor Dynamic Pricing: Evaluates seasonal trends, occupancy velocity, ML demand forecasts,
 *    and booking lead times to optimize freediving camp capacity utilization across the year.
 * 2. Predictive Yield Management: Connects with Prophet/XGBoost seasonal demand forecasts to project
 *    batch fill rates and optimize early-bird discounts and capacity revenue ahead of time.
 * 3. Strict ±30% Price Clamping Cap (ADJUSTMENT_PERCENTAGE_CAP):
 *    Protects customer trust and transparent pricing by strictly bounding cumulative discounts/surcharges
 *    between -30% and +30% of the base class tier price.
 * 4. Batangas Micro-Climate Seasonality:
 *    - Peak / Shoulder / Off-Peak come from the demand module (config/demand.php), derived from
 *      the 553 actual registration records. They are no longer hardcoded here.
 */
class PricingRuleEngine
{
    /**
     * Percentage cap to clamp stacked adjustments (+/- 30% of base price).
     */
    public const ADJUSTMENT_PERCENTAGE_CAP = 0.30;

    /**
     * Standard Base Prices per class type (fallback defaults).
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
     * Resolve base price for given class and diver certification.
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
     * Get dynamic pricing cap as a decimal float (e.g. 0.30 for 30%).
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
     * Season for a date: 'peak' | 'shoulder' | 'off_peak'.
     *
     * Uses the ML demand forecast's season_period when a forecast row covers the date,
     * otherwise the single source of truth in config/demand.php (App\Support\DemandRules).
     * No season months are hardcoded in this class.
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
     * Demand level for a date: 'high' | 'medium' | 'low'.
     *
     * 1. The ML forecast's demand_level (High / Medium / Low) for that date is the base.
     * 2. Real confirmed headcount can only RAISE it (a date already filling up is never priced
     *    below its forecast). Headcount is judged with the same thresholds as the forecast.
     * 3. With no forecast row for the date, only the real headcount is used.
     */
    public function getDemandForDate(string|Carbon $date): string
    {
        $dateStr = Carbon::parse($date)->format('Y-m-d');

        $bookedCount = BookingParticipant::whereHas('booking', function ($q) use ($dateStr) {
            $q->whereDate('start_date', $dateStr)
              ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment']);
        })->count();

        $rank = [DemandRules::LOW => 1, DemandRules::MEDIUM => 2, DemandRules::HIGH => 3];

        $level = DemandRules::demandLevel($bookedCount);

        if ($this->demandForecastService) {
            $forecast = $this->demandForecastService->getForecastForDate($date);
            $forecastLevel = DemandRules::normalizeLevel($forecast['demand_level'] ?? null);

            if ($forecastLevel !== null) {
                // Real bookings may only raise the forecast level, never lower it.
                $level = ($rank[$level] > $rank[$forecastLevel]) ? $level : $forecastLevel;
            }
        }

        return strtolower($level);
    }

    /**
     * Days between today and the scheduled dive date.
     */
    public function getLeadTimeDays(string|Carbon $date): int
    {
        $today = Carbon::now('Asia/Manila')->startOfDay();
        $target = Carbon::parse($date)->startOfDay();

        return (int) $today->diffInDays($target, false);
    }

    /**
     * Evaluate all matching active pricing rules for a class and date.
     * Enforces priority ordering and a +/- 30% clamping cap.
     */
    public function evaluate(string $classType, string|Carbon $diveDate, bool $isCertified = false, int $paxCount = 1): array
    {
        $basePrice = $this->getBasePrice($classType, $isCertified);
        $normalizedClass = strtolower($classType);
        $season = $this->getSeasonForDate($diveDate);
        $demand = $this->getDemandForDate($diveDate);
        $leadTimeDays = $this->getLeadTimeDays($diveDate);

        // Fetch matching active rules
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

            if ($matched) {
                // Calculate unit delta per person
                $amount = ($rule->adjustment_method === 'percentage')
                    ? ($basePrice * ((float) $rule->adjustment_value / 100.0))
                    : (float) $rule->adjustment_value;

                $delta = ($rule->adjustment_type === 'increase') ? $amount : -$amount;
                $rawTotalDelta += $delta;

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

        // Apply clamping cap (+/- max cap % of base price)
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
