<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\Coaches\AssignCoachesRequest;
use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\CoachOpening;
use App\Models\CoachRequest;
use App\Models\ParticipantAssignment;
use App\Models\User;
use App\Services\CoachMatchingService;
use App\Services\DemandForecastService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Requests\Admin\Coaches\UnassignCoachRequest;
use App\Http\Requests\Admin\Coaches\BroadcastOpeningRequest;
use App\Http\Requests\Admin\Coaches\BulkApproveCoachRequestsRequest;

class CoachMatchingController extends Controller
{
    private const RECENT_WORKLOAD_DAYS = 30;

    public function __construct(
        protected CoachMatchingService $matchingService,
        protected DemandForecastService $forecastService
    ) {}

    /**
     * Coach assignment page.
     */
    public function matching(): View
    {
        $today = Carbon::today();

        // 1. Upcoming active batches with participants
        $batches = Batch::with(['bookings.participants', 'activeParticipantAssignments.coach'])
            ->whereNotIn('status', ['completed', 'cancelled_by_camp'])
            ->orderBy('start_date', 'asc')
            ->get()
            ->filter(function ($batch) use ($today) {
                $isDone = ($batch->end_date && $batch->end_date->lt($today))
                    || ($batch->start_date && !$batch->end_date && $batch->start_date->lt($today))
                    || in_array($batch->status, ['completed', 'cancelled_by_camp']);

                // Only batches with at least 1 participant
                return !$isDone && ($batch->total_participants_count > 0);
            })
            ->values();
        Batch::preloadAssignedCoaches($batches);

        // 2. Active coaches and their availability
        $activeCoaches = User::where('role', 'coach')
            ->where('status', 'active')
            ->with(['coachAvailabilities'])
            ->orderBy('name', 'asc')
            ->get();

        $recentWorkloadStart = now()->subDays(self::RECENT_WORKLOAD_DAYS);
        $recentStudentCounts = ParticipantAssignment::query()
            ->whereIn('coach_id', $activeCoaches->pluck('id'))
            ->where('assigned_at', '>=', $recentWorkloadStart)
            ->selectRaw('coach_id, COUNT(DISTINCT participant_id) as recent_student_count')
            ->groupBy('coach_id')
            ->pluck('recent_student_count', 'coach_id');
        $lastAssignedDates = ParticipantAssignment::query()
            ->whereIn('coach_id', $activeCoaches->pluck('id'))
            ->selectRaw('coach_id, MAX(assigned_at) as last_assigned_at')
            ->groupBy('coach_id')
            ->pluck('last_assigned_at', 'coach_id');

        $settingService = app(\App\Services\SystemSettingService::class);
        $coachRatio = (int) ($settingService->get('camp_operations.coach_student_ratio', 4) ?? 4);

        // Open slot posts for these batches in one query (first one per batch)
        $openBroadcasts = CoachOpening::whereIn('batch_id', $batches->pluck('id'))
            ->where('status', 'open')
            ->orderBy('id')
            ->get()
            ->unique('batch_id')
            ->keyBy('batch_id');

        // 3. Build the staffing data for each batch
        $batchData = $batches->map(function ($batch) use ($activeCoaches, $coachRatio, $recentStudentCounts, $lastAssignedDates, $openBroadcasts) {
            $totalParticipants = (int) $batch->total_participants_count;
            $neededCoaches = $totalParticipants > 0 ? (int) ceil($totalParticipants / $coachRatio) : 0;
            $assignedCoaches = $batch->assigned_coaches;
            $assignedCoachIds = $assignedCoaches->pluck('id')->toArray();

            $startDateStr = $batch->start_date->format('Y-m-d');
            $endDateStr = $batch->end_date ? $batch->end_date->format('Y-m-d') : $startDateStr;

            // Staffing suggestion from the demand forecast
            $mlRecommendation = $this->forecastService->getStaffingRecommendationForDate($batch->start_date);

            // Only coaches who are free on this batch's dates and not already assigned to it
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
            })->map(function ($coach) use ($recentStudentCounts, $lastAssignedDates) {
                $lastAssignedAt = $lastAssignedDates->get($coach->id);

                return [
                    'id' => $coach->id,
                    'name' => $coach->name,
                    'email' => $coach->email,
                    'phone' => $coach->phone,
                    'recent_student_count' => (int) $recentStudentCounts->get($coach->id, 0),
                    'last_assigned_at' => $lastAssignedAt ? Carbon::parse($lastAssignedAt) : null,
                    'is_assigned_here' => false,
                    'is_available_on_calendar' => true,
                    'coach_model' => $coach,
                ];
            })
            ->sortBy([
                ['recent_student_count', 'asc'],
                fn (array $first, array $second) => ($first['last_assigned_at']?->timestamp ?? 0)
                    <=> ($second['last_assigned_at']?->timestamp ?? 0),
            ])
            ->values()
            ->map(function (array $coach, int $index) {
                $coach['is_fairest_pick'] = $index === 0;
                $coach['days_since_last_assignment'] = $coach['last_assigned_at']
                    ? $coach['last_assigned_at']->startOfDay()->diffInDays(Carbon::today())
                    : null;

                return $coach;
            });

            // Is there already an open slot post?
            $openBroadcast = $openBroadcasts[$batch->id] ?? null;

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

        // Number of pending coach requests
        $pendingRequestsCount = CoachRequest::where('status', 'pending')->count();

        return view('admin.coaches.matching', [
            'batchData' => $batchData,
            'batchGroups' => $batchData, // Backward compatibility
            'pendingRequestsCount' => $pendingRequestsCount,
        ]);
    }

