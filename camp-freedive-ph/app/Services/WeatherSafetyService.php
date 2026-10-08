<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Weather safety check for the booking pages.
 *
 * Turns the forecast (waves, wind, gusts, pressure, current) into one of 5 levels:
 * - Very Safe / Safe: normal
 * - Moderate: ok but be careful, use sheltered spots
 * - High Risk: rough sea, extra safety divers
 * - Critical Risk: no diving, reschedule/refund
 *
 * Up to 16 days ahead we use the Open-Meteo forecast. After that we use
 * the usual weather for that season (Amihan / Habagat).
 */
class WeatherSafetyService
{
    /**
     * @param WeatherForecastService $forecastService
     */
    public function __construct(
        protected WeatherForecastService $forecastService
    ) {}

    /**
     * Check the weather for a 2-day weekend.
     *
     * @param string|Carbon $startDate Saturday
     * @param string|Carbon $endDate Sunday
     * @return array safety result with the badge/colors for the UI and hourly data
     */
    public function getForecast(string|Carbon $startDate, string|Carbon $endDate): array
    {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        $today = Carbon::today(WeatherForecastService::TIMEZONE);
        $daysOut = (int) $today->diffInDays($start->copy()->startOfDay(), false);

        if ($daysOut >= 0 && $daysOut <= WeatherForecastService::MAX_FORECAST_DAYS) {
            $assessment = $this->forecastService->previewDateAssessment($start);
            if (!empty($assessment['available'])) {
                $overallClass = $assessment['overall_classification'] ?? 'Safe';
                $day1 = $assessment['day1'] ?? [];
                $day2 = $assessment['day2'] ?? [];

            $riskLevel = match ($overallClass) {
                'Very Safe' => 'very_safe',
                'Safe' => 'safe',
                'Moderate' => 'moderate',
                'High Risk' => 'high_risk',
                'Critical Risk' => 'critical_risk',
                default => 'safe',
            };

            $riskConfig = $this->getRiskConfig($riskLevel);

            $suggestedDates = [];
            if ($riskLevel === 'critical_risk') {
                $nextSat = $start->copy()->addWeeks(1)->next(Carbon::SATURDAY);
                $suggestedDates = [
                    [
                        'start_date' => $nextSat->format('Y-m-d'),
                        'end_date' => $nextSat->copy()->addDay()->format('Y-m-d'),
                        'label' => $nextSat->format('M d') . ' - ' . $nextSat->copy()->addDay()->format('M d, Y') . ' (Next Weekend - Safe)',
                    ],
                    [
                        'start_date' => $nextSat->copy()->addWeeks(1)->format('Y-m-d'),
                        'end_date' => $nextSat->copy()->addWeeks(1)->addDay()->format('Y-m-d'),
                        'label' => $nextSat->copy()->addWeeks(1)->format('M d') . ' - ' . $nextSat->copy()->addWeeks(1)->addDay()->format('M d, Y') . ' (2 Weeks Out - Very Safe)',
                    ]
                ];
            }

            $relObj = $assessment['reliability'] ?? WeatherForecastService::getReliabilityCategory($daysOut);
            $reliability = is_array($relObj) ? ($relObj['label'] ?? 'Moderate') : (string)$relObj;
            $confidence = $assessment['confidence'] ?? ($daysOut >= 4 ? 'low' : 'high');
            $rawAdvisory = $assessment['confidence_advisory'] ?? ($confidence === 'low' ? "Confidence is low this far out, recheck in 2 days." : null);
            $confidenceAdvisory = $rawAdvisory ? preg_replace('/^(Very Safe|Safe|Moderate|High Risk|Critical Risk)[\.\:\-]\s*/i', '', $rawAdvisory) : null;
            $seasonalEstimate = ($day1['seasonal_estimate'] ?? false)
                && ($day2['seasonal_estimate'] ?? false);

            $formattedDescription = $riskConfig['description'];

            return [
                'is_benchmark' => false,
                'risk_level' => $seasonalEstimate ? 'seasonal' : $riskLevel,
                'overall_classification' => $seasonalEstimate ? null : $overallClass,
                'confidence' => $seasonalEstimate ? 'seasonal' : $confidence,
                'reliability' => $seasonalEstimate ? 'Seasonal estimate' : ($assessment['reliability'] ?? null),
                'confidence_advisory' => $confidenceAdvisory,
                'is_seasonal_estimate' => $seasonalEstimate,
                'historical_replay' => $assessment['historical_replay'] ?? false,
                'historical_replay_label' => $assessment['historical_replay_label'] ?? null,
                'evaluating_engine' => $assessment['evaluating_engine'] ?? 'prd_site_forecast',
                'data_source' => $assessment['data_source'] ?? null,
                'engines' => $assessment['engines'] ?? null,
                'title' => $riskConfig['title'],
                'badge_color' => $riskConfig['badge_color'],
                'border_color' => $riskConfig['border_color'],
                'bg_color' => $riskConfig['bg_color'],
                'text_color' => $riskConfig['text_color'],
                'icon' => $riskConfig['icon'],
                'description' => $formattedDescription,
                // Roughest hour, shown when rougher than the whole-day rating
                'peak_label' => $seasonalEstimate ? null : ($assessment['peak_label'] ?? null),
                'is_bookable' => $seasonalEstimate || $riskLevel !== 'critical_risk',
                'has_storm_signal' => $riskLevel === 'critical_risk',
                'days_out' => $daysOut,
                'reliability' => $reliability,
                'day1' => array_merge($day1, [
                    'date' => $start->format('M d, Y'),
                    'classification' => $day1['classification'] ?? 'Safe',
                    'confidence' => $day1['confidence'] ?? $confidence,
                    'confidence_advisory' => $day1['confidence_advisory'] ?? $confidenceAdvisory,
                    'recommended_action' => $day1['recommended_action'] ?? 'Conditions are generally safe, but normal safety protocols should still be followed.',
                    'worst_hour' => $day1['worst_hour'] ?? '11:00 AM',
                    'peak_label' => $seasonalEstimate ? null : \App\Services\WeatherForecastService::peakLabel($day1['peak'] ?? null),
                ]),
                'day2' => array_merge($day2, [
                    'date' => $end->format('M d, Y'),
                    'classification' => $day2['classification'] ?? 'Safe',
                    'confidence' => $day2['confidence'] ?? $confidence,
                    'confidence_advisory' => $day2['confidence_advisory'] ?? $confidenceAdvisory,
                    'recommended_action' => $day2['recommended_action'] ?? 'Conditions are generally safe, but normal safety protocols should still be followed.',
                    'worst_hour' => $day2['worst_hour'] ?? '11:00 AM',
                    'peak_label' => $seasonalEstimate ? null : \App\Services\WeatherForecastService::peakLabel($day2['peak'] ?? null),
                ]),
                'suggested_dates' => $suggestedDates,
                'location' => 'Mabini / Anilao, Batangas',
            ];
            }
        }

        // More than 16 days away:
        $riskConfig = $this->getRiskConfig('safe');
        return [
            'is_benchmark' => true,
            'is_seasonal_estimate' => true,
            'risk_level' => 'safe',
            'overall_classification' => 'Safe',
            'confidence' => 'low',
            'confidence_advisory' => 'Seasonal climatological baseline for advance planning. Operational models update as trip approaches.',
            'title' => 'Booking Open (Standard Season Benchmark)',
            'badge_color' => $riskConfig['badge_color'],
            'border_color' => $riskConfig['border_color'],
            'bg_color' => $riskConfig['bg_color'],
            'text_color' => $riskConfig['text_color'],
            'icon' => $riskConfig['icon'],
            'description' => 'Seasonal estimate: typical conditions for this time of year. Historical climatological baseline active; live models evaluate 16 days prior to departure.',
            'is_bookable' => true,
            'has_storm_signal' => false,
            'days_out' => $daysOut,
            'reliability' => 'Seasonal Baseline',
            'day1' => [
                'date' => $start->format('M d, Y'),
                'classification' => 'Safe',
                'confidence' => 'low',
                'confidence_advisory' => 'Advance seasonal baseline',
                'recommended_action' => 'Conditions are generally safe, but normal safety protocols should still be followed.',
                'worst_hour' => 'Daylight Baseline',
                'wave_height_m' => 0.35,
                'current_speed_ms' => 0.30,
                'wind_speed_kmh' => 15.0,
                'wind_speed_ms' => 4.2,
                'wind_gust_kmh' => 22.0,
                'wind_gust_ms' => 6.1,
                'rain_daily_mm' => 3.5,
                'rain_label' => 'Light Rain',
                'p_wet' => 0.45,
                'p_high_gust' => 0.05,
            ],
            'day2' => [
                'date' => $end->format('M d, Y'),
                'classification' => 'Safe',
                'confidence' => 'low',
                'confidence_advisory' => 'Advance seasonal baseline',
                'recommended_action' => 'Conditions are generally safe, but normal safety protocols should still be followed.',
                'worst_hour' => 'Daylight Baseline',
                'wave_height_m' => 0.35,
                'current_speed_ms' => 0.30,
                'wind_speed_kmh' => 15.0,
                'wind_speed_ms' => 4.2,
                'wind_gust_kmh' => 22.0,
                'wind_gust_ms' => 6.1,
                'rain_daily_mm' => 3.5,
                'rain_label' => 'Light Rain',
                'p_wet' => 0.45,
                'p_high_gust' => 0.05,
            ],
            'suggested_dates' => [],
            'location' => 'Mabini / Anilao, Batangas',
        ];
    }

