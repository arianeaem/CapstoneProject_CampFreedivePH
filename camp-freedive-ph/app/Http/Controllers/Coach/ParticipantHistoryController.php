<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\Participant;
use App\Models\ParticipantAssignment;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A participant's history for coaches: only people in the coach's own batches,
 * with their full history (including classes with other coaches). Read-only.
 */
class ParticipantHistoryController extends Controller
{
    public function show(Request $request, Participant $participant): View
    {
        $coach = $request->user();
        $participant = $participant->merged_into_id ? $participant->mergedInto : $participant;
        abort_if(!$participant || $participant->anonymized_at, 404);

        $isTheirParticipant = ParticipantAssignment::where('coach_id', $coach->id)
            ->whereIn('participant_id', $participant->bookingParticipants()->pluck('id'))
            ->exists();
        abort_unless($isTheirParticipant, 403, 'You can only see participants in your own batches.');

        $history = $participant->bookingParticipants()
            ->with(['booking.batch', 'assignments.coach'])
            ->get()
            ->filter(fn ($row) => $row->booking && !in_array($row->booking->status, Participant::CANCELLED_STATUSES, true))
            ->sortByDesc(fn ($row) => $row->booking->start_date?->timestamp ?? 0)
            ->values();

        AuditLogger::log('PARTICIPANT_PROFILE_VIEWED', "Coach viewed participant #{$participant->id} ({$participant->full_name}).", $coach, $coach->name, $request);

        return view('coach.participants.show', compact('participant', 'history'));
    }
}
