<?php

namespace App\Services\Booking;

use Carbon\Carbon;
use RuntimeException;

/** A booking path tried to use, or create a batch on, a start date the camp is not running (G1). Nothing was written. */
class DateNotOperatingException extends RuntimeException
{
    public function __construct(public readonly OperatingDate $operatingDate)
    {
        parent::__construct('The camp is not running on ' . Carbon::parse($operatingDate->date)->format('M d, Y') . ' (its session was '
            . str_replace('_', ' ', (string) $operatingDate->closedStatus()) . '). Choose another date, or reopen the date in Batches first. Nothing was changed.');
    }
}
