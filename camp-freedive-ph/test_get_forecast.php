<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\WeatherSafetyService;
use Carbon\Carbon;

$safety = app(WeatherSafetyService::class);
$sat = Carbon::now('Asia/Manila')->addDays(3);
$sun = $sat->copy()->addDay();
$res = $safety->getForecast($sat->format('Y-m-d'), $sun->format('Y-m-d'));
echo json_encode($res, JSON_PRETTY_PRINT);
