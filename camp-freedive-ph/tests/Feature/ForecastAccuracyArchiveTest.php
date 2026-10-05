<?php

namespace Tests\Feature;

use App\Models\ForecastAccuracyLog;
use App\Models\ForecastSnapshot;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForecastAccuracyArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected WeatherForecastService $forecastService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forecastService = app(WeatherForecastService::class);
    }

    /**
     * Helper to mock calm sea Open-Meteo responses resulting in "Safe" / "Very Safe" classification.
     */
    protected function mockCalmOpenMeteoWeather(): void
    {
        Http::fake([
            'marine-api.open-meteo.com/*' => Http::response([
                'hourly' => [
                    'wave_height' => array_fill(0, 24, 0.50),
                    'swell_wave_height' => array_fill(0, 24, 0.40),
                    'wave_period' => array_fill(0, 24, 7.0),
                    'wind_wave_height' => array_fill(0, 24, 0.20),
                    'ocean_current_velocity' => array_fill(0, 24, 0.90), // ~0.25 m/s
                ],
            ], 200),
            'archive-api.open-meteo.com/*' => Http::response([
                'hourly' => [
                    'wind_speed_10m' => array_fill(0, 24, 10.0),
                    'wind_gusts_10m' => array_fill(0, 24, 12.0),
                    'rain' => array_fill(0, 24, 0.0),
                    'pressure_msl' => array_fill(0, 24, 1012.0),
                    'wind_direction_10m' => array_fill(0, 24, 45.0),
                ],
            ], 200),
        ]);
    }

    /**
     * Test recording multi-horizon forecast snapshots into database.
     */
    public function test_record_forecast_snapshot_persists_multi_horizon_predictions(): void
    {
        $targetDate = Carbon::now(WeatherForecastService::TIMEZONE)->addDays(7)->format('Y-m-d');

        $summary = [
            'overall_classification' => 'Moderate',
            'overall_score_pct' => 48.5,
            'avg_wave_height' => 0.85,
            'avg_wind_speed' => 18.5,
            'avg_ocean_current' => 0.42,
            'total_rain' => 3.2,
            'avg_pressure' => 1008.2,
            'hourly' => [],
        ];

        $snapshot = $this->forecastService->recordForecastSnapshot($targetDate, 7, $summary, 'Moderate');

        $this->assertInstanceOf(ForecastSnapshot::class, $snapshot);
        $this->assertEquals($targetDate, $snapshot->target_date->format('Y-m-d'));
        $this->assertEquals(7, $snapshot->lead_time_days);
        $this->assertEquals('7 Days (1 Week)', $snapshot->lead_time_label);
        $this->assertEquals('Moderate', $snapshot->predicted_classification);
        $this->assertEquals(48.5, (float) $snapshot->predicted_score_pct);
        $this->assertEquals(0.85, (float) $snapshot->predicted_wave_height);
        $this->assertEquals(18.5, (float) $snapshot->predicted_wind_speed);
        $this->assertEquals(0.42, (float) $snapshot->predicted_ocean_current);
        $this->assertEquals(3.2, (float) $snapshot->predicted_rain);
        $this->assertEquals(1008.2, (float) $snapshot->predicted_pressure);
        $this->assertEquals('Moderate', $snapshot->ml_predicted_classification);

        $this->assertDatabaseHas('forecast_snapshots', [
            'lead_time_days' => 7,
            'lead_time_label' => '7 Days (1 Week)',
            'predicted_classification' => 'Moderate',
        ]);
    }

    /**
     * Test archiving forecast accuracy by comparing T-1, T-3, T-7, T-14 against realized observations T-0.
     */
    public function test_archive_forecast_accuracy_calculates_scientific_error_deltas_and_persists_logs(): void
    {
        $targetDate = Carbon::yesterday(WeatherForecastService::TIMEZONE)->startOfDay();
        $dateStr = $targetDate->format('Y-m-d');

        // Create historical snapshots for multiple horizons
        // Horizon 1 (24h): Very close to actuals
        ForecastSnapshot::create([
            'target_date' => $dateStr,
            'lead_time_days' => 1,
            'lead_time_label' => '24h (1 Day)',
            'predicted_classification' => 'Very Safe',
            'predicted_score_pct' => 18.0,
            'predicted_wave_height' => 0.52,
            'predicted_wind_speed' => 10.5,
            'predicted_ocean_current' => 0.26,
            'predicted_rain' => 0.0,
            'predicted_pressure' => 1012.0,
            'ml_predicted_classification' => 'Very Safe',
        ]);

        // Horizon 7 (7 Days): Slightly divergent
        ForecastSnapshot::create([
            'target_date' => $dateStr,
            'lead_time_days' => 7,
            'lead_time_label' => '7 Days (1 Week)',
            'predicted_classification' => 'Moderate',
            'predicted_score_pct' => 45.0,
            'predicted_wave_height' => 0.90,
            'predicted_wind_speed' => 18.0,
            'predicted_ocean_current' => 0.40,
            'predicted_rain' => 2.0,
            'predicted_pressure' => 1009.0,
            'ml_predicted_classification' => 'Very Safe',
        ]);

        $this->mockCalmOpenMeteoWeather();

        $result = $this->forecastService->archiveForecastAccuracy($targetDate, [1, 7]);

        $this->assertEquals($dateStr, $result['target_date']);
        $this->assertEquals('Very Safe', $result['actual_classification']);
        $this->assertEquals(0.50, $result['actual_metrics']['wave_height']);
        $this->assertEquals(10.0, $result['actual_metrics']['wind_speed']);
        $this->assertEquals(0.25, $result['actual_metrics']['ocean_current']);
        $this->assertEquals(2, $result['verified_count']);

        // Verify 24h Horizon log
        $this->assertDatabaseHas('forecast_accuracy_logs', [
            'lead_time_days' => 1,
            'predicted_classification' => 'Very Safe',
            'actual_classification' => 'Very Safe',
            'classification_matched' => true,
            'wave_height_error' => 0.02, // |0.52 - 0.50|
            'wind_speed_error' => 0.50, // |10.5 - 10.0|
            'current_error' => 0.01, // |0.26 - 0.25|
            'ml_classification_matched' => true,
        ]);

        // Verify 7d Horizon log
        $this->assertDatabaseHas('forecast_accuracy_logs', [
            'lead_time_days' => 7,
            'predicted_classification' => 'Moderate',
            'actual_classification' => 'Very Safe',
            'classification_matched' => false,
            'wave_height_error' => 0.40, // |0.90 - 0.50|
            'wind_speed_error' => 8.00, // |18.0 - 10.0|
            'current_error' => 0.15, // |0.40 - 0.25|
            'ml_classification_matched' => true,
        ]);

        // Verify Audit Log entry
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'FORECAST_ACCURACY_ARCHIVED',
        ]);
    }

    /**
     * Test calculation of accuracy scores based on error deltas.
     */
    public function test_calculate_accuracy_score_helper_scales_appropriately(): void
    {
        // Perfect match
        $perfectScore = ForecastAccuracyLog::calculateAccuracyScore(true, 0.0, 0.0, 0.0, 0.0);
        $this->assertEquals(100.0, $perfectScore);

        // Complete mismatch with large errors
        $poorScore = ForecastAccuracyLog::calculateAccuracyScore(false, 0.80, 20.0, 0.50, 10.0);
        $this->assertEquals(0.0, $poorScore);

        // Partial match with small delta
        $goodScore = ForecastAccuracyLog::calculateAccuracyScore(true, 0.10, 2.0, 0.05, 0.2);
        $this->assertGreaterThan(90.0, $goodScore);
    }

    /**
     * Test console command forecast:archive-accuracy.
     */
    public function test_archive_forecast_accuracy_artisan_command_executes(): void
    {
        $targetDate = Carbon::yesterday(WeatherForecastService::TIMEZONE)->startOfDay();
        $dateStr = $targetDate->format('Y-m-d');

        ForecastSnapshot::create([
            'target_date' => $dateStr,
            'lead_time_days' => 1,
            'lead_time_label' => '24h (1 Day)',
            'predicted_classification' => 'Very Safe',
            'predicted_score_pct' => 18.0,
            'predicted_wave_height' => 0.55,
            'predicted_wind_speed' => 11.0,
            'predicted_ocean_current' => 0.27,
            'predicted_rain' => 0.0,
            'predicted_pressure' => 1012.0,
        ]);

        $this->mockCalmOpenMeteoWeather();

        $this->artisan('forecast:archive-accuracy', [
            '--date' => $dateStr,
            '--lead-times' => '1',
        ])
            ->expectsOutputToContain("Target Audit Date (T-0): {$targetDate->format('Y-m-d (D)')}")
            ->expectsOutputToContain('Audit Summary Statistics:')
            ->assertExitCode(0);

        $this->assertDatabaseHas('forecast_accuracy_logs', [
            'lead_time_days' => 1,
            'predicted_classification' => 'Very Safe',
        ]);
    }
}
