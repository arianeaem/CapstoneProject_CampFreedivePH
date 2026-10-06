<?php

namespace Tests\Feature;

use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The pressure-drop rule only makes a day Critical when the strong gust (or heavy rain)
 * happens in the same 3-hour window as the drop.
 */
class CompoundPressureBreachTest extends TestCase
{
    use RefreshDatabase;

    // Real Open-Meteo values for Oct 7, 2026, 06:00-18:00 (normal midday pressure dip)
    private const PRESSURE = [1012.1, 1013.0, 1013.7, 1014.3, 1014.2, 1013.5, 1012.4, 1011.6, 1010.7, 1010.5, 1011.0, 1011.5, 1011.8];
    private const WIND = [16.4, 15.4, 16.3, 16.1, 17.5, 21.5, 22.8, 22.8, 21.4, 20.7, 28.6, 28.2, 18.4];
    private const GUSTS = [25.6, 23.8, 23.4, 23.4, 24.1, 28.8, 31.0, 32.0, 31.0, 29.5, 42.8, 39.2, 38.2];

    private function classify(array $gusts): string
    {
        Cache::flush();
        $date = Carbon::now(WeatherForecastService::TIMEZONE)->addDays(2)->format('Y-m-d');
        $times = array_map(fn ($h) => sprintf('%sT%02d:00', $date, $h), range(0, 23));
        $day = fn (array $daytime, float $night) => array_merge(array_fill(0, 6, $night), $daytime, array_fill(0, 5, $night));

        Http::fake([
            'marine-api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'wave_height' => array_fill(0, 24, 0.2),
                'wave_period' => array_fill(0, 24, 3.0),
                'swell_wave_height' => array_fill(0, 24, 0.1),
                'wind_wave_height' => array_fill(0, 24, 0.15),
                'ocean_current_velocity' => array_fill(0, 24, 0.6),
            ]]),
            'api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'precipitation' => array_fill(0, 24, 0.0),
                'rain' => array_fill(0, 24, 0.0),
                'showers' => array_fill(0, 24, 0.0),
                'pressure_msl' => $day(self::PRESSURE, 1012.0),
                'wind_speed_10m' => $day(self::WIND, 15.0),
                'wind_gusts_10m' => $day($gusts, 20.0),
                'wind_direction_10m' => array_fill(0, 24, 110.0),
            ]]),
        ]);

        $summary = app(WeatherForecastService::class)->updateAllForecasts(3)['daily_summaries'][$date];

        return $summary['overall_classification'];
    }

    public function test_midday_pressure_dip_with_gust_at_another_time_is_not_critical(): void
    {
        // Drop is 10:00-14:00, the 42.8 km/h gust is at 16:00
        $this->assertNotSame('Critical Risk', $this->classify(self::GUSTS));
    }

    public function test_pressure_drop_with_gust_in_same_window_is_critical(): void
    {
        $gusts = self::GUSTS;
        $gusts[6] = 40.0; // 12:00, inside the 11:00-14:00 drop window

        $this->assertSame('Critical Risk', $this->classify($gusts));
    }
}
