<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\WeatherForecastService;
use Carbon\Carbon;

$service = app(WeatherForecastService::class);

echo "================================================================================\n";
echo "CAMP FREEDIVEPH MULTI-SOURCE FORECAST EVALUATION\n";
echo "================================================================================\n";
echo "Feature Flag FORECAST_SOURCE: " . config('forecast.forecast_source') . "\n";
echo "Microservice URL: " . config('forecast.api_url') . "\n\n";

// Let's test with a simulated issue time of 2025-06-15 12:00:00 (PHT)
$issuedAt = '2025-06-15 12:00:00';
$issuedCarbon = Carbon::parse($issuedAt, 'Asia/Manila');

// Days = 14 to cover beyond 10 days (up to 336 hours)
$result = $service->forecastSite(13.6874, 120.8931, $issuedCarbon->toIso8601String(), 14);

echo "Forecast Issued At: " . $result['issued_at'] . "\n";
echo "Degraded Status: " . ($result['degraded'] ? "TRUE ({$result['degraded_reason']})" : "FALSE (Live / Operational Store)") . "\n";
echo "Operational Cutoffs: hs=" . $result['operational_cutoffs']['hs_hours'] . "h, current=" . $result['operational_cutoffs']['current_speed_hours'] . "h\n\n";

// 3 Test Dates:
// 1. Sa loob ng cutoff: Day 1 (H = 24h)
// 2. Labas ng cutoff: Day 5 (H = 120h)
// 3. Higit 10 araw: Day 12 (H = 288h)
$testHorizons = [
    [
        'category' => '1. SA LOOB NG CUTOFF (Day 1 / 24h Ahead)',
        'target_h' => 24,
    ],
    [
        'category' => '2. LABAS NG CUTOFF (Day 5 / 120h Ahead)',
        'target_h' => 120,
    ],
    [
        'category' => '3. HIGIT 10 ARAW (Day 12 / 288h Ahead)',
        'target_h' => 288,
    ],
];

foreach ($testHorizons as $test) {
    echo "--------------------------------------------------------------------------------\n";
    echo $test['category'] . "\n";
    echo "--------------------------------------------------------------------------------\n";
    $h = $test['target_h'];
    
    // Find matching hour from forecast_hourly
    $hourData = null;
    foreach ($result['forecast_hourly'] as $row) {
        if ($row['lead_hours'] === $h) {
            $hourData = $row;
            break;
        }
    }

    if (!$hourData) {
        echo "Error: Hour H={$h} not found in hourly payload!\n";
        continue;
    }

    $forecastTime = Carbon::parse($hourData['forecast_time']);
    $targetDateStr = $forecastTime->format('Y-m-d');

    echo "Target Forecast Time : " . $hourData['forecast_time'] . "\n";
    echo "Lead Hours (H)       : " . $hourData['lead_hours'] . " hours\n";
    echo "Significant Wave (hs): Source = [" . strtoupper($hourData['hs']['source']) . "]"
        . " | p10=" . $hourData['hs']['p10'] . "m, p50=" . $hourData['hs']['p50'] . "m, p90=" . $hourData['hs']['p90'] . "m\n";
    echo "Current Speed        : Source = [" . strtoupper($hourData['current_speed']['source']) . "]"
        . " | p10=" . $hourData['current_speed']['p10'] . "m/s, p50=" . $hourData['current_speed']['p50'] . "m/s, p90=" . $hourData['current_speed']['p90'] . "m/s\n";
    echo "Wind Speed / Gust    : p50=" . $hourData['wind_speed']['p50'] . " km/h | Gust p50=" . $hourData['wind_gust']['p50'] . " km/h (Source: " . $hourData['wind_speed']['source'] . ")\n";
    echo "Operational Tier     : " . $hourData['tier'] . "\n";
    echo "Operational Label    : " . $hourData['label'] . "\n";
    echo "Adverse Tail Alert   : " . ($hourData['adverse_tail_triggered'] ? 'TRIGGERED (P90 Safety Escalation)' : 'None') . "\n";

    // Daily summary info
    $daySummary = null;
    foreach ($result['forecast_daily'] as $d) {
        if ($d['date'] === $targetDateStr) {
            $daySummary = $d;
            break;
        }
    }
    if ($daySummary) {
        echo "Daily Aggregates     : Date=" . $daySummary['date']
            . " | Rain p50=" . $daySummary['rain_daily_mm_p50'] . "mm (" . $daySummary['rain_label'] . ", Band " . $daySummary['rain_score'] . ")"
            . " | P(wet)=" . round($daySummary['p_wet'] * 100, 1) . "% [CI: " . round($daySummary['p_wet_ci'][0] * 100, 1) . "-" . round($daySummary['p_wet_ci'][1] * 100, 1) . "%]"
            . " | P(high_gust)=" . round($daySummary['p_high_gust'] * 100, 1) . "% [CI: " . round($daySummary['p_high_gust_ci'][0] * 100, 1) . "-" . round($daySummary['p_high_gust_ci'][1] * 100, 1) . "%]\n";
    }
    echo "\n";
}

echo "================================================================================\n";
echo "TESTING getCachedDayForecast() FOR DAY 1 ({$issuedCarbon->copy()->addDay()->format('Y-m-d')})\n";
echo "================================================================================\n";
$cachedDay = $service->getCachedDayForecast($issuedCarbon->copy()->addDay());
if ($cachedDay) {
    echo "Retrieved Cached Day: " . $cachedDay['date'] . "\n";
    echo "Day Tier: " . $cachedDay['day_tier'] . "\n";
    echo "Day Label: " . $cachedDay['day_label'] . "\n";
    echo "Rain Total: " . $cachedDay['rain_daily_mm'] . " mm (" . $cachedDay['rain_label'] . ")\n";
    echo "P(Wet Day): " . round($cachedDay['p_wet'] * 100, 1) . "%\n";
    echo "P(High Gust Day): " . round($cachedDay['p_high_gust'] * 100, 1) . "%\n";
    echo "Total Hourly Records in DB: " . count($cachedDay['hourly']) . " hours\n";
} else {
    echo "Failed retrieving cached day forecast!\n";
}
