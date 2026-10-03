<?php

namespace Tests\Feature;

use App\Services\WeatherSafetyMLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CircuitBreakerQuantileValidationTest extends TestCase
{
    use RefreshDatabase;

    protected WeatherSafetyMLService $mlService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mlService = app(WeatherSafetyMLService::class);
        $this->mlService->resetCircuit();
    }

    public function test_valid_quantile_response_succeeds_and_resets_breaker(): void
    {
        $validPhysics = [
            'significant_wave_height_m' => ['p10' => 0.40, 'p50' => 0.60, 'p90' => 0.85],
            'peak_period_s' => ['p10' => 5.5, 'p50' => 6.8, 'p90' => 8.0],
            'swell_height_m' => ['p10' => 0.20, 'p50' => 0.35, 'p90' => 0.50],
            'wind_wave_height_m' => ['p10' => 0.15, 'p50' => 0.25, 'p90' => 0.35],
            'wind_speed_kmh' => ['p10' => 10.0, 'p50' => 14.5, 'p90' => 19.0],
            'wind_gust_kmh' => ['p10' => 14.0, 'p50' => 19.0, 'p90' => 25.0],
            'wind_direction_deg' => ['p10' => 35.0, 'p50' => 45.0, 'p90' => 55.0],
            'sea_level_pressure_hpa' => ['p10' => 1010.0, 'p50' => 1012.5, 'p90' => 1014.0],
            'current_speed_ms' => ['p10' => 0.08, 'p50' => 0.15, 'p90' => 0.25],
            'current_direction_deg' => ['p10' => 195.0, 'p50' => 210.0, 'p90' => 225.0],
        ];

        Http::fake([
            '*/forecast' => Http::response([
                'horizon_hours' => 24,
                'physics_forecast' => $validPhysics,
                'metadata' => [],
                'generated_at' => now()->toIso8601String(),
            ], 200),
        ]);

        $result = $this->mlService->fetchPhysicsForecast(24);

        $this->assertNotNull($result);
        $status = $this->mlService->getCircuitStatus();
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_CLOSED, $status['state']);
        $this->assertEquals(0, $status['consecutive_failures']);
    }

    public function test_missing_quantile_field_increments_failure_counter_and_trips_breaker(): void
    {
        // Malformed: missing p90 and missing several quantile fields (e.g. legacy point-estimate response)
        $malformedPhysics = [
            'significant_wave_height_m' => 0.60, // Float instead of quantile object
            'wind_speed_kmh' => 15.0,
        ];

        Http::fake([
            '*/forecast' => Http::response([
                'horizon_hours' => 24,
                'physics_forecast' => $malformedPhysics,
            ], 200),
        ]);

        // Attempt 1: Should fail and increment failure count to 1
        $res1 = $this->mlService->fetchPhysicsForecast(24);
        $this->assertNull($res1);
        $this->assertEquals(1, $this->mlService->getCircuitStatus()['consecutive_failures']);
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_CLOSED, $this->mlService->getCircuitStatus()['state']);

        // Attempt 2: Failure count to 2
        $res2 = $this->mlService->fetchPhysicsForecast(24);
        $this->assertNull($res2);
        $this->assertEquals(2, $this->mlService->getCircuitStatus()['consecutive_failures']);

        // Attempt 3: Tripping threshold reached -> State becomes OPEN
        $res3 = $this->mlService->fetchPhysicsForecast(24);
        $this->assertNull($res3);
        $this->assertEquals(3, $this->mlService->getCircuitStatus()['consecutive_failures']);
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_OPEN, $this->mlService->getCircuitStatus()['state']);
        $this->assertFalse($this->mlService->isCircuitAvailable());

        // Attempt 4: Fail fast without calling HTTP endpoint
        Http::assertSentCount(3);
        $res4 = $this->mlService->fetchPhysicsForecast(24);
        $this->assertNull($res4);
        // Still 3 HTTP calls because circuit was open and failed fast
        Http::assertSentCount(3);
    }

    public function test_assess_booking_trips_circuit_if_physics_forecast_is_malformed(): void
    {
        Http::fake([
            '*/assess-booking' => Http::response([
                'planned_date' => '2026-09-15',
                'dive_start' => '08:00',
                'dive_end' => '12:00',
                'overall_recommendation' => 'Safe',
                'physics_forecast' => [
                    'significant_wave_height_m' => ['p10' => 'invalid_non_numeric', 'p50' => 0.60, 'p90' => 0.80],
                ],
            ], 200),
        ]);

        $boundary = [
            ['timestamp' => '2026-09-15T08:00:00+08:00', 'wind_speed' => 10.0, 'wind_gust' => 12.0, 'wind_dir' => 45.0, 'slp' => 1012.0, 'rain_rate_mm_hr' => 0.0],
        ];

        for ($i = 1; $i <= 3; $i++) {
            $res = $this->mlService->assessBookingSession('2026-09-15', '08:00', '12:00', $boundary);
            $this->assertNull($res);
        }

        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_OPEN, $this->mlService->getCircuitStatus()['state']);
    }
}
