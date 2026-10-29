<?php

namespace App\Services\Scheduling;

use App\Models\Batch;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachDateCommitment;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Authoritative coach scheduling domain logic (S1 foundation + S2 staff workflows).
 *
 * Calculates required coach counts, checks availability and conflicts, manages
 * authoritative date commitments, and handles staff confirmation of availability.
 * Does NOT activate the booking capacity gate — the InstructorCapacityVerifier binding
 * stays fail-closed until S1–S5 verification and separate rollout approval.
 */
class CoachSchedulingService
{
    private const MAX_RATIO = 4;

    public function ratio(): int
    {
        $configured = (int) (app(\App\Services\SystemSettingService::class)
            ->get('camp_operations.coach_student_ratio', self::MAX_RATIO) ?? self::MAX_RATIO);

        return max(1, min(self::MAX_RATIO, $configured));
    }

    public function requiredCoachCount(int $eligibleParticipants): int
    {
        if ($eligibleParticipants <= 0) {
            return 0;
        }

        return (int) ceil($eligibleParticipants / $this->ratio());
    }

    /**
     * Check if a coach has staff-confirmed availability on a specific date.
     * Self-declared availability without staff confirmation is not trusted.
     */
    public function hasConfirmedAvailability(Coach $coach, string $date): bool
    {
        if (! $coach->user_id) {
            return false;
        }

        return CoachAvailability::where('coach_id', $coach->user_id)
            ->whereDate('date', $date)
            ->where('status', 'available')
            ->whereNotNull('staff_confirmed_by')
            ->exists();
    }

    /**
     * Check if a coach already has a commitment on a specific date.
     */
    public function hasConflict(int $coachId, string $date): bool
    {
        return CoachDateCommitment::where('coach_id', $coachId)
            ->whereDate('date', $date)
            ->exists();
    }

    /**
     * Commit a coach to a batch for specific dates. Returns the created commitments.
     *
     * Validates:
     * - Coach is active
     * - Coach has staff-confirmed availability on each date
     * - No conflicting commitments exist (enforced by DB unique constraint)
     * - Assigner is an active owner or admin
     *
     * @return CoachDateCommitment[]
     * @throws CoachSchedulingException
     */
    public function commitCoach(Coach $coach, Batch $batch, array $dates, User $assignedBy): array
    {
        if ($coach->status !== 'active') {
            throw CoachSchedulingException::inactiveCoach($coach);
        }

        if (! in_array($assignedBy->role, ['admin', 'owner'], true) || $assignedBy->status !== 'active') {
            throw CoachSchedulingException::unauthorizedAssigner($assignedBy);
        }

        foreach ($dates as $date) {
            if (! $this->hasConfirmedAvailability($coach, $date)) {
                throw CoachSchedulingException::noConfirmedAvailability($coach, $date);
            }
        }

        $commitments = [];
        $now = now();

        try {
            DB::transaction(function () use ($coach, $batch, $dates, $assignedBy, $now, &$commitments) {
                foreach ($dates as $date) {
                    $commitments[] = CoachDateCommitment::create([
                        'coach_id' => $coach->id,
                        'batch_id' => $batch->id,
                        'date' => $date,
                        'assigned_by' => $assignedBy->id,
                        'assigned_at' => $now,
                    ]);
                }
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'coach_date_commitments_coach_id_date_unique')
                || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                throw CoachSchedulingException::dateConflict($coach, $dates);
            }
            throw $e;
        }

        return $commitments;
    }

    /**
     * Release a coach from a batch (delete commitment rows).
     */
    public function releaseCoach(Coach $coach, Batch $batch): int
    {
        return CoachDateCommitment::where('coach_id', $coach->id)
            ->where('batch_id', $batch->id)
            ->delete();
    }

    /**
     * Batch staffing state: committed coaches per date, required count, coverage assessment.
     */
    public function batchStaffingState(Batch $batch): array
    {
        $commitments = CoachDateCommitment::where('batch_id', $batch->id)
            ->with('coach')
            ->get();

        $eligiblePax = $this->eligibleParticipantCount($batch);
        $required = $this->requiredCoachCount($eligiblePax);

        $dates = $this->batchDates($batch);
        $perDate = [];
        foreach ($dates as $date) {
            $dateStr = Carbon::parse($date)->format('Y-m-d');
            $dateCommitments = $commitments->filter(fn ($c) => $c->date->format('Y-m-d') === $dateStr);
            $perDate[$dateStr] = [
                'coaches' => $dateCommitments->pluck('coach')->unique('id')->values(),
                'committed_count' => $dateCommitments->count(),
                'required_count' => $required,
                'covered' => $dateCommitments->count() >= $required,
            ];
        }

        return [
            'batch_id' => $batch->id,
            'eligible_participants' => $eligiblePax,
            'ratio' => $this->ratio(),
            'required_coaches' => $required,
            'dates' => $perDate,
            'fully_covered' => collect($perDate)->every(fn ($d) => $d['covered']),
        ];
    }

