<?php

namespace App\Services;

use App\Models\AssignmentLog;
use App\Models\Batch;
use App\Models\BookingParticipant;
use App\Models\CoachAvailability;
use App\Models\CoachOpening;
use App\Models\CoachRequest;
use App\Models\ParticipantAssignment;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class CoachMatchingService
{
    public function getCoachStudentRatio(): int
    {
        return (int) (app(\App\Services\SystemSettingService::class)->get('camp_operations.coach_student_ratio', 4) ?? 4);
    }

    /**
     * Assign one or multiple coaches to a batch.
     * Maps batch participants across the assigned coaching team.
     *
     * @throws Exception
     */
    public function assignCoachesToBatch(Batch $batch, array $coachIds, User $assignedBy): array
    {
        return DB::transaction(function () use ($batch, $coachIds, $assignedBy) {
            $coaches = User::where('role', 'coach')
                ->whereIn('id', $coachIds)
                ->get();

            if ($coaches->isEmpty()) {
                throw new Exception("No valid active coaches selected.");
            }

            // Get all active participants belonging to this batch
            $participants = BookingParticipant::whereHas('booking', function ($q) use ($batch) {
                $q->where('batch_id', $batch->id)
                  ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'no_show', 'pending_downpayment']);
            })->get();

            $totalParticipants = $participants->count();
            $existingAssignedCoaches = $batch->assigned_coaches->pluck('id')->toArray();
            $allCoachIds = array_values(array_unique(array_merge($existingAssignedCoaches, $coaches->pluck('id')->toArray())));
            $coachCount = count($allCoachIds);

            // Re-assign / Distribute participants evenly across all assigned coaches
            if ($totalParticipants > 0 && $coachCount > 0) {
                // Delete previous active assignments for this batch
                ParticipantAssignment::where('batch_id', $batch->id)
                    ->where('status', 'assigned')
                    ->delete();

                $chunkSize = (int) ceil($totalParticipants / $coachCount);
                $chunks = $participants->chunk($chunkSize);

                $ratio = $this->getCoachStudentRatio();
                foreach ($allCoachIds as $idx => $cId) {
                    $pChunk = $chunks->get($idx) ?? collect();
                    $isRatioOverride = $pChunk->count() > $ratio;

                    foreach ($pChunk as $p) {
                        ParticipantAssignment::create([
                            'participant_id' => $p->id,
                            'booking_id' => $p->booking_id,
                            'coach_id' => $cId,
                            'batch_id' => $batch->id,
                            'dive_date' => $batch->start_date,
                            'assigned_by' => $assignedBy->id,
                            'assigned_at' => now(),
                            'status' => 'assigned',
                            'is_ratio_override' => $isRatioOverride,
                        ]);
                    }
                }
            }

            // Update availability for each assigned coach
            foreach ($coaches as $coach) {
                $dateStr1 = $batch->start_date->format('Y-m-d');
                $dateStr2 = $batch->end_date ? $batch->end_date->format('Y-m-d') : $batch->start_date->copy()->addDay()->format('Y-m-d');

                foreach ([$dateStr1, $dateStr2] as $dStr) {
                    $avail = CoachAvailability::where('coach_id', $coach->id)->whereDate('date', $dStr)->first();
                    if ($avail) {
                        $avail->update(['status' => 'assigned', 'notes' => "Assigned to {$batch->batch_number}"]);
                    } else {
                        CoachAvailability::create([
                            'coach_id' => $coach->id,
                            'date' => $dStr,
                            'status' => 'assigned',
                            'notes' => "Assigned to {$batch->batch_number}",
                        ]);
                    }
                }
            }

            AuditLogger::log(
                'BATCH_COACHES_ASSIGNED',
                "Assigned " . $coaches->pluck('name')->implode(', ') . " to {$batch->batch_number}.",
                $assignedBy,
                $assignedBy->name
            );

            return [
                'success' => true,
                'coaches' => $coaches,
                'batch' => $batch,
                'total_assigned_coaches' => $coachCount,
            ];
        });
    }

    /**
     * Unassign a coach from a batch.
     */
    public function unassignCoachFromBatch(Batch $batch, User $coach, User $unassignedBy): bool
    {
        return DB::transaction(function () use ($batch, $coach, $unassignedBy) {
            // Delete active assignments for this coach in this batch
            ParticipantAssignment::where('batch_id', $batch->id)
                ->where('coach_id', $coach->id)
                ->where('status', 'assigned')
                ->delete();

            // Revert coach availability back to 'available'
            $dateStr1 = $batch->start_date->format('Y-m-d');
            $dateStr2 = $batch->end_date ? $batch->end_date->format('Y-m-d') : $batch->start_date->copy()->addDay()->format('Y-m-d');

            foreach ([$dateStr1, $dateStr2] as $dStr) {
                CoachAvailability::where('coach_id', $coach->id)
                    ->where('date', $dStr)
                    ->where('status', 'assigned')
                    ->update(['status' => 'available', 'notes' => 'Unassigned from batch. Available.']);
            }

            // Redistribute remaining participants among remaining coaches
            $remainingCoachIds = ParticipantAssignment::where('batch_id', $batch->id)
                ->where('coach_id', '!=', $coach->id)
                ->where('status', 'assigned')
                ->distinct()
                ->pluck('coach_id');

            $remainingCoaches = User::whereIn('id', $remainingCoachIds)->get();
            $participants = BookingParticipant::whereHas('booking', function ($q) use ($batch) {
                $q->where('batch_id', $batch->id)
                  ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'no_show', 'pending_downpayment']);
            })->get();

            if ($remainingCoaches->isEmpty()) {
                // No coaches left, clear any remaining assignments for this batch
                ParticipantAssignment::where('batch_id', $batch->id)
                    ->where('status', 'assigned')
                    ->delete();
            } elseif ($participants->isNotEmpty()) {
                ParticipantAssignment::where('batch_id', $batch->id)
                    ->where('status', 'assigned')
                    ->delete();

                $chunkSize = (int) ceil($participants->count() / $remainingCoaches->count());
                $chunks = $participants->chunk($chunkSize);

                $ratio = $this->getCoachStudentRatio();
                foreach ($remainingCoaches->values() as $idx => $remCoach) {
                    $pChunk = $chunks->get($idx) ?? collect();
                    $isRatioOverride = $pChunk->count() > $ratio;

                    foreach ($pChunk as $p) {
                        ParticipantAssignment::create([
                            'participant_id' => $p->id,
                            'booking_id' => $p->booking_id,
                            'coach_id' => $remCoach->id,
                            'batch_id' => $batch->id,
                            'dive_date' => $batch->start_date,
                            'assigned_by' => $unassignedBy->id,
                            'assigned_at' => now(),
                            'status' => 'assigned',
                            'is_ratio_override' => $isRatioOverride,
                        ]);
                    }
                }
            }

            AuditLogger::log(
                'BATCH_COACH_UNASSIGNED',
                "Unassigned Coach {$coach->name} from {$batch->batch_number}.",
                $unassignedBy,
                $unassignedBy->name
            );

            return true;
        });
    }

    /**
     * Assign a list of participants/students to a coach for a batch dive schedule.
     *
     * @throws Exception
     */
    public function assignStudentsToCoach(array $participantIds, User $coach, Batch $batch, User $assignedBy): array
    {
        if ($coach->role !== 'coach') {
            throw new Exception("Selected user {$coach->name} is not registered as a freediving coach.");
        }

        if (!$coach->isActive()) {
            throw new Exception("Cannot assign inactive coach {$coach->name}.");
        }

        return DB::transaction(function () use ($participantIds, $coach, $batch, $assignedBy) {
            $participants = BookingParticipant::with('booking')
                ->whereIn('id', $participantIds)
                ->get();

            $currentLoad = $coach->assignedCountForDate($batch->start_date);
            $newTotal = $currentLoad + $participants->count();
            $isRatioOverride = $newTotal > $this->getCoachStudentRatio();

            $assignedList = [];

            foreach ($participants as $participant) {
                // If participant had a previous active assignment, mark it reassigned
                $oldAssignment = ParticipantAssignment::where('participant_id', $participant->id)
                    ->whereDate('dive_date', $batch->start_date)
                    ->where('status', 'assigned')
                    ->first();

                if ($oldAssignment && $oldAssignment->coach_id !== $coach->id) {
                    $oldAssignment->update(['status' => 'reassigned']);

                    AssignmentLog::create([
                        'participant_id' => $participant->id,
                        'old_coach_id' => $oldAssignment->coach_id,
                        'new_coach_id' => $coach->id,
                        'changed_by' => $assignedBy->id,
                        'reason' => 'Reassigned via Admin Matching Queue.',
                    ]);
                }

                $assignment = ParticipantAssignment::create([
                    'participant_id' => $participant->id,
                    'booking_id' => $participant->booking_id,
                    'coach_id' => $coach->id,
                    'batch_id' => $batch->id,
                    'dive_date' => $batch->start_date,
                    'assigned_by' => $assignedBy->id,
                    'assigned_at' => now(),
                    'status' => 'assigned',
                    'is_ratio_override' => $isRatioOverride,
                ]);

                $assignedList[] = $assignment;
            }

            // Update coach availability calendar to 'assigned'
            $dateStr = $batch->start_date->format('Y-m-d');
            $avail = CoachAvailability::where('coach_id', $coach->id)->whereDate('date', $dateStr)->first();
            if ($avail) {
                $avail->update(['status' => 'assigned', 'notes' => "Assigned to {$batch->batch_code} ({$newTotal} students)"]);
            } else {
                CoachAvailability::create([
                    'coach_id' => $coach->id,
                    'date' => $dateStr,
                    'status' => 'assigned',
                    'notes' => "Assigned to {$batch->batch_code} ({$newTotal} students)",
                ]);
            }

            return [
                'success' => true,
                'assigned_count' => count($assignedList),
                'coach' => $coach,
                'batch' => $batch,
                'is_ratio_override' => $isRatioOverride,
                'total_load' => $newTotal,
            ];
        });
    }

    /**
     * Propose 2-3 viable coach-count options based on total unassigned student count
     * and the batch-level 4:1 guideline.
     */
    public function proposeCoachCountOptions(int $studentCount, int $availableCoachCount): array
    {
        if ($studentCount <= 0) {
            return [];
        }

        $options = [];
        $maxCoaches = max(1, min($availableCoachCount, $studentCount));

        $ratio = $this->getCoachStudentRatio();
        // Option 1: Standard ~ratio:1 guideline (Ceiling of studentCount / ratio)
        $opt1Count = (int) ceil($studentCount / $ratio);
        $opt1Count = max(1, min($opt1Count, $maxCoaches));

        $opt1Split = $this->calculateTargetSplit($studentCount, $opt1Count);
        $options[] = [
            'id' => 'standard',
            'label' => "Option A: {$opt1Count} Coach" . ($opt1Count > 1 ? 'es' : ''),
            'coach_count' => $opt1Count,
            'description' => "Standard ~{$ratio}:1 ratio (" . implode(' + ', $opt1Split) . " students)",
            'split' => $opt1Split,
            'is_recommended' => true,
        ];

        // Option 2: More distributed / lighter split (+1 coach if available and viable)
        $opt2Count = $opt1Count + 1;
        if ($opt2Count <= $maxCoaches && ($studentCount / $opt2Count) >= 1.5) {
            $opt2Split = $this->calculateTargetSplit($studentCount, $opt2Count);
            $options[] = [
                'id' => 'distributed',
                'label' => "Option B: {$opt2Count} Coaches",
                'coach_count' => $opt2Count,
                'description' => "Even lighter load (" . implode(' + ', $opt2Split) . " students)",
                'split' => $opt2Split,
                'is_recommended' => false,
            ];
        }

        // Option 3: Leaner split (-1 coach if viable and <= ratio per coach)
        $opt3Count = $opt1Count - 1;
        if ($opt3Count >= 1 && ($studentCount / $opt3Count) <= (float) $ratio) {
            $opt3Split = $this->calculateTargetSplit($studentCount, $opt3Count);
            $options[] = [
                'id' => 'lean',
                'label' => "Option C: {$opt3Count} Coach" . ($opt3Count > 1 ? 'es' : ''),
                'coach_count' => $opt3Count,
                'description' => "Compact roster (" . implode(' + ', $opt3Split) . " students)",
                'split' => $opt3Split,
                'is_recommended' => false,
            ];
        }

        return $options;
    }

    /**
     * Helper to compute integer split of students across N coaches.
     */
    protected function calculateTargetSplit(int $totalStudents, int $coachCount): array
    {
        if ($coachCount <= 0) return [$totalStudents];
        $base = intdiv($totalStudents, $coachCount);
        $rem = $totalStudents % $coachCount;

        $split = [];
        for ($i = 0; $i < $coachCount; $i++) {
            $split[] = $base + ($i < $rem ? 1 : 0);
        }
        return $split;
    }

    /**
     * Generate an initial balanced draft distributing participants across selected coaches,
     * balancing both headcount and class types (Discovery, Fundive, Refinement).
     */
    public function generateBalancedDraft($participants, array $coachUsers): array
    {
        $coachesCount = count($coachUsers);
        if ($coachesCount === 0 || count($participants) === 0) {
            return [
                'draft' => [],
                'is_balanced' => true,
                'headcount_spread' => 0,
            ];
        }

        // Initialize buckets for each coach
        $draft = [];
        foreach ($coachUsers as $coach) {
            $draft[$coach->id] = [
                'coach' => $coach,
                'students' => [],
                'class_counts' => [],
                'total_count' => 0,
            ];
        }

        // Group participants by class type
        $grouped = collect($participants)->groupBy(fn($p) => $p->booking?->class_type ?? 'discovery');

        // Distribute each class type round-robin to coach with smallest load
        foreach ($grouped as $classType => $students) {
            foreach ($students as $student) {
                // Find coach with lowest total load (and lowest of this class type as tie breaker)
                $targetCoachId = null;
                $minTotal = PHP_INT_MAX;
                $minClass = PHP_INT_MAX;

                foreach ($draft as $cid => $data) {
                    $cClassCount = $data['class_counts'][$classType] ?? 0;
                    if ($data['total_count'] < $minTotal || ($data['total_count'] === $minTotal && $cClassCount < $minClass)) {
                        $minTotal = $data['total_count'];
                        $minClass = $cClassCount;
                        $targetCoachId = $cid;
                    }
                }

                $draft[$targetCoachId]['students'][] = $student;
                $draft[$targetCoachId]['class_counts'][$classType] = ($draft[$targetCoachId]['class_counts'][$classType] ?? 0) + 1;
                $draft[$targetCoachId]['total_count']++;
            }
        }

        // Compute balance metrics
        $headcounts = array_column($draft, 'total_count');
        $minH = !empty($headcounts) ? min($headcounts) : 0;
        $maxH = !empty($headcounts) ? max($headcounts) : 0;
        $headcountSpread = $maxH - $minH;
        $isBalanced = $headcountSpread <= 1;

        return [
            'draft' => $draft,
            'is_balanced' => $isBalanced,
            'headcount_spread' => $headcountSpread,
        ];
    }

    /**
     * Save the finalized batch assignments across multiple coaches atomically.
     * Logs an intentional exception if an imbalanced split was manually chosen.
     *
     * @throws Exception
     */
    public function saveBatchBalancedAssignments(Batch $batch, array $coachAssignmentsMap, User $assignedBy, ?string $exceptionNote = null): array
    {
        return DB::transaction(function () use ($batch, $coachAssignmentsMap, $assignedBy, $exceptionNote) {
            $totalAssigned = 0;
            $headcounts = [];
            $assignedCoaches = [];

            foreach ($coachAssignmentsMap as $coachId => $participantIds) {
                if (empty($participantIds)) {
                    continue;
                }

                $coach = User::where('role', 'coach')->findOrFail($coachId);
                if (!$coach->isActive()) {
                    throw new Exception("Cannot assign inactive coach {$coach->name}.");
                }

                $currentLoad = $coach->assignedCountForDate($batch->start_date);
                $newStudentsCount = count($participantIds);
                $totalLoad = $currentLoad + $newStudentsCount;
                $isRatioOverride = $totalLoad > $this->getCoachStudentRatio();

                $headcounts[] = $totalLoad;
                $assignedCoaches[] = $coach;

                foreach ($participantIds as $pId) {
                    $participant = BookingParticipant::findOrFail($pId);

                    // Reassignment check
                    $oldAssignment = ParticipantAssignment::where('participant_id', $participant->id)
                        ->whereDate('dive_date', $batch->start_date)
                        ->where('status', 'assigned')
                        ->first();

                    if ($oldAssignment && $oldAssignment->coach_id !== $coach->id) {
                        $oldAssignment->update(['status' => 'reassigned']);

                        AssignmentLog::create([
                            'participant_id' => $participant->id,
                            'old_coach_id' => $oldAssignment->coach_id,
                            'new_coach_id' => $coach->id,
                            'changed_by' => $assignedBy->id,
                            'reason' => 'Batch rebalancing in Matching Queue.',
                        ]);
                    }

                    ParticipantAssignment::create([
                        'participant_id' => $participant->id,
                        'booking_id' => $participant->booking_id,
                        'coach_id' => $coach->id,
                        'batch_id' => $batch->id,
                        'dive_date' => $batch->start_date,
                        'assigned_by' => $assignedBy->id,
                        'assigned_at' => now(),
                        'status' => 'assigned',
                        'is_ratio_override' => $isRatioOverride,
                    ]);

                    $totalAssigned++;
                }

                // Update coach availability
                $dateStr = $batch->start_date->format('Y-m-d');
                $avail = CoachAvailability::where('coach_id', $coach->id)->whereDate('date', $dateStr)->first();
                if ($avail) {
                    $avail->update(['status' => 'assigned', 'notes' => "Assigned to {$batch->batch_code} ({$totalLoad} students)"]);
                } else {
                    CoachAvailability::create([
                        'coach_id' => $coach->id,
                        'date' => $dateStr,
                        'status' => 'assigned',
                        'notes' => "Assigned to {$batch->batch_code} ({$totalLoad} students)",
                    ]);
                }
            }

            // Check for intentional exception / imbalance
            $minH = !empty($headcounts) ? min($headcounts) : 0;
            $maxH = !empty($headcounts) ? max($headcounts) : 0;
            $isImbalanced = ($maxH - $minH) > 1;

            if ($isImbalanced || !empty($exceptionNote)) {
                $reason = $exceptionNote ?: "Manual imbalanced split configured by {$assignedBy->name} (Spread: {$minH} to {$maxH} students).";
                AuditLogger::log(
                    'BATCH_COACH_ASSIGNMENT_EXCEPTION',
                    "Intentional coach split exception on batch {$batch->batch_code}: {$reason}",
                    $assignedBy,
                    $assignedBy->name
                );
            }

            AuditLogger::log(
                'BATCH_COACHES_ASSIGNED',
                "Assigned {$totalAssigned} student(s) across " . count($assignedCoaches) . " coach(es) for batch {$batch->batch_code}.",
                $assignedBy,
                $assignedBy->name
            );

            return [
                'success' => true,
                'total_assigned' => $totalAssigned,
                'coaches_count' => count($assignedCoaches),
                'is_imbalanced' => $isImbalanced,
            ];
        });
    }

    /**
     * Reassign a single student away from their current coach to a new coach.
     *
     * @throws Exception
     */
    public function reassignStudent(BookingParticipant $participant, User $newCoach, User $changedBy, string $reason): ParticipantAssignment
    {
        if ($newCoach->role !== 'coach' || !$newCoach->isActive()) {
            throw new Exception("Target coach must be an active coach.");
        }

        return DB::transaction(function () use ($participant, $newCoach, $changedBy, $reason) {
            $activeAssignment = ParticipantAssignment::where('participant_id', $participant->id)
                ->where('status', 'assigned')
                ->first();

            $oldCoachId = $activeAssignment ? $activeAssignment->coach_id : null;
            $diveDate = $activeAssignment ? $activeAssignment->dive_date : $participant->booking->start_date;
            $batchId = $activeAssignment ? $activeAssignment->batch_id : $participant->booking->batch_id;

            if ($activeAssignment) {
                $activeAssignment->update(['status' => 'reassigned']);
            }

            AssignmentLog::create([
                'participant_id' => $participant->id,
                'old_coach_id' => $oldCoachId,
                'new_coach_id' => $newCoach->id,
                'changed_by' => $changedBy->id,
                'reason' => $reason,
            ]);

            $currentLoad = $newCoach->assignedCountForDate($diveDate);
            $isOverride = ($currentLoad + 1) > $this->getCoachStudentRatio();

            $newAssignment = ParticipantAssignment::create([
                'participant_id' => $participant->id,
                'booking_id' => $participant->booking_id,
                'coach_id' => $newCoach->id,
                'batch_id' => $batchId,
                'dive_date' => $diveDate,
                'assigned_by' => $changedBy->id,
                'assigned_at' => now(),
                'status' => 'assigned',
                'is_ratio_override' => $isOverride,
            ]);

            $dateStr = $diveDate->format('Y-m-d');
            $avail = CoachAvailability::where('coach_id', $newCoach->id)->whereDate('date', $dateStr)->first();
            if ($avail) {
                $avail->update(['status' => 'assigned', 'notes' => 'Updated via student reassignment']);
            } else {
                CoachAvailability::create([
                    'coach_id' => $newCoach->id,
                    'date' => $dateStr,
                    'status' => 'assigned',
                    'notes' => 'Updated via student reassignment',
                ]);
            }

            AuditLogger::log(
                'STUDENT_REASSIGNED',
                "Reassigned student {$participant->name} to Coach {$newCoach->name}. Reason: {$reason}",
                $changedBy,
                $changedBy->name
            );

            return $newAssignment;
        });
    }

    /**
     * Broadcast an open slot to the Coach Portal when no coach is available.
     */
    public function postOpeningToPortal(Batch $batch, Carbon $diveDate, User $postedBy, ?string $notes = null): CoachOpening
    {
        $opening = CoachOpening::create([
            'batch_id' => $batch->id,
            'dive_date' => $diveDate,
            'needed_students_count' => (int) $batch->total_participants_count ?: $this->getCoachStudentRatio(),
            'status' => 'open',
            'posted_by' => $postedBy->id,
            'notes' => $notes ?: "Open slot for {$batch->batch_code} ({$diveDate->format('M d, Y')})",
        ]);

        AuditLogger::log(
            'COACH_OPENING_POSTED',
            "Broadcasted open coach slot for batch {$batch->batch_code} on {$diveDate->format('M d, Y')}.",
            $postedBy,
            $postedBy->name
        );

        return $opening;
    }

    /**
     * Review & approve a coach request for an open slot.
     * Assigns the coach to the batch without rigid restrictions on coach count.
     */
    public function approveCoachRequest(CoachRequest $request, User $reviewer): void
    {
        DB::transaction(function () use ($request, $reviewer) {
            // 1. Approve selected request
            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
            ]);

            // Mark the opening as filled so it no longer shows as open
            if ($request->opening_id) {
                $request->opening?->update(['status' => 'filled']);
            } elseif ($request->batch_id) {
                CoachOpening::where('batch_id', $request->batch_id)
                    ->where('status', 'open')
                    ->update(['status' => 'filled']);
            }

            // Mark other pending requests for the same opening as not_selected
            if ($request->opening_id) {
                CoachRequest::where('opening_id', $request->opening_id)
                    ->where('id', '!=', $request->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'not_selected',
                        'reviewed_by' => $reviewer->id,
                        'reviewed_at' => now(),
                    ]);
            }

            $batch = $request->batch;

            // 2. Assign the coach to the batch
            $currentAssignedIds = $batch->assigned_coaches->pluck('id')->toArray();
            $newCoachIds = array_values(array_unique(array_merge($currentAssignedIds, [$request->coach_id])));
            $this->assignCoachesToBatch($batch, $newCoachIds, $reviewer);

            // 3. Mark coach availability to assigned
            $dateStr1 = $batch->start_date->format('Y-m-d');
            $dateStr2 = $batch->end_date ? $batch->end_date->format('Y-m-d') : $batch->start_date->copy()->addDay()->format('Y-m-d');

            foreach ([$dateStr1, $dateStr2] as $dStr) {
                $avail = CoachAvailability::where('coach_id', $request->coach_id)->whereDate('date', $dStr)->first();
                if ($avail) {
                    $avail->update(['status' => 'assigned', 'notes' => "Approved request for batch {$batch->batch_code}"]);
                } else {
                    CoachAvailability::create([
                        'coach_id' => $request->coach_id,
                        'date' => $dStr,
                        'status' => 'assigned',
                        'notes' => "Approved request for batch {$batch->batch_code}",
                    ]);
                }
            }

            AuditLogger::log(
                'COACH_REQUEST_APPROVED',
                "Approved Coach {$request->coach->name} for batch {$batch->batch_code}.",
                $reviewer,
                $reviewer->name
            );
        });
    }
}