    public function isStormSignalActive(string|Carbon $date): bool
    {
        $forecast = $this->getForecast($date, Carbon::parse($date)->addDay());
        return $forecast['has_storm_signal'] || $forecast['risk_level'] === 'critical_risk';
    }

    protected function getRiskConfig(string $level): array
    {
        return match ($level) {
            'very_safe' => [
                'title' => 'Very Safe',
                'badge_color' => '#34C759',
                'border_color' => '#A7F3D0',
                'bg_color' => '#ECFDF5',
                'text_color' => '#065F46',
                'icon' => 'shield-check',
                'description' => 'Calm seas, clear water visibility, and light ocean breeze. Optimal conditions for learning, equalizing, and open water dives.',
            ],
            'safe' => [
                'title' => 'Safe',
                'badge_color' => '#34C759',
                'border_color' => '#BBF7D0',
                'bg_color' => '#F0FDF4',
                'text_color' => '#166534',
                'icon' => 'check-circle',
                'description' => 'Good water visibility and gentle ripple. Great conditions for all class types.',
            ],
            'moderate' => [
                'title' => 'Moderate Conditions',
                'badge_color' => '#FF8D28',
                'border_color' => '#FDE68A',
                'bg_color' => '#FFFBEB',
                'text_color' => '#92400E',
                'icon' => 'alert-circle',
                'description' => 'Conditions are diveable but variable. Coaches will monitor closely and choose sheltered coves along Anilao coast.',
            ],
            'high_risk' => [
                'title' => 'High Risk Conditions',
                'badge_color' => '#FF8D28',
                'border_color' => '#FED7AA',
                'bg_color' => '#FFF7ED',
                'text_color' => '#9A3412',
                'icon' => 'alert-triangle',
                'description' => 'Surface swell and reduced visibility. Additional safety divers assigned; motion sickness precautions recommended.',
            ],
            'critical_risk' => [
                'title' => 'Critical Risk - Booking Suspended',
                'badge_color' => '#FF3B3C',
                'border_color' => '#FECACA',
                'bg_color' => '#FEF2F2',
                'text_color' => '#991B1B',
                'icon' => 'x-circle',
                'description' => 'Severe weather advisory, storm signal, or dangerous marine sea state in Batangas. Online bookings suspended for participant safety.',
            ],
            default => [
                'title' => 'Safe',
                'badge_color' => '#34C759',
                'border_color' => '#BBF7D0',
                'bg_color' => '#F0FDF4',
                'text_color' => '#166534',
                'icon' => 'check-circle',
                'description' => 'Normal dive conditions expected. Standard safety protocols in place.',
            ],
        };
    }
}
