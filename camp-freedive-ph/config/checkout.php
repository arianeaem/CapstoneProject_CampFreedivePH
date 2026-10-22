<?php

return [

    /*
    | Checkout intents (Option B): an unpaid checkout creates a checkout intent instead of a booking.
    | Off by default. This is a temporary rollout switch, not permission to keep unpaid bookings:
    | do not turn it on anywhere until payment finalization (Stage B, Batch 2) is in place.
    */
    'intents_enabled' => (bool) env('CHECKOUT_INTENTS_ENABLED', false),

    /*
    | Payment reconciliation (checkout:reconcile): recovers payments whose return page and webhook were both
    | missed, and marks abandoned unpaid checkouts expired. Separate switch, OFF by default: deploying the code
    | does not start it (the scheduler and the command both check it). It does not depend on intents_enabled,
    | so checkouts started before the flag was turned off can still be recovered.
    */
    'reconcile_enabled' => (bool) env('CHECKOUT_RECONCILE_ENABLED', false),

    'reconcile' => [
        'batch_size' => (int) env('CHECKOUT_RECONCILE_BATCH_SIZE', 25),
        'scan_limit' => 500,               // rows read per group per run, continuing from a per-group cursor (bounded)
        'max_run_seconds' => 240,          // stop taking new candidates after this (scheduler runs every 5 minutes)
        'min_age_minutes' => 10,           // give the return page and the webhook time first
        'expire_after_hours' => 24,        // unpaid this long (hold over, PayMongo shows no payment) -> close the PayMongo session
        'post_close_days' => 7,            // after PayMongo confirmed the session closed: keep re-checking this long
        'unclosed_days' => 90,             // session never confirmed closed: keep checking this long, then flag for staff
        'unresolved_flag_hours' => 72,     // payment still in progress/unconfirmed this long after checkout creation: flag for
                                           // staff (never closed because of this; still checked until unclosed_days)
        'review_retry_hours' => 72,        // retry transient payment reviews this long
        // Minutes between attempts per checkout, decaying with age: [age limit in hours => interval in minutes]
        'pacing' => [1 => 5, 24 => 30, 168 => 360, 720 => 1440, PHP_INT_MAX => 10080],
        'post_close_pacing' => [24 => 360, PHP_INT_MAX => 1440],
        // Review reasons that can clear up on a later read (PayMongo not showing the session or payment yet).
        // Identity, amount, currency, mode and duplicate-ownership mismatches are never retried automatically.
        'transient_review_reasons' => ['session_not_found', 'unexpected_payment_status', 'payment_id_missing'],
    ],

];
