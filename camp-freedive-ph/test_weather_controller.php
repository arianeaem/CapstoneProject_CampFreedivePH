<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = app(\App\Http\Controllers\BookingController::class);

$dates = [
    ['start_date' => '2026-10-10', 'end_date' => '2026-10-11'],
    ['start_date' => '2026-10-24', 'end_date' => '2026-10-25'],
    ['start_date' => '2026-11-07', 'end_date' => '2026-11-08'],
];

foreach ($dates as $d) {
    echo "\n=== checkWeather for " . $d['start_date'] . " ===\n";
    $request = Illuminate\Http\Request::create('/api/weather/check', 'POST', $d);
    try {
        $response = $controller->checkWeather($request);
        echo "STATUS: " . $response->getStatusCode() . "\n";
        $data = $response->getData(true);
        echo "IS_BENCHMARK: " . ($data['is_benchmark'] ? 'true' : 'false') . "\n";
        echo "OVERALL_CLASS: " . ($data['overall_classification'] ?? 'null') . "\n";
        echo "DATA KEYS: " . implode(', ', array_keys($data)) . "\n";
        echo "DAY 1: " . json_encode($data['day1']) . "\n";
        echo "DAY 2: " . json_encode($data['day2']) . "\n";
    } catch (\Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
}
