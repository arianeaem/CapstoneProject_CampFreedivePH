<?php

return [

    /*
    |--------------------------------------------------------------------------
    | External API Quota & Rate Limiting Configuration
    |--------------------------------------------------------------------------
    |
    | Centralized settings for all third-party external APIs and microservices
    | used across Camp FreedivePH. Controls rate limits, cache TTLs, timeouts,
    | retry attempts, and exponential backoff strategies.
    |
    */

    'paymongo' => [
        'name' => 'PayMongo Payment Gateway',
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
        'rate_limit' => [
            'max_requests_per_minute' => (int) env('PAYMONGO_MAX_REQ_PER_MIN', 60),
            'max_requests_per_hour' => (int) env('PAYMONGO_MAX_REQ_PER_HOUR', 500),
            'max_requests_per_day' => (int) env('PAYMONGO_MAX_REQ_PER_DAY', 1000),
            'max_requests_per_month' => (int) env('PAYMONGO_MAX_REQ_PER_MONTH', 25000),
            'warning_threshold_pct' => (int) env('PAYMONGO_WARNING_THRESHOLD_PCT', 80),
            'critical_threshold_pct' => (int) env('PAYMONGO_CRITICAL_THRESHOLD_PCT', 90),
            'decay_seconds' => 60,
        ],
        'cache_ttl_seconds' => (int) env('PAYMONGO_CACHE_TTL', 300),
        'max_retries' => (int) env('PAYMONGO_MAX_RETRIES', 2),
        'backoff_base_ms' => (int) env('PAYMONGO_BACKOFF_BASE_MS', 500),
        'timeout_seconds' => (int) env('PAYMONGO_TIMEOUT_SECONDS', 15),
    ],

    'open_meteo' => [
        'name' => 'Open-Meteo Marine & Atmospheric API',
        'rate_limit' => [
            'max_requests_per_minute' => (int) env('OPEN_METEO_MAX_REQ_PER_MIN', 60),
            'max_requests_per_hour' => (int) env('OPEN_METEO_MAX_REQ_PER_HOUR', 3000),
            'max_requests_per_day' => (int) env('OPEN_METEO_MAX_REQ_PER_DAY', 8000), // 20% buffer below 10k/day free limit
            'max_requests_per_month' => (int) env('OPEN_METEO_MAX_REQ_PER_MONTH', 240000), // 20% buffer below 300k/month
            'warning_threshold_pct' => (int) env('OPEN_METEO_WARNING_THRESHOLD_PCT', 80),
            'critical_threshold_pct' => (int) env('OPEN_METEO_CRITICAL_THRESHOLD_PCT', 90),
            'decay_seconds' => 60,
        ],
        'master_cache_ttl_hours' => (int) env('OPEN_METEO_MASTER_CACHE_HOURS', 6),
        'single_date_cache_ttl_minutes' => (int) env('OPEN_METEO_DATE_CACHE_MINS', 60),
        'max_retries' => (int) env('OPEN_METEO_MAX_RETRIES', 2),
        'backoff_base_ms' => (int) env('OPEN_METEO_BACKOFF_BASE_MS', 300),
        'timeout_seconds' => (int) env('OPEN_METEO_TIMEOUT_SECONDS', 10),
    ],

];
