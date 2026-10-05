<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$dates = [
    ['start_date' => '2026-10-10', 'end_date' => '2026-10-11'],
    ['start_date' => '2026-10-24', 'end_date' => '2026-10-25'],
    ['start_date' => '2026-11-07', 'end_date' => '2026-11-08'],
];

foreach ($dates as $d) {
    echo "\n=== POST /api/weather/check for " . $d['start_date'] . " ===\n";
    $request = Illuminate\Http\Request::create('/api/weather/check', 'POST', $d);
    $request->headers->set('Accept', 'application/json');
    $response = $kernel->handle($request);
    echo "STATUS: " . $response->getStatusCode() . "\n";
    echo "CONTENT: " . substr($response->getContent(), 0, 400) . "\n";
}
