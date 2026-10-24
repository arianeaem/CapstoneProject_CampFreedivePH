<?php

return [

    /*
    | Refund policy version stamped on every booking created from now on (bookings.refund_policy_version).
    | Bookings without a version were made under the earlier published terms (100% downpayment refund for
    | cancellations more than 14 days ahead); their refund requests always go to staff review and are never
    | repriced or paid automatically.
    |
    | Client-confirmed rules of this version:
    |  - voluntary cancellation: no downpayment refund (moving to another date stays available);
    |  - weather before diving: free move or the full downpayment back (anything paid beyond the downpayment
    |    is a staff decision, not automatic);
    |  - weather after the activity started: no refund;
    |  - camp operational cancellation: free move or everything paid back (minus earlier refunds).
    */
    'policy_version' => '2026-10-client-v1',

    // A refund submission that has been "submitting" this long without an answer is treated as an unknown outcome
    'stale_submission_minutes' => 10,

];
