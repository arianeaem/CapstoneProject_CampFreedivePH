<?php

namespace App\Http\Controllers\Coach;

use Illuminate\Support\Facades\Gate;
use App\Http\Controllers\Controller;
use App\Models\CoachOpening;
use App\Models\CoachRequest;
use App\Models\ParticipantAssignment;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RequestController extends Controller
{
    /**
     * Page 4: open slots and my requests.
     */
    public function index(Request $request): View
    {
        $coach = Auth::user();
        $today = Carbon::today();
        $activeTab = $request->input('tab', 'open_slots');

        // Hide batches this coach is already assigned to or approved for
        $assignedBatchIds = ParticipantAssignment::where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->pluck('batch_id')
            ->filter()
            ->unique()
            ->toArray();

        $approvedBatchIds = CoachRequest::where('coach_id', $coach->id)
            ->where('status', 'approved')
            ->pluck('batch_id')
            ->filter()
            ->unique()
            ->toArray();

        $excludeBatchIds = array_unique(array_merge($assignedBatchIds, $approvedBatchIds));

        // 1. Open slots (camp needs more coaches)
        $openings = CoachOpening::with(['batch.riskAssessments', 'postedByUser', 'requests'])
            ->where('status', 'open')
            ->whereDate('dive_date', '>=', $today)
            ->whereNotIn('batch_id', $excludeBatchIds)
            ->orderBy('dive_date', 'asc')
            ->get();

        // 2. My requests and their status
        $myRequests = CoachRequest::with(['opening.batch', 'batch.riskAssessments', 'reviewer'])
            ->where('coach_id', $coach->id)
            ->orderBy('created_at', 'desc')
            ->get();

        $myRequestedOpeningIds = $myRequests->whereIn('status', ['pending', 'approved'])->pluck('opening_id')->filter()->toArray();

        return view('coach.requests.index', compact(
            'coach',
            'openings',
            'myRequests',
            'myRequestedOpeningIds',
            'activeTab'
        ));
    }

    /**
     * Ask to take an open slot.
     */
    public function store(Request $request, CoachOpening $opening): RedirectResponse
    {
        $coach = Auth::user();

        // Is the slot still open?
        if ($opening->status !== 'open') {
            return back()->with('error', 'This slot is no longer open for requests.');
        }

        // Is the dive date already past?
        if ($opening->dive_date->isPast() && !$opening->dive_date->isToday()) {
            return back()->with('error', 'Cannot request past dive dates.');
        }

        // Is the coach already in this batch?
        $isAlreadyAssigned = ParticipantAssignment::where('coach_id', $coach->id)
            ->where('batch_id', $opening->batch_id)
            ->where('status', 'assigned')
            ->exists();

        if ($isAlreadyAssigned) {
            return back()->with('error', 'You are already assigned to this dive batch.');
        }

        // Does the coach already have a pending or approved request for it?
        $existing = CoachRequest::where('coach_id', $coach->id)
            ->where(function ($q) use ($opening) {
                $q->where('opening_id', $opening->id)
                  ->orWhere('batch_id', $opening->batch_id);
            })
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing) {
            return back()->with('error', 'You have already submitted a request for this dive batch.');
        }

        CoachRequest::create([
            'opening_id' => $opening->id,
            'batch_id' => $opening->batch_id,
            'coach_id' => $coach->id,
            'status' => 'pending',
            'notes' => $request->input('notes', 'Volunteer request submitted via Coach Portal board.'),
        ]);

        return back()->with('success', "Your request to take the open slot for {$opening->dive_date->format('M d, Y')} has been submitted. Camp Admin will review and make the assignment.");
    }

    /**
     * Cancel a pending request.
     */
    public function withdraw(CoachRequest $coachRequest): RedirectResponse
    {
        Gate::authorize('withdraw', $coachRequest);

        // Only pending requests can be withdrawn
        if ($coachRequest->status !== 'pending') {
            return back()->with('error', 'Only pending requests can be withdrawn.');
        }

        $coachRequest->delete();

        return back()->with('success', 'Your request has been withdrawn.');
    }
}
