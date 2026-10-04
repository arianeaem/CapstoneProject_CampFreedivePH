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
];
