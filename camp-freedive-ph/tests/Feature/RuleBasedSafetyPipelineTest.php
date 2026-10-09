<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Services\WeatherForecastService;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Open-Meteo -> rules -> classification, with no ML model in between.
 * Any HTTP call other than Open-Meteo (e.g. the old model service on :8001) fails the test.
 */
class RuleBasedSafetyPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** Sea data for the first 10 days only, like Open-Meteo's marine range. */
    private const SEA_DAYS = 10;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
    }

    /** @param float $currentKmh Open-Meteo's ocean_current_velocity (km/h) */
    private function fakeOpenMeteo(float $currentKmh = 0.3): void
    {
        $today = Carbon::today(WeatherForecastService::TIMEZONE);
        $times = [];
        $hasSea = [];
        for ($d = 0; $d < WeatherForecastService::MAX_FORECAST_DAYS; $d++) {
            foreach (range(0, 23) as $h) {
                $times[] = $today->copy()->addDays($d)->format('Y-m-d') . sprintf('T%02d:00', $h);
                $hasSea[] = $d < self::SEA_DAYS;
            }
        }
        $sea = fn (float $v) => array_map(fn ($ok) => $ok ? $v : null, $hasSea);
        $all = fn (float $v) => array_fill(0, count($times), $v);

        Http::preventStrayRequests();
        Http::fake([
            'marine-api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'wave_height' => $sea(0.2),
                'wave_period' => $sea(6.0),
                'swell_wave_height' => $sea(0.1),
                'wind_wave_height' => $sea(0.1),
                'ocean_current_velocity' => $sea($currentKmh),
                'swell_wave_period' => $sea(5.0),
                'wind_wave_period' => $sea(2.0),
            ]]),
            'api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'precipitation' => $all(0.0),
                'rain' => $all(0.0),
                'showers' => $all(0.0),
                'pressure_msl' => $all(1013.0),
                'wind_speed_10m' => $all(8.0),
                'wind_gusts_10m' => $all(12.0),
                'wind_direction_10m' => $all(45.0),
            ]]),
        ]);
    }

    private function batchStartingIn(int $days): Batch
    {
        $start = Carbon::today(WeatherForecastService::TIMEZONE)->addDays($days);

        return Batch::create([
            'name' => "Batch +{$days}",
            'batch_code' => "RULES-{$days}",
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'status' => 'open',
        ]);
    }

    public function test_batch_days_are_rated_from_open_meteo_with_rules_only(): void
    {
        $this->fakeOpenMeteo();

        $result = app(WeatherForecastService::class)->assessBatch($this->batchStartingIn(3));

        $this->assertContains($result['day1']['classification'], ['Very Safe', 'Safe']);
        $this->assertContains($result['day2']['classification'], ['Very Safe', 'Safe']);
        $this->assertArrayNotHasKey('ml_assessment', $result);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '8001'));
    }

    public function test_strong_current_alone_makes_the_day_high_risk(): void
    {
        // 2.0 km/h = 0.56 m/s: current score 3, which floors the day at High Risk
        $this->fakeOpenMeteo(currentKmh: 2.0);

        $result = app(WeatherForecastService::class)->assessBatch($this->batchStartingIn(3));

        $this->assertSame('High Risk', $result['day1']['classification']);
        $this->assertSame('High Risk', $result['overall_classification']);
    }

    public function test_days_beyond_open_meteo_sea_data_are_not_rated(): void
    {
        $this->fakeOpenMeteo();
        $batch = $this->batchStartingIn(self::SEA_DAYS + 1);

        $result = app(WeatherForecastService::class)->assessBatch($batch);

        $this->assertSame('Not Available', $result['day1']['classification']);
        $this->assertNull($batch->fresh()->risk_classification);
    }

    public function test_booking_preview_rates_dates_in_range(): void
    {
        $this->fakeOpenMeteo();

        $preview = app(WeatherForecastService::class)
            ->previewDateAssessment(Carbon::today(WeatherForecastService::TIMEZONE)->addDays(2));

        $this->assertTrue($preview['available']);
        $this->assertContains($preview['overall_classification'], ['Very Safe', 'Safe']);
        $this->assertArrayNotHasKey('engines', $preview);
    }

    public function test_booking_shows_dates_without_sea_data_as_not_rated_but_bookable(): void
    {
        $this->fakeOpenMeteo();
        $start = Carbon::today(WeatherForecastService::TIMEZONE)->addDays(self::SEA_DAYS + 1);

        $forecast = app(WeatherSafetyService::class)->getForecast($start, $start->copy()->addDay());

        $this->assertSame('Not Available', $forecast['overall_classification']);
        $this->assertSame('not_available', $forecast['risk_level']);
        $this->assertTrue($forecast['is_bookable']);
        $this->assertArrayNotHasKey('wave_height_m', $forecast['day1']);
    }

    public function test_weighted_score_from_plain_scores(): void
    {
        $score = app(WeatherForecastService::class)->computeWeightedScore([
            'wave_height' => 1,
            'wind_speed' => 1,
            'ocean_current' => 0,
            'swell_height' => 1,
            'wave_period' => 0,
            'wind_wave_height' => 1,
            'rain' => 0,
            'sea_level_pressure' => 0,
            'wind_direction' => 0,
        ]);

        $this->assertIsFloat($score);
        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }
}
