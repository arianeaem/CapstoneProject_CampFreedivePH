<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\WeatherForecastService;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;

$safetyService = app(WeatherSafetyService::class);
$forecastService = app(WeatherForecastService::class);

echo "================================================================================\n";
echo "DIVE SAFETY EVALUATION - END-TO-END VERIFICATION\n";
echo "Current System Date: " . Carbon::today(WeatherForecastService::TIMEZONE)->format('Y-m-d') . "\n";
echo "Forecast Source     : " . config('forecast.forecast_source') . "\n";
echo "Adverse-tail rule    : p90 reuses scoreWaveHeight(); 1.00m enters band 4, 1.80m is the hard gate\n";
echo "================================================================================\n\n";

$testCases = [
    [
        'label' => '1. REAL "TODAY" / UPCOMING OCTOBER WEEKEND (Day 6-7)',
        'start_date' => '2026-10-10',
        'end_date'   => '2026-10-11',
    ],
    [
        'label' => '2. MID-RANGE OCTOBER WEEKEND (Day 13-14)',
        'start_date' => '2026-10-17',
        'end_date'   => '2026-10-18',
    ],
    [
        'label' => '3. JANUARY AMIHAN (High Gust / Strong Wind Regime)',
        'start_date' => '2027-01-16',
        'end_date'   => '2027-01-17',
    ],
];

