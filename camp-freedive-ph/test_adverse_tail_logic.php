<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\WeatherForecastService;

$service = app(WeatherForecastService::class);

echo "TESTING ADVERSE TAIL POLICY (Model vs Climatology):\n";

// Case A: Model source with high p90 (hs.p50 = 0.8m, but hs.p90 = 1.35m enters scoreWaveHeight band 4)
$modelHourly = [
    'hs' => ['source' => 'model', 'p50' => 0.8, 'p90' => 1.35],
    'current_speed' => ['source' => 'model', 'p50' => 0.3, 'p90' => 0.45],
    'wind_speed' => ['source' => 'climatology', 'p50' => 12.0],
    'wind_gust' => ['source' => 'climatology', 'p50' => 18.0],
];
$resA = $service->calculateTierAndLabel($modelHourly);
echo "1. SOURCE=MODEL with high P90:\n";
echo "   Tier  : " . $resA['tier'] . " (p50 tier plus model-only adverse-tail policy)\n";
echo "   Label : " . $resA['label'] . "\n";
echo "   Alert : " . ($resA['adverse_tail_triggered'] ? 'YES' : 'NO') . "\n\n";

// Case B: Climatology source with identical values (hs.p50 = 0.8m, hs.p90 = 1.35m)
$climHourly = [
    'hs' => ['source' => 'climatology', 'p50' => 0.8, 'p90' => 1.35],
    'current_speed' => ['source' => 'climatology', 'p50' => 0.3, 'p90' => 0.45],
    'wind_speed' => ['source' => 'climatology', 'p50' => 12.0],
    'wind_gust' => ['source' => 'climatology', 'p50' => 18.0],
];
$resB = $service->calculateTierAndLabel($climHourly);
echo "2. SOURCE=CLIMATOLOGY with identical P90:\n";
echo "   Tier  : " . $resB['tier'] . " (STRICTLY Safe from P50; NOT escalated!)\n";
echo "   Label : " . $resB['label'] . "\n";
echo "   Alert : " . ($resB['adverse_tail_triggered'] ? 'YES' : 'NO') . "\n";
