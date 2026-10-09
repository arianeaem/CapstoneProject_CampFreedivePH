<?php

namespace Tests\Feature;

use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Hard-limit edge cases, using real Open-Meteo values for Anilao (06:00-18:00):
 * - Oct 12, 2026: normal midday pressure dip (2.7 hPa) with a single 38.9 km/h gust -> not a squall
 * - Oct 15, 2026: one 49 km/h gust at 18:00, after the dives -> High Risk, not Critical
 * - thunderstorm forecast during the dive hours -> at least High Risk
 */
class HardLimitEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private const OCT_12 = [
        'pressure' => [1012.2, 1013.1, 1013.5, 1013.9, 1013.9, 1013.2, 1012.4, 1011.2, 1010.8, 1010.8, 1011.1, 1012.0, 1012.5],
        'gust' => [38.2, 38.2, 40.0, 41.0, 38.9, 37.4, 33.5, 33.5, 32.8, 31.3, 31.3, 26.6, 23.0],
        'wind' => [26.8, 27.4, 29.7, 28.2, 27.4, 24.4, 24.7, 24.3, 22.2, 23.4, 19.0, 15.9, 15.3],
        'rain' => [0.1, 0.0, 0.1, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.2, 0.8, 2.4, 0.4],
        'current_ms' => [0.22, 0.17, 0.17, 0.22, 0.31, 0.42, 0.53, 0.53, 0.56, 0.50, 0.39, 0.28, 0.22],
        'wave' => [0.28, 0.28, 0.30, 0.30, 0.32, 0.32, 0.30, 0.28, 0.26, 0.24, 0.20, 0.18, 0.18],
    ];

    private const OCT_15 = [
        'pressure' => [1011.2, 1011.5, 1011.6, 1011.4, 1010.9, 1010.3, 1009.7, 1009.2, 1009.0, 1009.1, 1009.5, 1010.0, 1010.5],
        'gust' => [26.6, 27.7, 28.8, 29.5, 29.9, 30.2, 31.0, 31.7, 33.5, 36.4, 40.3, 44.6, 49.0],
        'wind' => [16.2, 17.2, 18.3, 19.4, 20.6, 21.8, 22.7, 23.8, 24.6, 25.5, 26.3, 27.5, 28.2],
        'rain' => [0.0, 0.0, 0.0, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.8, 0.8, 0.8, 0.8],
        'current_ms' => [0.19, 0.14, 0.11, 0.17, 0.14, 0.22, 0.25, 0.31, 0.36, 0.36, 0.31, 0.25, 0.14],
        'wave' => [0.14, 0.18, 0.20, 0.22, 0.22, 0.24, 0.24, 0.26, 0.26, 0.28, 0.32, 0.34, 0.36],
    ];

    /**
     * Daily summary for a day built from 06:00-18:00 values (night hours calm).
     *
     * @param array<int, int> $weatherCodes hour => WMO code
     */
    private function summary(array $day, array $weatherCodes = []): array
    {
        Cache::flush();
        $date = Carbon::now(WeatherForecastService::TIMEZONE)->addDays(2)->format('Y-m-d');
        $times = array_map(fn ($h) => sprintf('%sT%02d:00', $date, $h), range(0, 23));
        $hours = fn (array $daytime, float $night) => array_merge(array_fill(0, 6, $night), $daytime, array_fill(0, 5, $night));
        $codes = array_map(fn ($h) => $weatherCodes[$h] ?? 3, range(0, 23));

        // Open-Meteo reports the current in km/h
        $currentKmh = array_map(fn ($ms) => round($ms / 0.27778, 3), $day['current_ms']);

        Http::preventStrayRequests();
        Http::fake([
            'marine-api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'wave_height' => $hours($day['wave'], 0.2),
                'wave_period' => array_fill(0, 24, 4.0),
                'swell_wave_height' => array_fill(0, 24, 0.1),
                'wind_wave_height' => array_fill(0, 24, 0.15),
                'ocean_current_velocity' => $hours($currentKmh, 0.5),
                'swell_wave_period' => array_fill(0, 24, 5.0),
                'wind_wave_period' => array_fill(0, 24, 2.0),
            ]]),
            'api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'precipitation' => $hours($day['rain'], 0.0),
                'rain' => $hours($day['rain'], 0.0),
                'showers' => array_fill(0, 24, 0.0),
                'pressure_msl' => $hours($day['pressure'], 1012.0),
                'wind_speed_10m' => $hours($day['wind'], 10.0),
                'wind_gusts_10m' => $hours($day['gust'], 15.0),
                'wind_direction_10m' => array_fill(0, 24, 45.0),
                'weather_code' => $codes,
            ]]),
        ]);

        return app(WeatherForecastService::class)->updateAllForecasts(3)['daily_summaries'][$date];
    }

    public function test_oct_12_midday_pressure_dip_with_one_gusty_hour_is_not_critical(): void
    {
        $summary = $this->summary(self::OCT_12);

        // Not a squall. The current in the dive hours tops out at 0.53 m/s at 12:00: Moderate
        $this->assertSame('Moderate', $summary['overall_classification']);
    }

    public function test_oct_12_is_critical_when_the_drop_is_a_real_squall(): void
    {
        $day = self::OCT_12;
        $day['pressure'][7] = 1010.6; // 10:00 -> 13:00 drops 3.3 hPa
        $day['gust'][5] = 39.0;       // 11:00: second gusty hour in the same window

        $this->assertSame('Critical Risk', $this->summary($day)['overall_classification']);
    }

    public function test_oct_15_gust_at_18_00_only_is_high_risk_not_critical(): void
    {
        $summary = $this->summary(self::OCT_15);

        $this->assertSame('High Risk', $summary['overall_classification']);
        $this->assertSame('Critical Risk', $summary['hourly'][18]['classification']);
        $this->assertSame(
            'Roughest: Critical Risk at 6 PM · gusts 49 km/h',
            WeatherForecastService::peakLabel($summary['peak'])
        );
    }

    public function test_oct_15_is_critical_when_the_gust_comes_during_the_day(): void
    {
        $day = self::OCT_15;
        $day['gust'][11] = 48.5; // 17:00: end of the PM dive / boat ride back

        $this->assertSame('Critical Risk', $this->summary($day)['overall_classification']);
    }

    public function test_thunderstorm_during_dive_hours_is_at_least_high_risk(): void
    {
        $calm = self::OCT_15;
        $calm['gust'][12] = 30.0; // no 18:00 gust

        $summary = $this->summary($calm, [16 => 95]);

        $this->assertSame('High Risk', $summary['overall_classification']);
        $this->assertSame(
            'Roughest: High Risk at 4 PM · thunderstorm forecast',
            WeatherForecastService::peakLabel($summary['peak'])
        );
    }

    public function test_thunderstorm_keeps_a_rougher_peak_note(): void
    {
        $summary = $this->summary(self::OCT_15, [16 => 95]);

        $this->assertSame('High Risk', $summary['overall_classification']);
        $this->assertSame(
            'Roughest: Critical Risk at 6 PM · gusts 49 km/h',
            WeatherForecastService::peakLabel($summary['peak'])
        );
    }

    public function test_strong_current_in_the_midday_break_only_shows_as_the_roughest_note(): void
    {
        // Oct 11/13 pattern: 0.70 m/s from 13:00 to 15:00, between the AM and PM dives
        $day = self::OCT_12;
        $day['current_ms'] = [0.22, 0.17, 0.17, 0.22, 0.31, 0.42, 0.45, 0.70, 0.70, 0.70, 0.39, 0.28, 0.22];

        $summary = $this->summary($day);

        $this->assertSame('Moderate', $summary['overall_classification']);
        $this->assertSame('High Risk', $summary['peak']['classification']);
        $this->assertSame('1 PM', $summary['peak']['time']);
    }

    public function test_strong_current_during_a_dive_makes_the_day_high_risk(): void
    {
        // Same current, but during the AM dive (10:00-11:00)
        $day = self::OCT_12;
        $day['current_ms'] = [0.22, 0.17, 0.17, 0.22, 0.70, 0.70, 0.45, 0.42, 0.40, 0.40, 0.39, 0.28, 0.22];

        $this->assertSame('High Risk', $this->summary($day)['overall_classification']);
    }

    public function test_thunderstorm_outside_dive_hours_does_not_raise_the_day(): void
    {
        $calm = self::OCT_15;
        $calm['gust'][12] = 30.0;

        $summary = $this->summary($calm, [14 => 95]); // 2 PM: midday break

        $this->assertNotSame('High Risk', $summary['overall_classification']);
    }
}
