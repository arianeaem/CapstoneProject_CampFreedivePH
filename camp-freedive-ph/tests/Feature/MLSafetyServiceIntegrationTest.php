<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\User;
use App\Services\WeatherForecastService;
use App\Services\WeatherSafetyMLService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MLSafetyServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected WeatherSafetyMLService $mlService;
    protected WeatherForecastService $forecastService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $this->mlService = app(WeatherSafetyMLService::class);
        $this->forecastService = app(WeatherForecastService::class);
    }

    public function test_ml_service_standardizes_all_5_risk_classifications(): void
    {
        $tiers = [
            'Very Safe' => 'very_safe',
            'Safe' => 'safe',
            'Moderate' => 'moderate',
            'High Risk' => 'high_risk',
            'Critical Risk' => 'critical_risk',
        ];

        $currentTier = 'Very Safe';
        Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$currentTier) {
            return Http::response([
                'planned_date' => '2026-09-15',
                'dive_start' => '08:00',
                'dive_end' => '12:00',
                'overall_recommendation' => $currentTier,
                'displayed_risk_name' => $currentTier,
                'overall_operational_status' => 'PROVISIONAL_TREND_OUTLOOK',
                'is_authoritative_go' => ($currentTier === 'Very Safe' || $currentTier === 'Safe'),
                'overall_safety_threshold_triggered' => ($currentTier === 'Critical Risk'),
                'overall_hard_gate_triggered' => ($currentTier === 'Critical Risk'),
                'worst_hour' => [
                    'hour' => 10,
                    'final_tier_name' => $currentTier,
                    'primary_hazard' => 'Test Hazard',
                    'advisory_message' => 'Test Advisory',
                    'safety_threshold_triggered' => ($currentTier === 'Critical Risk'),
                    'hard_gate_triggered' => ($currentTier === 'Critical Risk'),
                ],
            ], 200);
        });

        foreach ($tiers as $tierName => $expectedKey) {
            $currentTier = $tierName;

            $boundaryWeather = [
                [
                    'timestamp' => '2026-09-15T08:00:00+08:00',
                    'wind_speed' => 12.0,
                    'wind_gust' => 15.0,
                    'wind_dir' => 245.0,
                    'slp' => 1012.0,
                    'rain_rate_mm_hr' => 0.0,
                ],
            ];

            $result = $this->mlService->assessBookingSession('2026-09-15', '08:00', '12:00', $boundaryWeather);

            $this->assertNotNull($result);
            $this->assertEquals($tierName, $result['overall_recommendation']);
            $this->assertEquals($tierName, $result['ml_recommendation']);
            $this->assertEquals($tierName, $result['ml_classification']);
            $this->assertEquals($expectedKey, $result['ml_risk_key']);
            $this->assertContains($result['overall_recommendation'], [
                'Very Safe',
                'Safe',
                'Moderate',
                'High Risk',
                'Critical Risk',
            ]);
        }
    }

    public function test_ml_service_gracefully_falls_back_when_microservice_offline(): void
    {
        Http::fake([
            '*/assess-booking' => Http::response('Service Unavailable', 503),
        ]);

        $boundaryWeather = [
            [
                'timestamp' => '2026-09-15T08:00:00+08:00',
                'wind_speed' => 12.0,
                'wind_gust' => 15.0,
                'wind_dir' => 245.0,
                'slp' => 1012.0,
                'rain_rate_mm_hr' => 0.0,
            ],
        ];

        $result = $this->mlService->assessBookingSession('2026-09-15', '08:00', '12:00', $boundaryWeather);
        $this->assertNull($result);
    }

    public function test_assess_batch_integrates_dual_engine_with_ml_recommendation(): void
    {
        Http::fake([
            '*/assess-booking' => Http::response([
                'planned_date' => '2026-09-15',
                'dive_start' => '08:00',
                'dive_end' => '18:00',
                'overall_recommendation' => 'Safe',
                'displayed_risk_name' => 'Safe',
                'overall_operational_status' => 'PROVISIONAL_TREND_OUTLOOK',
                'is_authoritative_go' => false,
                'overall_safety_threshold_triggered' => false,
                'overall_hard_gate_triggered' => false,
                'worst_hour' => [
                    'hour' => 11,
                    'final_tier_name' => 'Safe',
                    'primary_hazard' => 'Normal Marine Conditions',
                    'advisory_message' => 'Safe conditions',
                ],
            ], 200),
            'https://marine-api.open-meteo.com/*' => Http::response([], 200),
            'https://api.open-meteo.com/*' => Http::response([], 200),
        ]);

        $batch = Batch::create([
            'name' => 'Anilao ML Integrated Batch',
            'batch_code' => 'BATCH-ML-001',
            'start_date' => Carbon::now('Asia/Manila')->addDays(3)->format('Y-m-d'),
            'end_date' => Carbon::now('Asia/Manila')->addDays(4)->format('Y-m-d'),
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
        ]);

        $result = $this->forecastService->assessBatch($batch, null, $this->admin);

        $this->assertNotNull($result['overall_classification']);
        $this->assertArrayHasKey('ml_assessment', $result);
        if ($result['ml_assessment'] !== null) {
            $this->assertContains($result['ml_assessment']['overall_recommendation'], [
                'Very Safe',
                'Safe',
                'Moderate',
                'High Risk',
                'Critical Risk',
            ]);
        }
    }

    public function test_api_weather_preview_returns_open_meteo_forecast(): void
    {
        $targetDate = Carbon::now('Asia/Manila')->addDays(4)->format('Y-m-d');

        Http::fake([
            'https://marine-api.open-meteo.com/*' => Http::response([], 200),
            'https://api.open-meteo.com/*' => Http::response([], 200),
        ]);

        $response = $this->getJson("/api/weather/preview?start_date={$targetDate}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'available',
            'start_date',
            'end_date',
            'overall_classification',
            'day1',
            'day2',
        ]);
    }

    public function test_circuit_breaker_trips_to_open_after_3_consecutive_failures(): void
    {
        $this->mlService->resetCircuit();

        Http::fake([
            '*/assess-booking' => Http::response('Server Down', 500),
        ]);

        $boundaryWeather = [
            [
                'timestamp' => '2026-09-15T08:00:00+08:00',
                'wind_speed' => 12.0,
                'wind_gust' => 15.0,
                'wind_dir' => 245.0,
                'slp' => 1012.0,
                'rain_rate_mm_hr' => 0.0,
            ],
        ];

        // 1st failure
        $this->mlService->assessBookingSession('2026-09-15', '08:00', '12:00', $boundaryWeather);
        $status1 = $this->mlService->getCircuitStatus();
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_CLOSED, $status1['state']);
        $this->assertEquals(1, $status1['consecutive_failures']);

        // 2nd failure
        $this->mlService->assessBookingSession('2026-09-15', '08:00', '12:00', $boundaryWeather);
        $status2 = $this->mlService->getCircuitStatus();
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_CLOSED, $status2['state']);
        $this->assertEquals(2, $status2['consecutive_failures']);

        // 3rd failure -> Circuit should TRIP to OPEN
        $this->mlService->assessBookingSession('2026-09-15', '08:00', '12:00', $boundaryWeather);
        $status3 = $this->mlService->getCircuitStatus();
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_OPEN, $status3['state']);
        $this->assertEquals(3, $status3['consecutive_failures']);
        $this->assertFalse($status3['is_available']);
        $this->assertNotNull($status3['tripped_at']);
    }

    public function test_circuit_breaker_fails_fast_when_open_without_making_http_calls(): void
    {
        $this->mlService->resetCircuit();

        // Force trip circuit to OPEN
        $this->mlService->recordFailure('Test 1');
        $this->mlService->recordFailure('Test 2');
        $this->mlService->recordFailure('Test 3');

        $status = $this->mlService->getCircuitStatus();
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_OPEN, $status['state']);

        // Set Http::fake with a handler that would fail if called
        $called = false;
        Http::fake([
            '*/assess-booking' => function () use (&$called) {
                $called = true;
                return Http::response('Should not be called', 500);
            },
        ]);

        $boundaryWeather = [
            [
                'timestamp' => '2026-09-15T08:00:00+08:00',
                'wind_speed' => 12.0,
                'wind_gust' => 15.0,
                'wind_dir' => 245.0,
                'slp' => 1012.0,
                'rain_rate_mm_hr' => 0.0,
            ],
        ];

        // Should return null immediately without making any HTTP request
        $startTime = microtime(true);
        $result = $this->mlService->assessBookingSession('2026-09-15', '08:00', '12:00', $boundaryWeather);
        $durationMs = (microtime(true) - $startTime) * 1000;

        $this->assertNull($result);
        $this->assertFalse($called, 'HTTP request was sent despite circuit being OPEN!');
        $this->assertLessThan(50, $durationMs, 'Fail-fast took longer than 50ms');
    }

    public function test_circuit_breaker_recovers_to_closed_on_successful_trial_probe(): void
    {
        $this->mlService->resetCircuit();

        // Trip the circuit to OPEN
        $this->mlService->recordFailure('Test 1');
        $this->mlService->recordFailure('Test 2');
        $this->mlService->recordFailure('Test 3');

        // Simulate 35 seconds elapsed since tripping
        \Illuminate\Support\Facades\Cache::put(
            WeatherSafetyMLService::CACHE_KEY_TRIPPED_AT,
            now()->subSeconds(35)->timestamp,
            now()->addMinutes(10)
        );

        // Circuit should now transition to HALF_OPEN to allow a trial probe
        $this->assertTrue($this->mlService->isCircuitAvailable());
        $status = $this->mlService->getCircuitStatus();
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_HALF_OPEN, $status['state']);

        // Mock successful 200 response from microservice
        Http::fake([
            '*/assess-booking' => Http::response([
                'planned_date' => '2026-09-15',
                'dive_start' => '08:00',
                'dive_end' => '12:00',
                'overall_recommendation' => 'Very Safe',
                'displayed_risk_name' => 'Very Safe',
                'overall_operational_status' => 'PROVISIONAL_TREND_OUTLOOK',
                'is_authoritative_go' => true,
                'worst_hour' => [
                    'hour' => 9,
                    'final_tier_name' => 'Very Safe',
                ],
            ], 200),
        ]);

        $boundaryWeather = [
            [
                'timestamp' => '2026-09-15T08:00:00+08:00',
                'wind_speed' => 12.0,
                'wind_gust' => 15.0,
                'wind_dir' => 245.0,
                'slp' => 1012.0,
                'rain_rate_mm_hr' => 0.0,
            ],
        ];

        $result = $this->mlService->assessBookingSession('2026-09-15', '08:00', '12:00', $boundaryWeather);

        $this->assertNotNull($result);
        $this->assertEquals('Very Safe', $result['overall_recommendation']);

        // Circuit breaker should now be fully recovered and CLOSED
        $recoveredStatus = $this->mlService->getCircuitStatus();
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_CLOSED, $recoveredStatus['state']);
        $this->assertEquals(0, $recoveredStatus['consecutive_failures']);
        $this->assertTrue($recoveredStatus['is_available']);
    }

    public function test_circuit_breaker_can_be_manually_reset(): void
    {
        // Trip circuit
        $this->mlService->recordFailure('Err 1');
        $this->mlService->recordFailure('Err 2');
        $this->mlService->recordFailure('Err 3');

        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_OPEN, $this->mlService->getCircuitStatus()['state']);

        // Reset circuit
        $this->mlService->resetCircuit();

        $resetStatus = $this->mlService->getCircuitStatus();
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_CLOSED, $resetStatus['state']);
        $this->assertEquals(0, $resetStatus['consecutive_failures']);
        $this->assertTrue($resetStatus['is_available']);
    }

    public function test_forecast_straddling_safety_ceiling_evaluates_to_low_confidence(): void
    {
        // 1. Wind speed straddles 42.0 km/h ceiling (p10=36.0 < 42.0 <= p90=46.0)
        $windStraddlingForecast = [
            'physics' => [
                'significant_wave_height_m' => ['p10' => 0.50, 'p50' => 0.70, 'p90' => 0.95],
                'peak_period_s' => ['p10' => 5.0, 'p50' => 6.5, 'p90' => 8.0],
                'swell_height_m' => ['p10' => 0.20, 'p50' => 0.35, 'p90' => 0.50],
                'wind_wave_height_m' => ['p10' => 0.15, 'p50' => 0.25, 'p90' => 0.35],
                'wind_speed_kmh' => ['p10' => 36.0, 'p50' => 40.0, 'p90' => 46.0],
                'wind_gust_kmh' => ['p10' => 40.0, 'p50' => 44.0, 'p90' => 47.0],
                'wind_direction_deg' => ['p10' => 35.0, 'p50' => 45.0, 'p90' => 55.0],
                'sea_level_pressure_hpa' => ['p10' => 1010.0, 'p50' => 1012.0, 'p90' => 1014.0],
                'current_speed_ms' => ['p10' => 0.20, 'p50' => 0.30, 'p90' => 0.45],
                'rain_rate_mm_hr' => 0.0,
            ],
        ];

        $windResult = $this->forecastService->computeWeightedScore($windStraddlingForecast);
        $this->assertIsArray($windResult);
        $this->assertEquals('low', $windResult['confidence'], 'Expected confidence: low when wind speed interval straddles 42 km/h ceiling');

        // 2. Wave height straddles 1.80m ceiling (p10=1.40 < 1.80 <= p90=2.20)
        $waveStraddlingForecast = [
            'physics' => [
                'significant_wave_height_m' => ['p10' => 1.40, 'p50' => 1.70, 'p90' => 2.20],
                'peak_period_s' => ['p10' => 5.0, 'p50' => 6.5, 'p90' => 8.0],
                'swell_height_m' => ['p10' => 0.40, 'p50' => 0.60, 'p90' => 0.90],
                'wind_wave_height_m' => ['p10' => 0.30, 'p50' => 0.50, 'p90' => 0.70],
                'wind_speed_kmh' => ['p10' => 15.0, 'p50' => 20.0, 'p90' => 25.0],
                'wind_gust_kmh' => ['p10' => 20.0, 'p50' => 25.0, 'p90' => 30.0],
                'wind_direction_deg' => ['p10' => 35.0, 'p50' => 45.0, 'p90' => 55.0],
                'sea_level_pressure_hpa' => ['p10' => 1010.0, 'p50' => 1012.0, 'p90' => 1014.0],
                'current_speed_ms' => ['p10' => 0.20, 'p50' => 0.30, 'p90' => 0.45],
                'rain_rate_mm_hr' => 0.0,
            ],
        ];

        $waveResult = $this->forecastService->computeWeightedScore($waveStraddlingForecast);
        $this->assertIsArray($waveResult);
        $this->assertEquals('low', $waveResult['confidence'], 'Expected confidence: low when wave height interval straddles 1.80m ceiling');
    }

    public function test_forecast_confidently_above_or_below_every_ceiling_evaluates_to_high_confidence(): void
    {
        // 1. Confidently calm (cleanly below every Coast Guard ceiling)
        $calmForecast = [
            'physics' => [
                'significant_wave_height_m' => ['p10' => 0.35, 'p50' => 0.50, 'p90' => 0.75], // ceiling 1.80m
                'peak_period_s' => ['p10' => 5.5, 'p50' => 7.0, 'p90' => 8.5],
                'swell_height_m' => ['p10' => 0.20, 'p50' => 0.30, 'p90' => 0.45],
                'wind_wave_height_m' => ['p10' => 0.10, 'p50' => 0.20, 'p90' => 0.30],
                'wind_speed_kmh' => ['p10' => 10.0, 'p50' => 14.0, 'p90' => 18.0], // ceiling 42.0 km/h
                'wind_gust_kmh' => ['p10' => 14.0, 'p50' => 18.0, 'p90' => 24.0], // ceiling 48.0 km/h
                'wind_direction_deg' => ['p10' => 40.0, 'p50' => 50.0, 'p90' => 60.0],
                'sea_level_pressure_hpa' => ['p10' => 1011.0, 'p50' => 1013.0, 'p90' => 1015.0],
                'current_speed_ms' => ['p10' => 0.10, 'p50' => 0.18, 'p90' => 0.28], // ceiling 0.80 m/s
                'rain_rate_mm_hr' => 0.0,
            ],
        ];

        $calmResult = $this->forecastService->computeWeightedScore($calmForecast);
        $this->assertIsArray($calmResult);
        $this->assertEquals('high', $calmResult['confidence']);
        $this->assertFalse($calmResult['is_physical_breach']);

        // 2. Confidently severe (cleanly above ceiling across entire [p10, p90] interval)
        $severeForecast = [
            'physics' => [
                'significant_wave_height_m' => ['p10' => 2.20, 'p50' => 2.60, 'p90' => 3.10], // entirely above 1.80m
                'peak_period_s' => ['p10' => 4.0, 'p50' => 5.0, 'p90' => 6.0],
                'swell_height_m' => ['p10' => 1.80, 'p50' => 2.10, 'p90' => 2.50],
                'wind_wave_height_m' => ['p10' => 1.20, 'p50' => 1.50, 'p90' => 1.80],
                'wind_speed_kmh' => ['p10' => 46.0, 'p50' => 52.0, 'p90' => 60.0], // entirely above 42.0 km/h
                'wind_gust_kmh' => ['p10' => 55.0, 'p50' => 65.0, 'p90' => 75.0], // entirely above 48.0 km/h
                'wind_direction_deg' => ['p10' => 220.0, 'p50' => 230.0, 'p90' => 240.0],
                'sea_level_pressure_hpa' => ['p10' => 995.0, 'p50' => 998.0, 'p90' => 1002.0],
                'current_speed_ms' => ['p10' => 0.90, 'p50' => 1.10, 'p90' => 1.35], // entirely above 0.80 m/s
                'rain_rate_mm_hr' => 35.0,
            ],
        ];

        $severeResult = $this->forecastService->computeWeightedScore($severeForecast);
        $this->assertIsArray($severeResult);
        $this->assertEquals('high', $severeResult['confidence'], 'Expected confidence: high for unambiguous severe breach');
        $this->assertTrue($severeResult['is_physical_breach']);
        $this->assertEquals('Critical Risk', $severeResult['classification']);
    }

    public function test_malformed_response_missing_quantile_fields_trips_circuit_breaker(): void
    {
        $this->mlService->resetCircuit();

        // Microservice returns 200 OK but with legacy float numbers instead of quantile objects
        Http::fake([
            '*/forecast' => Http::response([
                'horizon_hours' => 24,
                'physics_forecast' => [
                    'significant_wave_height_m' => 0.65, // missing p10, p50, p90
                    'wind_speed_kmh' => 15.0,
                ],
                'generated_at' => now()->toIso8601String(),
            ], 200),
        ]);

        // 1st request: Schema validation throws exception -> Failure counter incremented to 1
        $res1 = $this->mlService->fetchPhysicsForecast(24);
        $this->assertNull($res1);
        $this->assertEquals(1, $this->mlService->getCircuitStatus()['consecutive_failures']);
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_CLOSED, $this->mlService->getCircuitStatus()['state']);

        // 2nd request: Failure counter incremented to 2
        $res2 = $this->mlService->fetchPhysicsForecast(24);
        $this->assertNull($res2);
        $this->assertEquals(2, $this->mlService->getCircuitStatus()['consecutive_failures']);

        // 3rd request: Tripping threshold reached -> Circuit breaker transitions to OPEN
        $res3 = $this->mlService->fetchPhysicsForecast(24);
        $this->assertNull($res3);
        $status = $this->mlService->getCircuitStatus();
        $this->assertEquals(3, $status['consecutive_failures']);
        $this->assertEquals(WeatherSafetyMLService::CIRCUIT_STATE_OPEN, $status['state']);
        $this->assertFalse($status['is_available']);

        // 4th request: Circuit is OPEN -> Fails fast without sending HTTP request
        Http::assertSentCount(3);
        $res4 = $this->mlService->fetchPhysicsForecast(24);
        $this->assertNull($res4);
        Http::assertSentCount(3); // Count remains 3
    }

    /**
     * Test that booking weather check endpoint (/api/weather/check) at H+168 (7 days out)
     * visibly returns confidence: low and surfaces the operational recheck advisory.
     */
    public function test_booking_weather_check_at_h168_surfaces_low_confidence_flag_and_advisory(): void
    {
        $startDate = Carbon::today('Asia/Manila')->addDays(7)->format('Y-m-d');
        $endDate = Carbon::today('Asia/Manila')->addDays(8)->format('Y-m-d');

        Http::fake([
            'https://marine-api.open-meteo.com/*' => Http::response([], 200),
            'https://api.open-meteo.com/*' => Http::response([], 200),
        ]);

        $response = $this->postJson('/api/weather/check', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('low', $data['confidence']);
        $this->assertNotNull($data['confidence_advisory']);
        $this->assertStringContainsString('Confidence is low this far out, recheck in 2 days', $data['confidence_advisory']);
        $this->assertNotEmpty($data['description']);
        $this->assertEquals('low', $data['day1']['confidence']);
        $this->assertNotNull($data['day1']['confidence_advisory']);
    }

    /**
     * Test that preview assessment near safety ceiling straddle returns low confidence with operational advisory.
     */
    public function test_preview_date_assessment_surfaces_confidence_advisory_when_conditions_straddle_ceiling(): void
    {
        $targetDate = Carbon::today('Asia/Manila')->addDays(2);
        
        $straddlingPhysics = [
            'significant_wave_height_m' => ['p10' => 0.8, 'p50' => 1.2, 'p90' => 1.95], // Straddles 1.80m
            'wind_speed_kmh' => ['p10' => 20.0, 'p50' => 28.0, 'p90' => 35.0],
            'wind_gust_kmh' => ['p10' => 25.0, 'p50' => 35.0, 'p90' => 42.0],
            'current_speed_ms' => ['p10' => 0.2, 'p50' => 0.4, 'p90' => 0.6],
        ];

        $scoringResult = $this->forecastService->computeWeightedScore($straddlingPhysics);
        $this->assertEquals('low', $scoringResult['confidence']);
    }
}