    public function eligibleParticipantCount(Batch $batch): int
    {
        return $batch->bookings()
            ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'no_show', 'completed'])
            ->withCount('participants')
            ->get()
            ->sum('participants_count');
    }

    /**
     * All dates in the batch's range (inclusive).
     */
    public function batchDates(Batch $batch): array
    {
        $dates = [];
        $current = $batch->start_date->copy();
        $end = $batch->end_date->copy();
        while ($current->lte($end)) {
            $dates[] = $current->format('Y-m-d');
            $current->addDay();
        }

        return $dates;
    }

    // ---------------------------------------------------------------- S2: Staff confirmation

    /**
     * Staff-confirm a coach's self-declared availability. The coach must have an existing
     * availability record with status 'available' on that date.
     */
    public function confirmAvailability(Coach $coach, string $date, User $confirmedBy): CoachAvailability
    {
        $this->assertStaffRole($confirmedBy);
        if ($confirmedBy->id === $coach->user_id) {
            throw CoachSchedulingException::selfConfirmation($coach);
        }

        $availability = CoachAvailability::where('coach_id', $coach->user_id)
            ->whereDate('date', $date)
            ->where('status', 'available')
            ->first();

        if (! $availability) {
            throw CoachSchedulingException::noAvailabilityToConfirm($coach, $date);
        }

        if ($availability->isStaffConfirmed()) {
            return $availability;
        }

        $availability->update([
            'staff_confirmed_by' => $confirmedBy->id,
            'staff_confirmed_at' => now(),
        ]);

        AuditLogger::log('COACH_AVAILABILITY_CONFIRMED', "Staff confirmed {$coach->full_name}'s availability on {$date}.", $confirmedBy, $confirmedBy->name);

        return $availability->fresh();
    }

    /**
     * Revoke staff confirmation. If the coach has an active commitment on that date, revocation
     * is refused — remove the commitment first.
     */
    public function revokeConfirmation(Coach $coach, string $date, User $revokedBy): CoachAvailability
    {
        $this->assertStaffRole($revokedBy);

        $availability = CoachAvailability::where('coach_id', $coach->user_id)
            ->whereDate('date', $date)
            ->whereNotNull('staff_confirmed_by')
            ->first();

        if (! $availability) {
            throw CoachSchedulingException::noConfirmationToRevoke($coach, $date);
        }

        if ($this->hasConflict($coach->id, $date)) {
            throw CoachSchedulingException::commitmentBlocksRevocation($coach, $date);
        }

        $availability->update([
            'staff_confirmed_by' => null,
            'staff_confirmed_at' => null,
        ]);

        AuditLogger::log('COACH_AVAILABILITY_REVOKED', "Staff revoked {$coach->full_name}'s confirmed availability on {$date}.", $revokedBy, $revokedBy->name);

        return $availability->fresh();
    }

    // ---------------------------------------------------------------- S2: Coach roster for batch staffing

    /**
     * Active coaches with their availability and commitment status for each date of a batch.
     *
     * @return Collection<int, array{coach: Coach, dates: array<string, array{available: bool, confirmed: bool, committed: bool, conflict_batch: ?string}>}>
     */
    public function coachesForBatch(Batch $batch): Collection
    {
        $dates = $this->batchDates($batch);
        $coaches = Coach::where('status', 'active')->with('user')->get();

        $availabilities = CoachAvailability::whereIn('coach_id', $coaches->pluck('user_id'))
            ->whereIn(DB::raw("DATE(date)"), $dates)
            ->get()
            ->keyBy(fn ($a) => $a->coach_id . '|' . $a->date->format('Y-m-d'));

        $commitments = CoachDateCommitment::whereIn('coach_id', $coaches->pluck('id'))
            ->whereIn(DB::raw("DATE(date)"), $dates)
            ->with('batch')
            ->get()
            ->keyBy(fn ($c) => $c->coach_id . '|' . $c->date->format('Y-m-d'));

        return $coaches->map(function (Coach $coach) use ($dates, $availabilities, $commitments, $batch) {
            $perDate = [];
            foreach ($dates as $date) {
                $avail = $availabilities->get($coach->user_id . '|' . $date);
                $commit = $commitments->get($coach->id . '|' . $date);
                $perDate[$date] = [
                    'available' => $avail !== null && $avail->status === 'available',
                    'confirmed' => $avail !== null && $avail->isStaffConfirmed(),
                    'committed' => $commit !== null,
                    'committed_to_this_batch' => $commit !== null && $commit->batch_id === $batch->id,
                    'conflict_batch' => $commit !== null && $commit->batch_id !== $batch->id ? $commit->batch->name : null,
                ];
            }

            return ['coach' => $coach, 'dates' => $perDate];
        });
    }

    private function assertStaffRole(User $user): void
    {
        if (! in_array($user->role, ['admin', 'owner'], true) || $user->status !== 'active') {
            throw CoachSchedulingException::unauthorizedAssigner($user);
        }
    }
}
