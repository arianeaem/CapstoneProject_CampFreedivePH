<?php

namespace Tests\Unit;

use App\Services\WeatherForecastService;
use Tests\TestCase;

class ForecastQuantileScoringTest extends TestCase
{
    protected WeatherForecastService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WeatherForecastService();
    }

    public function test_compute_weighted_score_with_high_confidence_calm_conditions(): void
    {
        $forecast = [
            'physics' => [
                'significant_wave_height_m' => ['p10' => 0.40, 'p50' => 0.60, 'p90' => 0.85],
                'peak_period_s' => ['p10' => 5.5, 'p50' => 6.8, 'p90' => 8.0],
                'swell_height_m' => ['p10' => 0.20, 'p50' => 0.35, 'p90' => 0.50],
                'wind_wave_height_m' => ['p10' => 0.15, 'p50' => 0.25, 'p90' => 0.35],
                'wind_speed_kmh' => ['p10' => 10.0, 'p50' => 14.5, 'p90' => 19.0],
                'wind_gust_kmh' => ['p10' => 14.0, 'p50' => 19.0, 'p90' => 25.0],
                'wind_direction_deg' => ['p10' => 35.0, 'p50' => 45.0, 'p90' => 55.0],
                'sea_level_pressure_hpa' => ['p10' => 1010.0, 'p50' => 1012.5, 'p90' => 1014.0],
                'current_speed_ms' => ['p10' => 0.08, 'p50' => 0.15, 'p90' => 0.25],
                'rain_rate_mm_hr' => 0.0,
            ],
        ];

        $result = $this->service->computeWeightedScore($forecast);

        $this->assertIsArray($result);
        $this->assertEquals('high', $result['confidence']);
        // 0.6 m waves: experts put 0.5-0.6 m in Moderate, so the worst-of floor applies
        $this->assertSame('Moderate', $result['classification']);
        $this->assertFalse($result['is_physical_breach']);
        $this->assertLessThan(40.0, $result['weighted_score_pct']);
    }

    public function test_evaluate_confidence_detects_straddling_wind_ceiling(): void
    {
        // Wind speed ceiling is 42 km/h. If p10=36 and p90=46, it straddles the ceiling -> 'low'
        $forecast = [
            'physics' => [
                'significant_wave_height_m' => ['p10' => 0.50, 'p50' => 0.70, 'p90' => 0.95],
                'wind_speed_kmh' => ['p10' => 36.0, 'p50' => 40.0, 'p90' => 46.0],
                'wind_gust_kmh' => ['p10' => 40.0, 'p50' => 45.0, 'p90' => 47.0],
                'current_speed_ms' => ['p10' => 0.20, 'p50' => 0.30, 'p90' => 0.45],
            ],
        ];

        $result = $this->service->computeWeightedScore($forecast);

        $this->assertIsArray($result);
        $this->assertEquals('low', $result['confidence']);
    }

    public function test_evaluate_confidence_detects_straddling_wave_ceiling(): void
    {
        // Wave height ceiling is 1.80m. If p10=1.40 and p90=2.10, it straddles the ceiling -> 'low'
        $forecast = [
            'physics' => [
                'significant_wave_height_m' => ['p10' => 1.40, 'p50' => 1.75, 'p90' => 2.10],
                'wind_speed_kmh' => ['p10' => 12.0, 'p50' => 16.0, 'p90' => 22.0],
                'wind_gust_kmh' => ['p10' => 18.0, 'p50' => 24.0, 'p90' => 30.0],
                'current_speed_ms' => ['p10' => 0.20, 'p50' => 0.30, 'p90' => 0.40],
            ],
        ];

        $result = $this->service->computeWeightedScore($forecast);

        $this->assertIsArray($result);
        $this->assertEquals('low', $result['confidence']);
    }

    public function test_evaluate_confidence_detects_straddling_current_ceiling(): void
    {
        // Current speed ceiling is 0.80 m/s. If p10=0.65 and p90=0.95, it straddles -> 'low'
        $forecast = [
            'physics' => [
                'significant_wave_height_m' => ['p10' => 0.40, 'p50' => 0.60, 'p90' => 0.80],
                'wind_speed_kmh' => ['p10' => 10.0, 'p50' => 15.0, 'p90' => 20.0],
                'wind_gust_kmh' => ['p10' => 15.0, 'p50' => 20.0, 'p90' => 25.0],
                'current_speed_ms' => ['p10' => 0.65, 'p50' => 0.75, 'p90' => 0.95],
            ],
        ];

        $result = $this->service->computeWeightedScore($forecast);

        $this->assertIsArray($result);
        $this->assertEquals('low', $result['confidence']);
    }

    public function test_backward_compatibility_with_numeric_scores_array(): void
    {
        $rawScores = [
            'wave_height' => 1,
            'wind_speed' => 1,
            'ocean_current' => 0,
            'swell_height' => 1,
            'wave_period' => 0,
            'wind_wave_height' => 1,
            'rain' => 0,
            'sea_level_pressure' => 0,
            'wind_direction' => 0,
        ];

        $score = $this->service->computeWeightedScore($rawScores);

        $this->assertIsFloat($score);
        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }

    public function test_hourly_tier_uses_weighted_score_and_does_not_use_high_gust_probability(): void
    {
        $result = $this->service->calculateTierAndLabel([
            'hs' => ['source' => 'climatology', 'p50' => 0.40, 'p90' => 0.70],
            'current_speed' => ['source' => 'climatology', 'p50' => 0.15, 'p90' => 0.30],
            'wind_speed' => ['source' => 'climatology', 'p50' => 12.0],
            'wind_gust' => ['source' => 'climatology', 'p50' => 18.0],
            'p_high_gust' => 0.99,
        ]);

        // 0.4 m waves are felt (Coach LC: from 0.3 m), so the worst-of floor makes it Safe
        $this->assertSame('Safe', $result['tier']);
        $this->assertSame($result['score_details']['classification_from_weighted_score'], 'Safe');
        $this->assertFalse($result['score_details']['p_high_gust_used']);
        $this->assertFalse($result['score_details']['hard_gate_triggered']);
    }
}
