<?php

namespace App\Services\Payment\Allocation;

/**
 * The default (and, until an authoritative instructor schedule is approved, the only) verifier: it never confirms
 * instructor capacity, so every allocation is blocked for staff scheduling review (P3: fail closed).
 *
 * Why the existing coach data cannot prove capacity (Batch 3-II B2.4 audit):
 *  - "assigned" coach availability is linked to a batch only through free-text notes ("Assigned to <batch>");
 *  - availability is recorded for the start date only, never for every day of a multi-day session;
 *  - coach assignment and unassignment take no locks shared with booking capacity, so a count checked here could be
 *    undone right after (CoachMatchingService), and the ratio can be overridden per participant.
 * Replacing this binding requires an approved, authoritative scheduling source and its own concurrency review.
 */
class UnconfirmedInstructorCapacity implements InstructorCapacityVerifier
{
    public function verify(string $startDate, string $endDate, int $additionalPax): InstructorCapacityResult
    {
        return InstructorCapacityResult::unverified('instructor_schedule_unconfirmed', ['start_date' => $startDate, 'end_date' => $endDate]);
    }
}
