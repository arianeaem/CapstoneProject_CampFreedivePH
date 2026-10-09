<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, PayMongo, and more.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paymongo' => [
        'secret_key' => env('PAYMONGO_SECRET_KEY'),
        'public_key' => env('PAYMONGO_PUBLIC_KEY'),
        'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),
    ],

    'ml' => [
        'token' => env('ML_API_TOKEN', ''),
    ],

    'ml_demand' => [
        'enabled_schedule' => (bool) env('ML_DEMAND_SCHEDULE_ENABLED', true),
        'retrain_script' => env('ML_DEMAND_RETRAIN_SCRIPT', base_path('../demand-forecast/retrain_pipeline.py')),
        'working_dir' => env('ML_DEMAND_WORKING_DIR', base_path('../demand-forecast')),
        'python_path' => env('DEMAND_FORECAST_PYTHON', env('PYTHON_PATH', 'python')),
    ],

];
