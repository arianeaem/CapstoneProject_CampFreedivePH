<?php

namespace App\Services;

use App\Mail\AccountRemovedMail;
use App\Models\Batch;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachRequest;
use App\Models\ParticipantAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Account removal without data loss: accounts are archived (status = 'archived'), never deleted.
 */
class UserAccountService
{
    /**
     * Upcoming batches a coach is still responsible for. Each item:
     * ['batch' => Batch, 'students' => int (assigned students), 'team_only' => bool (on the coach team, no students yet)]
     */
    public function upcomingCoachCommitments(User $coach): Collection
    {
        if (!$coach->isCoach()) {
            return collect();
        }

        $today = Carbon::today('Asia/Manila');

        // 1. Students assigned to this coach in upcoming batches
        $byBatch = ParticipantAssignment::with('batch')
            ->where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->whereDate('dive_date', '>=', $today)
            ->get()
            ->filter(fn ($a) => $a->batch && !in_array($a->batch->status, ['completed', 'cancelled_by_camp'], true))
            ->groupBy('batch_id')
            ->map(fn ($rows) => [
                'batch' => $rows->first()->batch,
                'students' => $rows->pluck('participant_id')->unique()->count(),
                'team_only' => false,
            ]);

        // 2. Upcoming batches where the coach is on the coach team (assigned availability) without students yet
        $teamBatches = Batch::whereDate('start_date', '>=', $today)
            ->whereNotIn('status', ['completed', 'cancelled_by_camp'])
            ->whereNotIn('id', $byBatch->keys())
            ->with('activeParticipantAssignments.coach')
            ->get();
        Batch::preloadAssignedCoaches($teamBatches);

        foreach ($teamBatches as $batch) {
            if ($batch->assigned_coaches->pluck('id')->contains($coach->id)) {
                $byBatch[$batch->id] = ['batch' => $batch, 'students' => 0, 'team_only' => true];
            }
        }

        return $byBatch->sortBy(fn ($c) => $c['batch']->start_date)->values();
    }

    /**
     * Archive ("remove") an account: keeps every record, blocks login, releases future open
     * availability / pending requests, and emails the person.
     */
    public function archive(User $user, User $removedBy): void
    {
        DB::transaction(function () use ($user) {
            $user->update(['status' => 'archived']);

            if ($user->isCoach()) {
                $today = Carbon::today('Asia/Manila');

                // Future open days are no longer offered; past records stay untouched
                CoachAvailability::where('coach_id', $user->id)
                    ->where('status', 'available')
                    ->whereDate('date', '>=', $today)
                    ->update(['status' => 'unavailable']);

                // Pending volunteer requests can no longer be approved
                CoachRequest::where('coach_id', $user->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'not_selected', 'notes' => 'Coach account removed']);

                Coach::where('user_id', $user->id)->update(['status' => 'inactive']);
            }
        });

        try {
            Mail::to($user->email)->send(new AccountRemovedMail($user, $removedBy->name));
        } catch (\Throwable $e) {
            Log::warning("Failed to send account removal email to {$user->email}: " . $e->getMessage());
        }
    }

    /**
     * Human-readable list of the commitments, for error messages.
     */
    public function describeCommitments(Collection $commitments): string
    {
        return $commitments->map(function ($c) {
            $batch = $c['batch'];
            $when = $batch->start_date?->format('M d, Y');
            $what = $c['team_only'] ? 'on the coach team' : "{$c['students']} " . ($c['students'] === 1 ? 'student' : 'students');
            return "{$batch->batch_number} ({$when}): {$what}";
        })->implode('; ');
    }
}
