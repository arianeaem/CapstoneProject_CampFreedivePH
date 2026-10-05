<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$safetyService = app(\App\Services\WeatherSafetyService::class);
$forecastService = app(\App\Services\WeatherForecastService::class);

echo "TODAY: " . \Carbon\Carbon::today(\App\Services\WeatherForecastService::TIMEZONE)->format('Y-m-d') . "\n";

$testDates = [
    \Carbon\Carbon::today(\App\Services\WeatherForecastService::TIMEZONE)->addDays(1)->format('Y-m-d'),
    \Carbon\Carbon::today(\App\Services\WeatherForecastService::TIMEZONE)->addDays(3)->format('Y-m-d'),
    \Carbon\Carbon::today(\App\Services\WeatherForecastService::TIMEZONE)->addDays(7)->format('Y-m-d'),
    \Carbon\Carbon::today(\App\Services\WeatherForecastService::TIMEZONE)->addDays(20)->format('Y-m-d'),
];

foreach ($testDates as $d) {
    echo "\n----------------------------------------------------\n";
    echo "TESTING DATE: $d\n";
    $start = \Carbon\Carbon::parse($d);
    $end = $start->copy()->addDay();
    try {
        $res = $safetyService->getForecast($start, $end);
        echo "SUCCESS getForecast:\n";
        echo "is_benchmark: " . ($res['is_benchmark'] ? 'true' : 'false') . "\n";
        echo "overall_classification: " . ($res['overall_classification'] ?? 'N/A') . "\n";
        echo "risk_level: " . ($res['risk_level'] ?? 'N/A') . "\n";
        echo "description: " . ($res['description'] ?? 'N/A') . "\n";
        echo "day1 date: " . ($res['day1']['date'] ?? 'N/A') . " - class: " . ($res['day1']['classification'] ?? 'N/A') . "\n";
        echo "day2 date: " . ($res['day2']['date'] ?? 'N/A') . " - class: " . ($res['day2']['classification'] ?? 'N/A') . "\n";
    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
}
