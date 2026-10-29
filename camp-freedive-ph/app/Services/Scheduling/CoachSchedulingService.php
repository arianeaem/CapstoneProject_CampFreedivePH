<?php

namespace App\Services\Scheduling;

use App\Models\Batch;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachDateCommitment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * S1 foundational scheduling domain logic.
 *
 * Calculates required coach counts, checks availability and conflicts, and manages
 * authoritative date commitments. Does NOT activate the booking capacity gate — the
 * InstructorCapacityVerifier binding stays fail-closed until S1–S5 verification and
 * separate rollout approval.
 */
class CoachSchedulingService
{
    public function ratio(): int
    {
        return max(1, (int) (app(\App\Services\SystemSettingService::class)
            ->get('camp_operations.coach_student_ratio', 4) ?? 4));
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
}
