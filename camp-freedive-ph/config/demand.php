<?php

/*
|--------------------------------------------------------------------------
| Demand rules - SINGLE SOURCE OF TRUTH
|--------------------------------------------------------------------------
| High / Medium / Low demand and Peak / Shoulder / Off-Peak seasons are derived
| from the 553 actual Google Form registration records by the ML pipeline
| (demand-forecast/retrain_pipeline.py) and written to
| demand-forecast/demand_thresholds.json.
|
| Laravel only READS that file. Do not hardcode demand/season rules anywhere else;
| use App\Support\DemandRules instead.
|
| The defaults below are used ONLY if the JSON file is missing or unreadable (they
| equal the values currently in the file) and `rules_loaded` will be false so the
| problem is visible in the Demand Forecast module.
*/

// Local: the sibling demand-forecast/ folder. Deployed: only camp-freedive-ph/ is uploaded,
// so the deploy workflow copies the file into resources/ml/.
$candidates = [
    base_path('../demand-forecast/demand_thresholds.json'),
    base_path('resources/ml/demand_thresholds.json'),
];
$path = env('DEMAND_RULES_PATH')
    ?: (array_values(array_filter($candidates, 'is_readable'))[0] ?? $candidates[0]);
$rules = null;

if (is_string($path) && is_readable($path)) {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (
        is_array($decoded)
        && isset($decoded['demand_level']['low_max'], $decoded['demand_level']['medium_max'])
        && isset($decoded['season']['by_month'])
        && count($decoded['season']['by_month']) === 12
    ) {
        $rules = $decoded;
    }
}

return [
    'rules_path' => $path,
    'rules_loaded' => $rules !== null,
    'source' => $rules['source'] ?? 'built-in defaults (demand_thresholds.json not found)',
    'generated_at' => $rules['generated_at'] ?? null,
    'batches_used' => $rules['batches_used'] ?? null,

    // participants per batch: <= low_max = Low, <= medium_max = Medium, else High
    'low_max' => (float) ($rules['demand_level']['low_max'] ?? 10.0),
    'medium_max' => (float) ($rules['demand_level']['medium_max'] ?? 20.3),

    // calendar month (1-12) => Peak | Shoulder | Off-Peak
    'season_by_month' => $rules['season']['by_month'] ?? [
        '1' => 'Off-Peak', '2' => 'Shoulder', '3' => 'Peak', '4' => 'Peak',
        '5' => 'Shoulder', '6' => 'Peak', '7' => 'Off-Peak', '8' => 'Peak',
        '9' => 'Shoulder', '10' => 'Off-Peak', '11' => 'Off-Peak', '12' => 'Shoulder',
    ],
    'seasonal_index_by_month' => $rules['season']['seasonal_index_by_month'] ?? [],
    'peak_index' => (float) ($rules['season']['peak_index'] ?? 1.15),
    'offpeak_index' => (float) ($rules['season']['offpeak_index'] ?? 0.90),
];