    /**
     * Assign one or more coaches to a batch.
     */
    public function batchAssign(AssignCoachesRequest $request): RedirectResponse
    {
        if ($request->has('assignments')) {
            $validated = $request->validated();

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
     * Assign the selected coaches to a batch.
     */
    public function assign(AssignCoachesRequest $request): RedirectResponse
    {
        // Old way: assign single students if participant_ids is sent
        if ($request->has('participant_ids')) {
            $validated = $request->validated();

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

        // Assign coaches to the batch
        $validated = $request->validated();

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
     * Remove a coach from a batch.
     */
    public function unassign(UnassignCoachRequest $request): RedirectResponse
    {
        $validated = $request->validated();

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
     * Post an open slot on the Coach Portal when no coach is available.
     */
    public function broadcastOpening(BroadcastOpeningRequest $request): RedirectResponse
    {
        $validated = $request->validated();

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
     * Page 4: coach requests.
     */
    public function requests(): View
    {
        $requests = CoachRequest::with(['opening.batch', 'coach', 'batch'])
            ->orderBy('status', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        $pendingRequests = $requests->where('status', 'pending')->groupBy('batch_id');
        $reviewedRequests = $requests->where('status', '!=', 'pending');

        // Approved coaches per batch (from the list we already have, so no extra queries in the view)
        $approvedByBatch = $requests->where('status', 'approved')->countBy('batch_id');
        $coachRatio = (int) (app(\App\Services\SystemSettingService::class)->get('camp_operations.coach_student_ratio', 4) ?? 4);

        return view('admin.coaches.requests', compact(
            'pendingRequests',
            'reviewedRequests',
            'approvedByBatch',
            'coachRatio'
        ));
    }

    /**
     * Approve a coach request.
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
     * Approve several coach requests at once.
     */
    public function bulkApproveRequests(BulkApproveCoachRequestsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

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
     * Approve a coach's emergency release request.
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

                // Remove the coach's students for that batch
                \App\Models\ParticipantAssignment::where('coach_id', $reqModel->coach_id)
                    ->where('batch_id', $reqModel->batch_id)
                    ->delete();

                // Mark the coach as unavailable on that date
                \App\Models\CoachAvailability::where('coach_id', $reqModel->coach_id)
                    ->where('date', $reqModel->dive_date)
                    ->update(['status' => 'unavailable']);

                // The other day of the weekend
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
     * Reject a coach's emergency release request.
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
