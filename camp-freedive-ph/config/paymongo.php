<?php

return [

    /*
    |--------------------------------------------------------------------------
    | PayMongo API Keys & Endpoints Configuration
    |--------------------------------------------------------------------------
    |
    | PayMongo credentials for handling online customer payments, hosted checkout
    | (QR Ph, GCash, BPI, Maya, Cards), webhooks, and refunds.
    |
    */

    'public_key' => env('PAYMONGO_PUBLIC_KEY', ''),

    'secret_key' => env('PAYMONGO_SECRET_KEY', ''),

    'webhook_signature_secret' => env('PAYMONGO_WEBHOOK_SIGNATURE_SECRET', ''),

    'base_url' => env('PAYMONGO_BASE_URL', 'https://api.paymongo.com/v1'),

    'currency' => env('PAYMONGO_CURRENCY', 'PHP'),

    /*
    |--------------------------------------------------------------------------
    | Supported Hosted Checkout Payment Method Types (QR Ph, GCash, BPI, Card, Maya)
    |--------------------------------------------------------------------------
    */
    'payment_method_types' => [
        'qrph',
        'gcash',
        'paymaya',
        'card',
        'grab_pay',
    ],

    /*
    |--------------------------------------------------------------------------
    | Options & Environment Fallback
    |--------------------------------------------------------------------------
    */
    'verify_ssl' => env('PAYMONGO_VERIFY_SSL', true),
    'timeout' => (int) env('PAYMONGO_TIMEOUT', 20),

];
