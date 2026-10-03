<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting Configuration - Camp FreedivePH
    |--------------------------------------------------------------------------
    |
    | Defines configurable request limits and decay windows across sensitive,
    | public, API, and back-office endpoints. These can be adjusted via
    | environment variables in production.
    |
    */

    'general_api' => [
        'max_attempts' => (int) env('RATE_LIMIT_GENERAL_API', 100),
        'decay_minutes' => (int) env('RATE_LIMIT_GENERAL_API_DECAY', 15),
    ],

    'login' => [
        'max_attempts' => (int) env('RATE_LIMIT_LOGIN', 10),
        'decay_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY', 15),
    ],

    'password_reset' => [
        'max_attempts' => (int) env('RATE_LIMIT_PASSWORD_RESET', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_PASSWORD_RESET_DECAY', 15),
    ],

    'booking_create' => [
        'max_attempts' => (int) env('RATE_LIMIT_BOOKING', 10),
        'decay_minutes' => (int) env('RATE_LIMIT_BOOKING_DECAY', 15),
    ],

    'booking_quote_weather' => [
        'max_attempts' => (int) env('RATE_LIMIT_QUOTES', 60),
        'decay_minutes' => (int) env('RATE_LIMIT_QUOTES_DECAY', 1),
    ],

    'manage_lookup' => [
        'max_attempts' => (int) env('RATE_LIMIT_MANAGE_LOOKUP', 10),
        'decay_minutes' => (int) env('RATE_LIMIT_MANAGE_LOOKUP_DECAY', 15),
    ],

    'manage_requests' => [
        'max_attempts' => (int) env('RATE_LIMIT_MANAGE_REQUESTS', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_MANAGE_REQUESTS_DECAY', 15),
    ],

    'paymongo_checkout' => [
        'max_attempts' => (int) env('RATE_LIMIT_CHECKOUT', 10),
        'decay_minutes' => (int) env('RATE_LIMIT_CHECKOUT_DECAY', 15),
    ],

    'paymongo_webhook' => [
        'max_attempts' => (int) env('RATE_LIMIT_WEBHOOK', 120),
        'decay_minutes' => (int) env('RATE_LIMIT_WEBHOOK_DECAY', 1),
    ],

    'weather_sync' => [
        'max_attempts' => (int) env('RATE_LIMIT_WEATHER_SYNC', 10),
        'decay_minutes' => (int) env('RATE_LIMIT_WEATHER_SYNC_DECAY', 15),
    ],

    'ml_api' => [
        'max_attempts' => (int) env('RATE_LIMIT_ML_API', 60),
        'decay_minutes' => (int) env('RATE_LIMIT_ML_API_DECAY', 1),
    ],

];
