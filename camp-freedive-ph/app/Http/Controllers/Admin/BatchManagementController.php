<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\User;
use App\Services\BatchManagementService;
use App\Services\DemandForecastService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Requests\Admin\Batches\StoreBatchRequest;
use App\Http\Requests\Admin\Batches\AssignParticipantRequest;
use App\Http\Requests\Admin\Batches\UpdateBatchStatusRequest;
use App\Http\Requests\Admin\Batches\MoveBookingRequest;

/**
 * Admin pages for batches.
 *
 * - create, confirm, run and complete weekend batches
 * - group confirmed bookings into batches (1 coach for every 4 students)
 * - shows the demand forecast to help decide on extra slots or staff
 */
class BatchManagementController extends Controller
{
    public function __construct(
        protected BatchManagementService $batchService,
        protected DemandForecastService $forecastService
    ) {}

    // TODO: calendar feed (iCal / Google Calendar) for coaches

    /**
     * Page 1: batch list.
     *
     * @param Request $request filters: status, date_from, date_to, search, sort
     * @return View
     */
    public function index(Request $request): View
    {
        $query = Batch::with(['bookings.participants', 'activeParticipantAssignments.coach']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by date range
        if ($request->filled('date_from')) {
            $query->whereDate('start_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('start_date', '<=', $request->input('date_to'));
        }


        // Sorting
        $sort = $request->input('sort', 'date_asc');
        match ($sort) {
            'date_desc' => $query->orderBy('start_date', 'desc')->orderBy('created_at', 'desc'),
            'date_asc' => $query->orderBy('start_date', 'asc')->orderBy('created_at', 'desc'),
            'batch_asc' => $query->orderBy('batch_code', 'asc'),
            'batch_desc' => $query->orderBy('batch_code', 'desc'),
            'created_desc' => $query->latest('created_at'),
            'created_asc' => $query->oldest('created_at'),
            default => $query->orderBy('start_date', 'asc')->orderBy('created_at', 'desc'),
        };

        $batches = $query->get();
        Batch::preloadAssignedCoaches($batches);

        // Search by batch number / code / name / notes, or coach name
        if ($request->filled('search')) {
            $needle = mb_strtolower(trim($request->input('search')));
            $batches = $batches->filter(function ($b) use ($needle) {
                $haystacks = array_merge(
                    [$b->batch_number, $b->batch_code, $b->name, $b->notes],
                    $b->assigned_coaches->pluck('name')->all()
                );
                foreach ($haystacks as $h) {
                    if ($h !== null && str_contains(mb_strtolower((string) $h), $needle)) {
                        return true;
                    }
                }
                return false;
            })->values();
        }

        // Filter by coach
        if ($request->filled('coach')) {
            $coachId = (int) $request->input('coach');
            $batches = $batches->filter(fn ($b) => $b->assigned_coaches->pluck('id')->contains($coachId))->values();
        }

        // Filter by staffing status (done in PHP, not SQL)
        if ($request->filled('staffing')) {
            if ($request->input('staffing') === 'pending') {
                $batches = $batches->filter(fn($b) => $b->is_coach_pending);
            } elseif ($request->input('staffing') === 'staffed') {
                $batches = $batches->filter(fn($b) => !$b->is_coach_pending);
            }
        }

        // Sort by capacity (done in PHP, not SQL)
        if ($sort === 'capacity_desc') {
            $batches = $batches->sortByDesc(fn($b) => $b->total_participants_count)->values();
        } elseif ($sort === 'capacity_asc') {
            $batches = $batches->sortBy(fn($b) => $b->total_participants_count)->values();
        }

        // Number of batches that need attention
        $attentionCount = $batches->filter(fn($b) => $b->needs_attention)->count();

        // Confirmed bookings with no batch yet (newest first)
        $unbatchedBookings = Booking::whereNull('batch_id')
            ->where('status', 'confirmed')
            ->with('participants')
            ->orderBy('created_at', 'desc')
            ->get();
        $unbatchedCount = $unbatchedBookings->count();
        $unbatchedPaxCount = $unbatchedBookings->sum(fn($b) => $b->participants->count());

        // Paginate
        $page = (int) $request->input('page', 1);
        $perPage = max(4, min(100, (int) $request->input('per_page', 12)));
        $total = $batches->count();
        $batches = new \Illuminate\Pagination\LengthAwarePaginator(
            $batches->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $coachOptions = User::where('role', 'coach')
            ->where('status', '!=', 'archived')
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('admin.batches.index', compact(
            'coachOptions',
            'batches', 
            'attentionCount', 
            'unbatchedBookings', 
            'unbatchedCount', 
            'unbatchedPaxCount'
        ));
    }


    /**
     * Page 2: create batch form.
     */
    public function create(Request $request): View
    {
        $defaultDate = $request->filled('date') ? Carbon::parse($request->input('date')) : null;
        $defaultEndDate = $defaultDate ? $defaultDate->copy()->addDay() : null;
        $defaultStartDateStr = $defaultDate ? $defaultDate->format('Y-m-d') : '';
        $defaultEndDateStr = $defaultEndDate ? $defaultEndDate->format('Y-m-d') : '';
        $suggestedNum = $defaultDate ? $this->batchService->getNextBatchNumber($defaultDate) : $this->batchService->getNextBatchNumber();
        $defaultBatchNumber = 'Batch ' . $suggestedNum;

        $unbatchedBookings = $defaultDate ? $this->batchService->getUnbatchedBookingsForDate($defaultDate) : collect();

        $initialBookings = $unbatchedBookings->map(function ($b) {
            return [
                'id' => $b->id,
                'booking_number' => $b->booking_number,
                'contact_name' => $b->contact_name,
                'class_type' => ucfirst($b->class_type ?? 'Discovery'),
                'participants_count' => $b->participants->count(),
                'total_amount' => number_format($b->total_amount, 2),
            ];
        })->values()->all();

        $selectedIds = $unbatchedBookings->pluck('id')->values()->all();
        $initialStaffingRec = $defaultDate ? $this->forecastService->getStaffingRecommendationForDate($defaultDate) : null;

        $existingBatches = Batch::all()->map(function ($b) {
            return [
                'id' => $b->id,
                'batch_number' => $b->batch_number,
                'name' => $b->name,
                'batch_code' => $b->batch_code,
                'start_date' => $b->start_date->format('Y-m-d'),
                'end_date' => $b->end_date->format('Y-m-d'),
                'status' => $b->status,
                'participants_count' => $b->total_participants_count,
                'coaches_count' => $b->assigned_coaches_count,
            ];
        })->values()->all();

        return view('admin.batches.create', compact(
            'defaultDate',
            'defaultStartDateStr',
            'defaultEndDateStr',
            'defaultBatchNumber',
            'unbatchedBookings',
            'initialBookings',
            'selectedIds',
            'existingBatches',
            'initialStaffingRec'
        ));
    }

    /**
     * AJAX: get the bookings with no batch when the date is changed on the create form.
     */
    public function unbatchedBookings(Request $request): JsonResponse
    {
        $dateStr = $request->input('date', Carbon::today()->format('Y-m-d'));
        $date = Carbon::parse($dateStr);
        $bookings = $this->batchService->getUnbatchedBookingsForDate($date);
        $suggestedNum = $this->batchService->getNextBatchNumber($date);
        $mlRec = $this->forecastService->getStaffingRecommendationForDate($date);

        $existingForDate = Batch::whereDate('start_date', $date)->get()->map(function ($b) {
            return [
                'id' => $b->id,
                'batch_number' => $b->batch_number,
                'name' => $b->name,
                'batch_code' => $b->batch_code,
                'status' => $b->status,
                'participants_count' => $b->total_participants_count,
                'coaches_count' => $b->assigned_coaches_count,
            ];
        })->values()->all();

        return response()->json([
            'date' => $date->format('Y-m-d'),
            'suggested_batch_number' => 'Batch ' . $suggestedNum,
            'suggested_batch_number_only' => (string) $suggestedNum,
            'suggested_name' => 'Batch ' . $suggestedNum,
            'suggested_code' => 'Batch ' . $suggestedNum,
            'count' => $bookings->count(),
            'existing_batches' => $existingForDate,
            'ml_recommendation' => $mlRec,
            'bookings' => $bookings->map(function ($b) {
                return [
                    'id' => $b->id,
                    'booking_number' => $b->booking_number,
                    'contact_name' => $b->contact_name,
                    'class_type' => ucfirst($b->class_type ?? 'Discovery'),
                    'participants_count' => $b->participants->count(),
                    'total_amount' => number_format($b->total_amount, 2),
                ];
            }),
        ]);
    }

    /**
     * Save a new batch.
     */
    public function store(StoreBatchRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $batchRaw = $validated['batch_number'] ?? $request->input('batch_number_digits') ?? '';
        if (preg_match('/(\d+)/', (string) $batchRaw, $m)) {
            $batchIdentifier = 'Batch ' . $m[1];
        } else {
            $nextNum = $this->batchService->getNextBatchNumber(Carbon::parse($validated['start_date']));
            $batchIdentifier = 'Batch ' . $nextNum;
        }

        $validated['batch_number'] = $batchIdentifier;
        $validated['batch_code'] = $batchIdentifier;
        $validated['name'] = $batchIdentifier;

        // Don't allow two batches with the same start date
        $existingBatch = Batch::whereDate('start_date', $validated['start_date'])->first();
        if ($existingBatch) {
            $bookingIds = $request->input('booking_ids', []);
            if (!empty($bookingIds)) {
                Booking::whereIn('id', $bookingIds)->update(['batch_id' => $existingBatch->id]);
            }

            return redirect()->route('admin.batches.show', $existingBatch)
                ->with('info', "A batch for {$existingBatch->start_date->format('M d, Y')} already exists ({$existingBatch->batch_code}). Redirected to existing batch" . (!empty($bookingIds) ? " and " . count($bookingIds) . " booking(s) were assigned to it." : "."));
        }

        try {
            $batch = $this->batchService->createBatch(
                $validated,
                $request->input('booking_ids', []),
                auth()->user()
            );

            return redirect()->route('admin.batches.show', $batch)
                ->with('success', "Batch {$batch->name} ({$batch->batch_code}) created with " . count($request->input('booking_ids', [])) . " linked booking(s).");
        } catch (Exception $e) {
            return back()->withInput()->with('error', 'Batch creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Page 3: batch details.
     */
    public function show(Batch $batch): View
    {
        $batch->load([
            'bookings.participants.assignments.coach',
            'activeParticipantAssignments.coach',
            'statusLogs.changer',
            'creator',
        ]);

        // Coaches assigned to this batch
        $assignedCoaches = $batch->assigned_coaches;

        // Students with no coach yet
        $unassignedStudentsCount = $batch->bookings->flatMap->participants
            ->filter(fn($p) => !$p->activeAssignment)
            ->count();

        // Other batches for the "Move Booking" popup
        $otherBatches = Batch::where('id', '!=', $batch->id)
            ->orderBy('start_date', 'desc')
            ->get();

        return view('admin.batches.show', compact(
            'batch',
            'assignedCoaches',
            'unassignedStudentsCount',
            'otherBatches'
        ));
    }

    /**
     * Quickly assign a participant to one of the batch's coaches.
     */
    public function assignParticipant(AssignParticipantRequest $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validated();

        $participant = \App\Models\BookingParticipant::findOrFail($validated['participant_id']);

        if ($participant->booking?->batch_id !== $batch->id) {
            return back()->with('error', 'Participant does not belong to this batch.');
        }

        $diveDate = $batch->start_date;
        $assignedBy = auth()->user();

        \Illuminate\Support\Facades\DB::transaction(function () use ($batch, $participant, $validated, $diveDate, $assignedBy) {
            $oldAssignment = \App\Models\ParticipantAssignment::where('participant_id', $participant->id)
                ->where('status', 'assigned')
                ->first();

            $oldCoachId = $oldAssignment?->coach_id;

            if (empty($validated['coach_id'])) {
                if ($oldAssignment) {
                    $oldAssignment->delete();
                    \App\Models\AssignmentLog::create([
                        'participant_id' => $participant->id,
                        'old_coach_id' => $oldCoachId,
                        'new_coach_id' => null,
                        'changed_by' => $assignedBy->id,
                        'reason' => 'On-site unassigned from pod.',
                    ]);
                }
            } else {
                $newCoach = \App\Models\User::findOrFail($validated['coach_id']);

                if ($oldAssignment) {
                    $oldAssignment->update([
                        'coach_id' => $newCoach->id,
                        'batch_id' => $batch->id,
                        'dive_date' => $diveDate,
                        'assigned_by' => $assignedBy->id,
                        'assigned_at' => now(),
                    ]);
                } else {
                    \App\Models\ParticipantAssignment::create([
                        'participant_id' => $participant->id,
                        'booking_id' => $participant->booking_id,
                        'coach_id' => $newCoach->id,
                        'batch_id' => $batch->id,
                        'dive_date' => $diveDate,
                        'assigned_by' => $assignedBy->id,
                        'assigned_at' => now(),
                        'status' => 'assigned',
                    ]);
                }

                \App\Models\AssignmentLog::create([
                    'participant_id' => $participant->id,
                    'old_coach_id' => $oldCoachId,
                    'new_coach_id' => $newCoach->id,
                    'changed_by' => $assignedBy->id,
                    'reason' => 'On-site pod assignment during session.',
                ]);
            }
        });

        $coachName = !empty($validated['coach_id']) ? \App\Models\User::find($validated['coach_id'])?->name : 'Shared Pool';
        return back()->with('success', "Assigned {$participant->name} to {$coachName}.");
    }

    /**
     * Change the batch status (also updates its bookings).
     */
    public function updateStatus(UpdateBatchStatusRequest $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $this->batchService->updateStatus(
                $batch,
                $validated['status'],
                auth()->user(),
                $validated['note'] ?? null
            );

            $actionLabel = match ($validated['status']) {
                'cancelled_by_camp' => 'Cancelled by Camp (Full refund eligibility triggered for all connected bookings)',
                'completed' => 'Marked as Completed',
                'rescheduled' => 'Rescheduled (Customer email notifications dispatched)',
                default => 'Updated to ' . ucfirst($validated['status']),
            };

            return back()->with('success', "Batch status updated: {$actionLabel}.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Move a booking from this batch to another one.
     */
    public function moveBooking(MoveBookingRequest $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $booking = Booking::findOrFail($validated['booking_id']);
            $targetBatch = $validated['target_batch_id'] ? Batch::findOrFail($validated['target_batch_id']) : null;

            $this->batchService->moveBooking(
                $booking,
                $targetBatch,
                auth()->user(),
                $validated['reason'] ?? null
            );

            return back()->with('success', "Booking {$booking->booking_number} moved successfully.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