foreach ($testCases as $tc) {
    echo "--------------------------------------------------------------------------------\n";
    echo $tc['label'] . "\n";
    echo "Selected Dates: {$tc['start_date']} to {$tc['end_date']}\n";
    echo "--------------------------------------------------------------------------------\n";

    $start = Carbon::parse($tc['start_date']);
    $end = Carbon::parse($tc['end_date']);

    // 1. Test /api/weather/preview via previewDateAssessment
    $preview = $forecastService->previewDateAssessment($start);

    echo "PREVIEW ASSESSMENT STATUS: " . ($preview['available'] ? 'AVAILABLE' : 'UNAVAILABLE') . "\n";
    echo "Overall Classification   : " . ($preview['overall_classification'] ?? 'N/A') . "\n";
    $relLabel = is_array($preview['reliability'] ?? null) ? ($preview['reliability']['label'] ?? 'N/A') : ($preview['reliability'] ?? 'N/A');
    echo "Reliability Category     : " . $relLabel . "\n";
    echo "Overall Confidence       : " . ($preview['confidence'] ?? 'N/A') . "\n";
    echo "Confidence Advisory      : " . ($preview['confidence_advisory'] ?? 'None') . "\n";
    echo "Data Source              : " . ($preview['data_source'] ?? 'N/A') . "\n\n";

    // Day 1
    $d1 = $preview['day1'] ?? [];
    echo "  [DAY 1 - {$d1['date']}]\n";
    echo "    Classification       : " . ($d1['classification'] ?? 'N/A') . "\n";
    echo "    Peak / Worst Hour    : " . ($d1['worst_hour'] ?? 'N/A') . "\n";
    echo "    Operational Window   : " . ($d1['operational_hours']['worst_tier'] ?? 'N/A') . " (Peak at " . ($d1['operational_hours']['worst_hour'] ?? 'N/A') . ")\n";
    echo "    Window Label         : " . ($d1['operational_hours']['label'] ?? 'N/A') . "\n";
    echo "    AM Window (09:30-12) : " . ($d1['am']['classification'] ?? 'N/A') . "\n";
    echo "    PM Window (15:30-17) : " . ($d1['pm']['classification'] ?? 'N/A') . "\n";

    if (!empty($d1['physics'])) {
        $p = $d1['physics'];
        echo "    Physical Conditions:\n";
        echo "      - Significant Wave (Hs) : p50=" . $p['hs_p50_m'] . "m, p90=" . $p['hs_p90_m'] . "m (Source: {$p['hs_source']})\n";
        echo "      - Ocean Current         : p50=" . $p['current_speed_p50_ms'] . "m/s, p90=" . $p['current_speed_p90_ms'] . "m/s (Source: {$p['current_source']})\n";
        echo "      - Wind Speed (RAW SI)   : " . $p['wind_speed_ms'] . " m/s\n";
        echo "      - Wind Speed (KM/H)     : " . $p['wind_speed_kmh'] . " km/h (Evaluated against criteria)\n";
        echo "      - Peak Wind Gust        : " . $p['wind_gust_ms'] . " m/s (=" . $p['wind_gust_kmh'] . " km/h)\n";
        echo "      - Daily Rain Total      : " . $p['rain_daily_mm'] . " mm ({$p['rain_label']}, Band {$p['rain_score']})\n";
        echo "      - Wet Day Probability   : " . $p['p_wet'] . "\n";
        echo "      - High Gust Probability : " . $p['p_high_gust'] . "\n";
        $sd = $d1['operational_hours']['score_details'] ?? [];
        echo "    Weighted Score Breakdown (worst daytime hour):\n";
        echo "      - Weighted score        : " . ($sd['weighted_score_pct'] ?? 'N/A') . "%\n";
        echo "      - Variable scores       : " . json_encode($sd['scores'] ?? []) . "\n";
        echo "      - Hard gate triggered   : " . (($sd['hard_gate_triggered'] ?? false) ? 'yes' : 'no') . "\n";
        echo "      - P(high_gust) as input : " . (($sd['p_high_gust_used'] ?? false) ? 'yes' : 'no (advisory only)') . "\n";
    }
    echo "\n";

    // Day 2
    $d2 = $preview['day2'] ?? [];
    echo "  [DAY 2 - {$d2['date']}]\n";
    echo "    Classification       : " . ($d2['classification'] ?? 'N/A') . "\n";
    echo "    Peak / Worst Hour    : " . ($d2['worst_hour'] ?? 'N/A') . "\n";
    echo "    Operational Window   : " . ($d2['operational_hours']['worst_tier'] ?? 'N/A') . " (Peak at " . ($d2['operational_hours']['worst_hour'] ?? 'N/A') . ")\n";
    echo "    Window Label         : " . ($d2['operational_hours']['label'] ?? 'N/A') . "\n";
    echo "    AM Window (09:30-12) : " . ($d2['am']['classification'] ?? 'N/A') . "\n";
    echo "    PM Window (15:30-17) : " . ($d2['pm']['classification'] ?? 'N/A') . "\n";

    if (!empty($d2['physics'])) {
        $p = $d2['physics'];
        echo "    Physical Conditions:\n";
        echo "      - Significant Wave (Hs) : p50=" . $p['hs_p50_m'] . "m, p90=" . $p['hs_p90_m'] . "m (Source: {$p['hs_source']})\n";
        echo "      - Ocean Current         : p50=" . $p['current_speed_p50_ms'] . "m/s, p90=" . $p['current_speed_p90_ms'] . "m/s (Source: {$p['current_source']})\n";
        echo "      - Wind Speed (RAW SI)   : " . $p['wind_speed_ms'] . " m/s\n";
        echo "      - Wind Speed (KM/H)     : " . $p['wind_speed_kmh'] . " km/h\n";
        echo "      - Peak Wind Gust        : " . $p['wind_gust_ms'] . " m/s (=" . $p['wind_gust_kmh'] . " km/h)\n";
        echo "      - Daily Rain Total      : " . $p['rain_daily_mm'] . " mm ({$p['rain_label']}, Band {$p['rain_score']})\n";
        echo "      - Wet Day Probability   : " . $p['p_wet'] . "\n";
        echo "      - High Gust Probability : " . $p['p_high_gust'] . "\n";
        $sd = $d2['operational_hours']['score_details'] ?? [];
        echo "    Weighted Score Breakdown (worst daytime hour):\n";
        echo "      - Weighted score        : " . ($sd['weighted_score_pct'] ?? 'N/A') . "%\n";
        echo "      - Variable scores       : " . json_encode($sd['scores'] ?? []) . "\n";
        echo "      - Hard gate triggered   : " . (($sd['hard_gate_triggered'] ?? false) ? 'yes' : 'no') . "\n";
        echo "      - P(high_gust) as input : " . (($sd['p_high_gust_used'] ?? false) ? 'yes' : 'no (advisory only)') . "\n";
    }
    echo "\n";

    // 2. Test BookingController::checkWeather endpoint output (used by client booking UI)
    $clientRes = $safetyService->getForecast($start, $end);
    echo "CLIENT UI WIDGET (Dive Safety Evaluation Payload):\n";
    echo "  - Is Benchmark         : " . ($clientRes['is_benchmark'] ? 'true (Seasonal Baseline)' : 'false (Operational Multi-Source)') . "\n";
    echo "  - Overall Title        : " . ($clientRes['title'] ?? 'N/A') . "\n";
    echo "  - Overall Badge Color  : " . ($clientRes['badge_color'] ?? 'N/A') . "\n";
    echo "  - Description          : " . ($clientRes['description'] ?? 'N/A') . "\n";
    echo "  - Day 1 Wave / Current : " . ($clientRes['day1']['wave_height_m'] ?? 'N/A') . "m / " . ($clientRes['day1']['current_speed_ms'] ?? 'N/A') . " m/s\n";
    echo "  - Day 1 Wind / Gust    : " . ($clientRes['day1']['wind_speed_kmh'] ?? 'N/A') . " km/h / " . ($clientRes['day1']['wind_gust_kmh'] ?? 'N/A') . " km/h\n";
    echo "  - Day 1 Rain / Gust %  : " . ($clientRes['day1']['rain_daily_mm'] ?? 'N/A') . " mm / P(high_gust)=" . (is_numeric($clientRes['day1']['p_high_gust'] ?? null) ? round($clientRes['day1']['p_high_gust'] * 100) . '%' : ($clientRes['day1']['p_high_gust'] ?? 'N/A')) . "\n";
    echo "\n";
}

echo "--------------------------------------------------------------------------------\n";
echo "4. HISTORICAL REPLAY (June 16, 2025)\n";
echo "--------------------------------------------------------------------------------\n";
$replay = $forecastService->previewDateAssessment(Carbon::parse('2025-06-16'), true);
echo "Label                  : " . ($replay['historical_replay_label'] ?? 'N/A') . "\n";
echo "Source                 : " . ($replay['day1']['physics']['hs_source'] ?? 'N/A') . "\n";
echo "Current source         : " . ($replay['day1']['physics']['current_source'] ?? 'N/A') . "\n";
echo "Replay date            : " . ($replay['start_date'] ?? 'N/A') . "\n";
