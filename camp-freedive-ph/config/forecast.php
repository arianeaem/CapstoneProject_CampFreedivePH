<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Marine & Meteorological Forecast Coordinates
    |--------------------------------------------------------------------------
    |
    | Geographic site coordinates for Camp FreedivePH freediving operations
    | in Anilao / Mabini, Batangas (Bagalangit / Mainit Point).
    |
    */

    'site_lat' => (float) env('FORECAST_SITE_LAT', 13.6874),
    'site_lon' => (float) env('FORECAST_SITE_LON', 120.8931),
    'timezone' => env('FORECAST_TIMEZONE', 'Asia/Manila'),
    'site_name' => 'Camp FreedivePH (Anilao / Mabini, Batangas)',

    /*
    |--------------------------------------------------------------------------
    | Primary Forecast Source & Microservice Router
    |--------------------------------------------------------------------------
    |
    | 'prd': Production multi-source physics engine (POST /forecast/site)
    | 'legacy': Heuristic 16-day Open-Meteo sliding cache
    |
    */
    'forecast_source' => env('FORECAST_SOURCE', 'prd'),
    'api_url' => env('SAFETY_FORECAST_API_URL', 'http://127.0.0.1:8001'),
    'circuit_breaker' => [
        'timeout_seconds' => 5.0,
        'max_failures' => 5,
        'decay_seconds' => 300,
        'cache_ttl_seconds' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Verified Multi-Source Historical Training Window
    |--------------------------------------------------------------------------
    |
    | Canonical clean reanalysis/Final window without provisional data.
    | Aligns CMEMS Waves 1/12° Analysis, CMEMS SMOC Currents, ECMWF ERA5,
    | and NASA GPM IMERG Final Run V07B.
    |
    */
    'training_window' => [
        'start' => '2022-11-01',
        'end'   => '2025-09-30',
    ],

    /*
    |--------------------------------------------------------------------------
    | Operational Daytime Window (Asia/Manila PHT)
    |--------------------------------------------------------------------------
    |
    | In-water freediving operational hours: 06:00 to 18:00 PHT.
    | IMERG UTC Day D directly maps to Manila Day D (08:00–18:00 PHT is inside UTC Day D).
    |
    */
    'operational_hours' => [
        'start_hour' => 6,   // 06:00 PHT
        'end_hour'   => 18,  // 18:00 PHT
    ],

    /*
    |--------------------------------------------------------------------------
    | High Gust / Tactical Hard Gate Threshold
    |--------------------------------------------------------------------------
    |
    | A high gust event is flagged if any hour during 06:00–18:00 PHT has
    | wind gust >= 48.0 km/h (13.33 m/s). Matches the tactical Hard Gate limit.
    | Renamed from squall to high_gust per project terminology guidelines.
    |
    */
    'high_gust_event' => [
        'gust_threshold_kmh' => 48.0,
        'gust_threshold_ms'  => 13.333,
    ],
    // Backward compatibility alias
    'squall_event' => [
        'gust_threshold_kmh' => 48.0,
        'gust_threshold_ms'  => 13.333,
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Rain Bands (PLACEHOLDER - Pending Review with Coach LC & Team)
    |--------------------------------------------------------------------------
    |
    | Daily total precipitation from NASA GPM IMERG (mm/day).
    | Wet day threshold: daily total >= 1.0 mm/day.
    |
    | Band classification:
    |   Band 0: < 1 mm/day   (Dry / Negligible)
    |   Band 1: < 10 mm/day  (Light Rain)
    |   Band 2: < 25 mm/day  (Moderate Rain)
    |   Band 3: < 50 mm/day  (Heavy Rain)
    |   Band 4: >= 50 mm/day (Torrential / Extreme Rain)
    |
    */
    'rain' => [
        'wet_day_threshold_mm' => 1.0,
        'bands' => [
            ['band' => 0, 'max_mm' => 1.0,  'label' => 'Dry / None',      'description' => 'Negligible rain (< 1 mm/day)'],
            ['band' => 1, 'max_mm' => 10.0, 'label' => 'Light Rain',      'description' => 'Light showers (< 10 mm/day)'],
            ['band' => 2, 'max_mm' => 25.0, 'label' => 'Moderate Rain',   'description' => 'Moderate rainfall (< 25 mm/day)'],
            ['band' => 3, 'max_mm' => 50.0, 'label' => 'Heavy Rain',      'description' => 'Heavy rainfall (< 50 mm/day)'],
            ['band' => 4, 'max_mm' => null, 'label' => 'Torrential Rain', 'description' => 'Extreme/Torrential rain (>= 50 mm/day)'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Adverse Tail Quantile Definitions & Operational Comparison
    |--------------------------------------------------------------------------
    |
    | Statistical Tails:
    |   - P90 upper tail for: hs, swell_height, wind_wave_height, current_speed, wind_speed, wind_gust, rain_daily_mm
    |   - P10 lower tail for: tp, slp
    |
    | Tail Evaluation Rule:
    |   Evaluated strictly when source == 'model'.
    |   Applies the canonical scoring functions to upper quantiles (e.g. scoreWaveHeight on hs_p90,
    |   where 1.0m yields score 4 and 1.8m triggers the hard gate) and compares against the p50 tier.
    | The p90 comparison reuses the existing scoreWaveHeight and
    | scoreOceanCurrent bands. These are scoring thresholds, not a separate
    | tiering system: a p90 score of 4 raises an otherwise lower p50 tier to
    | High Risk, while the existing hard-gate ceilings raise it to Critical Risk.
    |
    */
    'adverse_tails' => [
        'p90' => ['hs', 'swell_height', 'wind_wave_height', 'current_speed', 'wind_speed', 'wind_gust', 'rain_daily_mm'],
        'p10' => ['tp', 'slp'],
        'thresholds' => [
            'hs' => [
                'elevate_score' => 1.00, // scoreWaveHeight() band 4 starts at 1.00 m
                'hard_gate'     => 1.80, // existing physics hard gate ceiling
            ],
            'current_speed' => [
                'elevate_score' => 0.80, // scoreOceanCurrent() hard-gate band
                'hard_gate'     => 0.80, // existing physics hard gate ceiling
            ],
        ],
    ],
];
