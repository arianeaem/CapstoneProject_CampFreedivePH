<?php

namespace Tests\Feature;

use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The pressure-drop (squall) rule only makes a day Critical when pressure falls >= 3 hPa in
 * 3 hours AND, in the same hours, gusts >= 38 km/h last 2+ hours (or heavy rain falls).
 */
class CompoundPressureBreachTest extends TestCase
{
    use RefreshDatabase;

    // Real Open-Meteo values for Oct 7, 2026, 06:00-18:00 (normal midday pressure dip)
    private const PRESSURE = [1012.1, 1013.0, 1013.7, 1014.3, 1014.2, 1013.5, 1012.4, 1011.6, 1010.7, 1010.5, 1011.0, 1011.5, 1011.8];
    private const WIND = [16.4, 15.4, 16.3, 16.1, 17.5, 21.5, 22.8, 22.8, 21.4, 20.7, 28.6, 28.2, 18.4];
    private const GUSTS = [25.6, 23.8, 23.4, 23.4, 24.1, 28.8, 31.0, 32.0, 31.0, 29.5, 42.8, 39.2, 38.2];

    private function classify(array $gusts, array $pressure = self::PRESSURE): string
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
                'pressure_msl' => $day($pressure, 1012.0),
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

    public function test_normal_midday_dip_with_one_gusty_hour_is_not_critical(): void
    {
        // 11:00-14:00 drop is 2.8 hPa (normal dip) and only 12:00 is gusty
        $gusts = self::GUSTS;
        $gusts[6] = 40.0;

        $this->assertNotSame('Critical Risk', $this->classify($gusts));
    }

    public function test_squall_drop_with_two_gusty_hours_in_same_window_is_critical(): void
    {
        $pressure = self::PRESSURE;
        $pressure[8] = 1010.2; // 11:00 -> 14:00 drops 3.3 hPa
        $gusts = self::GUSTS;
        $gusts[6] = 40.0; // 12:00
        $gusts[7] = 41.0; // 13:00

        $this->assertSame('Critical Risk', $this->classify($gusts, $pressure));
    }

    public function test_squall_drop_with_only_one_gusty_hour_is_not_critical(): void
    {
        $pressure = self::PRESSURE;
        $pressure[8] = 1010.2; // 3.3 hPa drop, but only 12:00 is gusty
        $gusts = self::GUSTS;
        $gusts[6] = 40.0;

        $this->assertNotSame('Critical Risk', $this->classify($gusts, $pressure));
    }
}
