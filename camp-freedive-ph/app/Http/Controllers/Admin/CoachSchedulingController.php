<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Coach;
use App\Services\Scheduling\CoachSchedulingException;
use App\Services\Scheduling\CoachSchedulingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CoachSchedulingController extends Controller
{
    public function __construct(protected CoachSchedulingService $scheduling) {}

    public function batchStaffing(Batch $batch): View
    {
        $staffing = $this->scheduling->batchStaffingState($batch);
        $coaches = $this->scheduling->coachesForBatch($batch);

        return view('admin.scheduling.batch-staffing', compact('batch', 'staffing', 'coaches'));
    }

    public function confirmAvailability(Request $request, Coach $coach): RedirectResponse
    {
        $request->validate(['date' => 'required|date']);

        try {
            $this->scheduling->confirmAvailability($coach, $request->date, $request->user());
        } catch (CoachSchedulingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$coach->full_name}'s availability on {$request->date} confirmed.");
    }

    public function revokeConfirmation(Request $request, Coach $coach): RedirectResponse
    {
        $request->validate(['date' => 'required|date']);

        try {
            $this->scheduling->revokeConfirmation($coach, $request->date, $request->user());
        } catch (CoachSchedulingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$coach->full_name}'s confirmed availability on {$request->date} revoked.");
    }

    public function assignCoach(Request $request, Batch $batch): RedirectResponse
    {
        $request->validate(['coach_id' => 'required|exists:coaches,id']);

        $coach = Coach::findOrFail($request->coach_id);
        $dates = $this->scheduling->batchDates($batch);

        try {
            $this->scheduling->commitCoach($coach, $batch, $dates, $request->user());
        } catch (CoachSchedulingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$coach->full_name} assigned to {$batch->name}.");
    }

    public function removeCoach(Request $request, Batch $batch): RedirectResponse
    {
        $request->validate(['coach_id' => 'required|exists:coaches,id']);

        $coach = Coach::findOrFail($request->coach_id);
        $removed = $this->scheduling->releaseCoach($coach, $batch);

        if ($removed === 0) {
            return back()->with('error', "{$coach->full_name} has no commitments on {$batch->name}.");
        }

        return back()->with('success', "{$coach->full_name} removed from {$batch->name}.");
    }
}
