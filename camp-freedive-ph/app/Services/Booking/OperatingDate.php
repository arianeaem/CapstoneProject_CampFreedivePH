<?php

namespace App\Services\Booking;

use App\Models\Batch;

/**
 * Whether the camp runs on a start date (G1), read under that date's capacity lock.
 *
 * - An open, confirmed or full batch exists: operating. The date's batch is the earliest open or confirmed one,
 *   otherwise the earliest full one (a full camp still runs; the participant-capacity check refuses new places).
 * - No batch has ever existed for the date: operating; the first booking creates its batch.
 * - Otherwise every batch for the date is closed (cancelled, rescheduled, completed, or any unknown status): not
 *   operating. Nothing reopens such a date automatically; staff reopen it explicitly (set the batch back to confirmed,
 *   or create a new batch for the date).
 */
final class OperatingDate
{
    public function __construct(public readonly string $date, public readonly ?Batch $batch, public readonly ?Batch $closedBy)
    {
    }

    public function isOperating(): bool
    {
        return $this->batch !== null || $this->closedBy === null;
    }

    /** The closed batch's status (cancelled_by_camp, rescheduled, ...) for messages and evidence; null when operating. */
    public function closedStatus(): ?string
    {
        return $this->isOperating() ? null : $this->closedBy->status;
    }
}
