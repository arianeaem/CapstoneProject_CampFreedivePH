<?php

return [

    /*
    | Checkout intents (Option B): an unpaid checkout creates a checkout intent instead of a booking.
    | Off by default. This is a temporary rollout switch, not permission to keep unpaid bookings:
    | do not turn it on anywhere until payment finalization (Stage B, Batch 2) is in place.
    */
    'intents_enabled' => (bool) env('CHECKOUT_INTENTS_ENABLED', false),

];
