<?php

namespace App\Services\Payment\Allocation;

/**
 * Proof that confirmed instructors cover a session (Batch 3-II B2.4, P3): for every date of the session, enough
 * confirmed coaches for the participants already going plus $additionalPax at the coach-to-student ratio. Called
 * inside the allocation transaction, under the session's capacity locks. An implementation must return verified only
 * from an authoritative schedule; "no conflicting assignment" is never proof of availability.
 */
interface InstructorCapacityVerifier
{
    public function verify(string $startDate, string $endDate, int $additionalPax): InstructorCapacityResult;
}
