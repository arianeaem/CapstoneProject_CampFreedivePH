<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BookingParticipant;
use App\Models\CoachOpening;
use App\Models\CoachRequest;
use App\Models\User;
use App\Services\CoachMatchingService;
use App\Services\DemandForecastService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CoachMatchingController extends Controller
{
    public function __construct(
        protected CoachMatchingService $matchingService,
        protected DemandForecastService $forecastService
    ) {}

    /**
     * Coach Assignment & Matching Queue.
     */
    public function matching(): View
    {
        $today = Carbon::today();

        // 1. Fetch upcoming active batches with registered participants
        $batches = Batch::with(['bookings.participants', 'activeParticipantAssignments.coach'])
            ->whereNotIn('status', ['completed', 'cancelled_by_camp'])
            ->orderBy('start_date', 'asc')
            ->get()
            ->filter(function ($batch) use ($today) {
                $isDone = ($batch->end_date && $batch->end_date->lt($today))
                    || ($batch->start_date && !$batch->end_date && $batch->start_date->lt($today))
                    || in_array($batch->status, ['completed', 'cancelled_by_camp']);

                // Only include active batches that have at least 1 participant
                return !$isDone && ($batch->total_participants_count > 0);
            })
            ->values();

        // 2. Fetch all active coaches with availability
        $activeCoaches = User::where('role', 'coach')
            ->where('status', 'active')
            ->with(['coachAvailabilities'])
            ->orderBy('name', 'asc')
            ->get();

        $settingService = app(\App\Services\SystemSettingService::class);
        $coachRatio = (int) ($settingService->get('camp_operations.coach_student_ratio', 4) ?? 4);

        // 3. Build simplified batch staffing data
        $batchData = $batches->map(function ($batch) use ($activeCoaches, $coachRatio) {
            $totalParticipants = (int) $batch->total_participants_count;
            $neededCoaches = $totalParticipants > 0 ? (int) ceil($totalParticipants / $coachRatio) : 0;
            $assignedCoaches = $batch->assigned_coaches;
            $assignedCoachIds = $assignedCoaches->pluck('id')->toArray();

            $startDateStr = $batch->start_date->format('Y-m-d');
            $endDateStr = $batch->end_date ? $batch->end_date->format('Y-m-d') : $startDateStr;

            // Fetch Demand Forecast staffing suggestion for batch's start date
            $mlRecommendation = $this->forecastService->getStaffingRecommendationForDate($batch->start_date);

            // Only include coaches who are available on this batch's dates (and NOT already assigned to this batch)
            $availableCoaches = $activeCoaches->filter(function ($coach) use ($startDateStr, $endDateStr, $assignedCoachIds) {
                if (in_array($coach->id, $assignedCoachIds)) {
                    return false;
                }

                return $coach->coachAvailabilities->contains(function ($avail) use ($startDateStr, $endDateStr) {
                    $d = $avail->date instanceof \DateTimeInterface 
                        ? $avail->date->format('Y-m-d') 
                        : substr((string) $avail->date, 0, 10);

                    return ($d === $startDateStr || $d === $endDateStr) && $avail->status === 'available';
                });
            })->map(function ($coach) {
                return [
                    'id' => $coach->id,
                    'name' => $coach->name,
                    'email' => $coach->email,
                    'phone' => $coach->phone,
                    'is_assigned_here' => false,
                    'is_available_on_calendar' => true,
                    'coach_model' => $coach,
                ];
            })->values();

            // Check if open broadcast exists
            $openBroadcast = CoachOpening::where('batch_id', $batch->id)
                ->where('status', 'open')
                ->first();

            return [
                'batch' => $batch,
                'total_participants' => $totalParticipants,
                'needed_coaches' => $neededCoaches,
                'assigned_coaches' => $assignedCoaches,
                'assigned_count' => $assignedCoaches->count(),
                'available_coaches' => $availableCoaches,
                'open_broadcast' => $openBroadcast,
                'ml_recommendation' => $mlRecommendation,
            ];
        });

        // Pending Coach Requests count
        $pendingRequestsCount = CoachRequest::where('status', 'pending')->count();

        return view('admin.coaches.matching', [
            'batchData' => $batchData,
            'batchGroups' => $batchData, // Backward compatibility
            'pendingRequestsCount' => $pendingRequestsCount,
        ]);
    }

    /**
     * Batch Assignment: Assign one or multiple coaches to a batch.
     */
    public function batchAssign(Request $request): RedirectResponse
    {
        if ($request->has('assignments')) {
            $validated = $request->validate([
                'batch_id' => 'required|exists:batches,id',
                'assignments' => 'required|array|min:1',
                'exception_note' => 'nullable|string|max:500',
            ]);

            try {
                $batch = Batch::findOrFail($validated['batch_id']);

                if ($batch->total_participants_count === 0) {
                    return back()->with('error', "Cannot assign coaches to {$batch->batch_number} because there are no participants registered yet.");
                }

                $firstVal = reset($validated['assignments']);
                if (is_array($firstVal)) {
                    $this->matchingService->saveBatchBalancedAssignments(
                        $batch,
                        $validated['assignments'],
                        auth()->user(),
                        $validated['exception_note'] ?? null
                    );
                } else {
                    $coachIds = array_keys($validated['assignments']);
                    $this->matchingService->assignCoachesToBatch(
                        $batch,
                        $coachIds,
                        auth()->user()
                    );
                }

                return back()->with('success', "Coaches successfully assigned to {$batch->batch_number}.");
            } catch (Exception $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        return $this->assign($request);
    }

    /**
     * Assign selected coach(es) to a batch.
     */
    public function assign(Request $request): RedirectResponse
    {
        // Support legacy single-student assignment if participant_ids provided
        if ($request->has('participant_ids')) {
            $validated = $request->validate([
                'participant_ids' => 'required|array|min:1',
                'participant_ids.*' => 'exists:booking_participants,id',
                'coach_id' => 'required|exists:users,id',
                'batch_id' => 'required|exists:batches,id',
            ]);

            try {
                $coach = User::findOrFail($validated['coach_id']);
                $batch = Batch::findOrFail($validated['batch_id']);

                if ($batch->total_participants_count === 0) {
                    return back()->with('error', "Cannot assign coaches to {$batch->batch_number} because there are no participants registered yet.");
                }

                $result = $this->matchingService->assignStudentsToCoach(
                    $validated['participant_ids'],
                    $coach,
                    $batch,
                    auth()->user()
                );

                return back()->with('success', "Assigned Coach {$coach->name} to {$batch->batch_number}.");
            } catch (Exception $e) {
                return back()->with('error', $e->getMessage());
            }
        }

        // New clean Batch Coach Assignment
        $validated = $request->validate([
            'batch_id' => 'required|exists:batches,id',
            'coach_ids' => 'nullable|array',
            'coach_ids.*' => 'exists:users,id',
            'coach_id' => 'nullable|exists:users,id',
        ]);

        $batch = Batch::findOrFail($validated['batch_id']);
        if ($batch->total_participants_count === 0) {
            return back()->with('error', "Cannot assign coaches to {$batch->batch_number} because there are no participants registered yet.");
        }

        $coachIds = $validated['coach_ids'] ?? [];
        if (!empty($validated['coach_id'])) {
            $coachIds[] = $validated['coach_id'];
        }
        $coachIds = array_values(array_unique(array_filter($coachIds)));

        if (empty($coachIds)) {
            return back()->with('error', 'Please select at least one coach to assign.');
        }

        try {
            $batch = Batch::findOrFail($validated['batch_id']);
            $result = $this->matchingService->assignCoachesToBatch(
                $batch,
                $coachIds,
                auth()->user()
            );

            $names = $result['coaches']->pluck('name')->implode(', ');
            return back()->with('success', "Assigned {$names} to {$batch->batch_number}.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Unassign a coach from a batch.
     */
    public function unassign(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'batch_id' => 'required|exists:batches,id',
            'coach_id' => 'required|exists:users,id',
        ]);

        try {
            $batch = Batch::findOrFail($validated['batch_id']);
            $coach = User::findOrFail($validated['coach_id']);

            $this->matchingService->unassignCoachFromBatch($batch, $coach, auth()->user());

            return back()->with('success', "Unassigned Coach {$coach->name} from {$batch->batch_number}.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Broadcast an open slot to the Coach Portal when no coach is available.
     */
    public function broadcastOpening(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'batch_id' => 'required|exists:batches,id',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $batch = Batch::findOrFail($validated['batch_id']);

            $this->matchingService->postOpeningToPortal(
                $batch,
                $batch->start_date,
                auth()->user(),
                $validated['notes'] ?? null
            );

            return back()->with('success', "Open slot for {$batch->batch_code} ({$batch->start_date->format('M d, Y')}) has been posted to the Coach Portal.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Page 4: Review Coach Requests queue.
     */
    public function requests(): View
    {
        $requests = CoachRequest::with(['opening.batch', 'coach', 'batch'])
            ->orderBy('status', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        $pendingRequests = $requests->where('status', 'pending')->groupBy('batch_id');
        $reviewedRequests = $requests->where('status', '!=', 'pending');

        return view('admin.coaches.requests', compact(
            'pendingRequests',
            'reviewedRequests'
        ));
    }

    /**
     * Approve a coach request for an open slot.
     */
    public function approveRequest($coachRequest): RedirectResponse
    {
        try {
            $requestModel = $coachRequest instanceof CoachRequest ? $coachRequest : CoachRequest::findOrFail($coachRequest);
            $this->matchingService->approveCoachRequest($requestModel, auth()->user());
            $batch = $requestModel->batch;

            return back()->with('success', "Approved Coach " . ($requestModel->coach?->name ?? 'Coach') . " for " . ($batch?->batch_code ?? 'Batch') . ".");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Bulk approve selected coach requests.
     */
    public function bulkApproveRequests(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'request_ids' => 'required|array|min:1',
            'request_ids.*' => 'exists:coach_requests,id',
        ]);

        $approvedCount = 0;
        $coachNames = [];
        $batchCode = '';

        try {
            foreach ($validated['request_ids'] as $reqId) {
                $coachRequest = CoachRequest::find($reqId);
                if ($coachRequest && $coachRequest->status === 'pending') {
                    $this->matchingService->approveCoachRequest($coachRequest, auth()->user());
                    $approvedCount++;
                    $coachNames[] = $coachRequest->coach?->name ?? 'Coach';
                    $batchCode = $coachRequest->batch?->batch_code ?? '';
                }
            }

            $namesStr = implode(', ', $coachNames);
            return back()->with('success', "Approved {$approvedCount} coach applicant(s) ({$namesStr}) for {$batchCode}.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Approve an emergency assignment release request.
     */
    public function approveReleaseRequest(Request $request, $releaseRequest): RedirectResponse
    {
        $reqModel = $releaseRequest instanceof \App\Models\AssignmentReleaseRequest 
            ? $releaseRequest 
            : \App\Models\AssignmentReleaseRequest::findOrFail($releaseRequest);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($reqModel, $request) {
                $reqModel->update([
                    'status' => 'approved',
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                    'review_notes' => $request->input('notes', 'Approved by Camp Administration.'),
                ]);

                // Unassign the coach's students for that batch
                \App\Models\ParticipantAssignment::where('coach_id', $reqModel->coach_id)
                    ->where('batch_id', $reqModel->batch_id)
                    ->delete();

                // Set coach availability for that date to unavailable
                \App\Models\CoachAvailability::where('coach_id', $reqModel->coach_id)
                    ->where('date', $reqModel->dive_date)
                    ->update(['status' => 'unavailable']);

                // Pair date
                $pairDate = $reqModel->dive_date->isSaturday() 
                    ? $reqModel->dive_date->copy()->addDay() 
                    : $reqModel->dive_date->copy()->subDay();

                \App\Models\CoachAvailability::where('coach_id', $reqModel->coach_id)
                    ->where('date', $pairDate)
                    ->update(['status' => 'unavailable']);
            });

            return back()->with('success', "Approved release request for Coach " . ($reqModel->coach?->name ?? 'Coach') . ". Students have been moved back to the matching queue.");
        } catch (Exception $e) {
            return back()->with('error', 'Failed to approve release request: ' . $e->getMessage());
        }
    }

    /**
     * Reject an emergency assignment release request.
     */
    public function rejectReleaseRequest(Request $request, $releaseRequest): RedirectResponse
    {
        $reqModel = $releaseRequest instanceof \App\Models\AssignmentReleaseRequest 
            ? $releaseRequest 
            : \App\Models\AssignmentReleaseRequest::findOrFail($releaseRequest);

        $reqModel->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $request->input('notes', 'Request could not be accommodated due to staffing constraints.'),
        ]);

        return back()->with('success', "Release request for Coach " . ($reqModel->coach?->name ?? 'Coach') . " has been rejected.");
    }
}
