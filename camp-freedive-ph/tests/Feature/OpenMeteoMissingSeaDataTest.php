<?php

namespace Tests\Feature;

use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Open-Meteo's sea values stop ~9-10 days ahead (null), while weather covers 16 days.
 * Ratings must come from real Open-Meteo values only, never from made-up defaults.
 */
class OpenMeteoMissingSeaDataTest extends TestCase
{
    use RefreshDatabase;

    private function summaryFor(bool $withSea, float $gust = 20.0): array
    {
        Cache::flush();
        $date = Carbon::now(WeatherForecastService::TIMEZONE)->addDays(12)->format('Y-m-d');
        $times = array_map(fn ($h) => sprintf('%sT%02d:00', $date, $h), range(0, 23));
        $sea = fn (float $v) => array_fill(0, 24, $withSea ? $v : null);

        Http::fake([
            'marine-api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'wave_height' => $sea(0.2),
                'wave_period' => $sea(4.0),
                'swell_wave_height' => $sea(0.1),
                'wind_wave_height' => $sea(0.1),
                'ocean_current_velocity' => $sea(0.3),
                'swell_wave_period' => $sea(5.0),
                'wind_wave_period' => $sea(2.0),
            ]]),
            'api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'precipitation' => array_fill(0, 24, 0.0),
                'rain' => array_fill(0, 24, 0.0),
                'showers' => array_fill(0, 24, 0.0),
                'pressure_msl' => array_fill(0, 24, 1012.0),
                'wind_speed_10m' => array_fill(0, 24, 10.0),
                'wind_gusts_10m' => array_fill(0, 24, $gust),
                'wind_direction_10m' => array_fill(0, 24, 45.0),
            ]]),
        ]);

        return app(WeatherForecastService::class)->updateAllForecasts(16)['daily_summaries'][$date];
    }

    public function test_day_without_sea_data_is_not_rated_with_made_up_values(): void
    {
        $summary = $this->summaryFor(withSea: false);

        $this->assertSame('Not Available', $summary['overall_classification']);
        $this->assertFalse($summary['sea_data_available']);
        $this->assertNull($summary['avg_wave_height']);
        $this->assertNull($summary['avg_ocean_current']);
        $this->assertSame('Not Available', $summary['hourly'][11]['classification']);
        $this->assertNull($summary['hourly'][11]['wave_height']);
    }

    public function test_weather_hard_limit_still_counts_without_sea_data(): void
    {
        // Real Open-Meteo gusts of 50 km/h are Critical even with no sea data
        $summary = $this->summaryFor(withSea: false, gust: 50.0);

        $this->assertSame('Critical Risk', $summary['overall_classification']);
    }

    public function test_day_with_real_sea_data_is_rated_from_it(): void
    {
        $summary = $this->summaryFor(withSea: true);

        $this->assertTrue($summary['sea_data_available']);
        $this->assertSame(0.2, (float) $summary['avg_wave_height']);
        $this->assertContains($summary['overall_classification'], ['Very Safe', 'Safe']);
    }
}
