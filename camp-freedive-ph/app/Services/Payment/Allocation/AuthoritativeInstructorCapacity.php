<?php

namespace App\Services\Payment\Allocation;

use App\Services\Scheduling\CoachCapacityEngine;

/**
 * S4: Authoritative instructor-capacity verifier backed by the S1–S3 coach scheduling
 * data (CoachDateCommitment, staff-confirmed availability, CoachCapacityEngine).
 *
 * NOT BOUND in AppServiceProvider. The binding remains UnconfirmedInstructorCapacity
 * (fail-closed) until S1–S5 verification passes and a separate rollout decision is made.
 *
 * To test: inject this class directly in test setUp() or via app()->instance().
 * To activate: replace the AppServiceProvider binding — but NOT until these prerequisites are met:
 *   1. All S1–S5 tests pass (SQLite + PostgreSQL + concurrency).
 *   2. Staff workflow (S2) is reviewed and approved for production use.
 *   3. Existing batches have coach assignments migrated to CoachDateCommitment.
 *   4. Rollout approval from the project owner.
 */
class AuthoritativeInstructorCapacity implements InstructorCapacityVerifier
{
    public function __construct(protected CoachCapacityEngine $engine) {}

    public function verify(string $startDate, string $endDate, int $additionalPax): InstructorCapacityResult
    {
        $result = $this->engine->verifyCapacity($startDate, $endDate, $additionalPax);

        return $result['verified']
            ? InstructorCapacityResult::verified($result['details'])
            : InstructorCapacityResult::unverified($result['reason'], $result['details']);
    }
}
