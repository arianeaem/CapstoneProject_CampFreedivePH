<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ValidatesListFilters;
use App\Http\Controllers\Controller;
use App\Models\BookingParticipant;
use App\Models\User;
use App\Services\CoachMatchingService;
use App\Services\CoachWorkloadService;
use App\Services\Exports\ManagementListExporter;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use App\Http\Requests\Admin\Coaches\ReassignStudentRequest;

class CoachRosterController extends Controller
{
    use ValidatesListFilters;

    public function __construct(
        protected CoachMatchingService $matchingService
    ) {}

    /**
     * Page 1: coach list.
     */
    public function index(Request $request, CoachWorkloadService $workloadService): View
    {
        $coaches = $this->filteredCoaches($request);

        $activeCount = User::where('role', 'coach')->where('status', 'active')->count();
        $inactiveCount = User::where('role', 'coach')->where('status', 'inactive')->count();

        // Number of participants with no coach (for the banner)
        $unassignedStudentsCount = BookingParticipant::whereHas('booking', function ($q) {
            $q->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'completed', 'no_show', 'pending_downpayment', 'paid_unallocated']);
        })->whereDoesntHave('activeAssignment')->count();

        // Paginate
        $page = (int) $request->input('page', 1);
        $perPage = max(5, min(100, (int) $request->input('per_page', 10)));
        $total = $coaches->count();
        $coaches = new \Illuminate\Pagination\LengthAwarePaginator(
            $coaches->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Card figures for the coaches on this page
        $workload = $workloadService->forCoaches($coaches->getCollection());

        return view('admin.coaches.index', compact(
            'coaches',
            'workload',
            'activeCount',
            'inactiveCount',
            'unassignedStudentsCount'
        ));
    }

    protected const LIST_FILTERS = ['search', 'status', 'available_on', 'has_capacity'];

    /**
     * Excel copy of the coach roster (all pages), with contact details for staff.
     */
    public function export(Request $request, ManagementListExporter $exporter, CoachWorkloadService $workloadService): Response
    {
        $coaches = $this->filteredCoaches($request);
        $workload = $workloadService->forCoaches($coaches);

        $rows = $coaches->map(fn (User $c) => [
            $c->name,
            $c->email,
            $c->phone ?? '',
            ucfirst($c->status),
            $workload[$c->id]['upcoming_days'],
            $workload[$c->id]['next_dive_date'] ?? 'None',
            $workload[$c->id]['load_on_next'],
            $workload[$c->id]['open_dates'],
            $c->activeAssignedParticipants->count(),
        ]);

        return $exporter->download($request, 'coaches', 'Instructor roster', $this->listFilterSummary($request), ManagementListExporter::auditFilters($request, self::LIST_FILTERS),
            ['Name', 'Email', 'Phone', 'Account status', 'Upcoming dive days', 'Next assignment', 'Load on next', 'Open availability dates', 'Participants assigned'],
            [26, 30, 16, 14, 18, 16, 13, 22, 20],
            $rows);
    }

    /**
     * Printable instructor roster (A4 portrait). No contact details.
     */
    public function printList(Request $request, ManagementListExporter $exporter, CoachWorkloadService $workloadService): Response
    {
        $coaches = $this->filteredCoaches($request);
        $workload = $coaches->count() <= ManagementListExporter::MAX_PRINT_ROWS ? $workloadService->forCoaches($coaches) : [];

        return $exporter->print('Instructor roster', false, $this->listFilterSummary($request), [
            ['Name', 'font-semibold'], ['Account status', ''], ['Upcoming dive days', 'num'], ['Next assignment', 'nowrap'], ['Load', 'num'], ['Open dates', 'num'],
        ], $coaches, fn (User $c) => [
            $c->name,
            ucfirst($c->status),
            $workload[$c->id]['upcoming_days'],
            $workload[$c->id]['next_dive_date']?->format('M d, Y') ?? 'None',
            // Same "x / max" load as the roster card
            $workload[$c->id]['load_on_next'] . ' / ' . ($c->max_ratio ?? 4),
            $workload[$c->id]['open_dates'],
        ], portal_route('coaches.index', $request->except(['page', 'per_page'])));
    }

    /**
     * Filters shared by the roster, the Excel export and the printout (sorted by name).
     */
    protected function filteredCoaches(Request $request): Collection
    {
        $this->validateListFilters($request, [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'archived'])],
            'available_on' => ['nullable', 'date_format:Y-m-d'],
            'has_capacity' => ['nullable', 'string', Rule::in(['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'])],
        ]);

        $query = User::where('role', 'coach')
            ->with(['coachAvailabilities', 'activeAssignedParticipants.batch']);

        // Filter by status (archived coaches only show if picked)
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        } else {
            $query->where('status', '!=', 'archived');
        }

        // Search by name, email or phone
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->whereLike('name', "%{$search}%")
                  ->orWhereLike('email', "%{$search}%")
                  ->orWhereLike('phone', "%{$search}%");
            });
        }

        $coaches = $query->orderBy('name')->get();

        // Filter by available date (done in PHP)
        if ($request->filled('available_on')) {
            $date = Carbon::parse($request->input('available_on'))->format('Y-m-d');
            $coaches = $coaches->filter(function ($coach) use ($date) {
                // date is cast to a Carbon date, so compare it as Y-m-d (one row per coach per day)
                $avail = $coach->coachAvailabilities->first(fn ($a) => $a->date?->format('Y-m-d') === $date);
                return $avail && in_array($avail->status, ['available', 'assigned']);
            });
        }

        // Filter by free capacity
        if ($request->boolean('has_capacity')) {
            $today = Carbon::today()->format('Y-m-d');
            $coaches = $coaches->filter(function ($coach) use ($today) {
                return $coach->assignedCountForDate($today) < 4;
            });
        }

        return $coaches->values();
    }

    /** Readable list of the filters in use, for the export summary and the printout. */
    protected function listFilterSummary(Request $request): array
    {
        return array_filter([
            'Search' => $request->filled('search') ? self::searchLabel($request->input('search')) : null,
            'Account status' => $request->filled('status') ? ucfirst($request->input('status')) : 'Active and inactive (archived hidden, default)',
            'Available on' => $request->input('available_on'),
            'Free capacity today' => $request->boolean('has_capacity') ? 'Only coaches with room today' : null,
            'Sorted by' => 'Name (A–Z)',
        ]);
    }


    /**
     * Page 2: coach details.
     */
    public function show(User $coach): View
    {
        if ($coach->role !== 'coach') {
            abort(404, 'User is not a freediving coach.');
        }

        $coach->load([
            'coachAvailabilities' => fn($q) => $q->orderBy('date', 'desc'),
            'assignedParticipants' => fn($q) => $q->with(['participant.booking', 'batch'])->orderBy('dive_date', 'desc'),
        ]);

        // 1. Assigned participants (active / upcoming)
        $activeAssignments = $coach->assignedParticipants()
            ->where('status', 'assigned')
            ->where('dive_date', '>=', Carbon::today())
            ->with(['participant.booking', 'batch'])
            ->orderBy('dive_date', 'asc')
            ->get();

        // 2. Upcoming dive dates
        $upcomingAssignments = $activeAssignments;

        // 3. Past dives (grouped by batch)
        $pastAssignments = $coach->assignedParticipants()
            ->where(function ($q) {
                $q->where('dive_date', '<', Carbon::today())
                  ->orWhere('status', 'completed');
            })
            ->with(['participant.booking', 'batch'])
            ->orderBy('dive_date', 'desc')
            ->get();

        $pastBatches = $pastAssignments->groupBy(function ($assignment) {
            return $assignment->batch_id ? 'batch_' . $assignment->batch_id : 'date_' . $assignment->dive_date->format('Y-m-d');
        });

        // Active coaches for the reassign popup
        $otherCoaches = User::where('role', 'coach')
            ->where('status', 'active')
            ->where('id', '!=', $coach->id)
            ->get();

        return view('admin.coaches.show', compact(
            'coach',
            'activeAssignments',
            'upcomingAssignments',
            'pastAssignments',
            'pastBatches',
            'otherCoaches'
        ));
    }

    /**
     * Move a participant from this coach to another one.
     */
    public function reassignStudent(ReassignStudentRequest $request, User $coach): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $participant = BookingParticipant::findOrFail($validated['participant_id']);
            $newCoach = User::findOrFail($validated['new_coach_id']);

            $this->matchingService->reassignStudent(
                $participant,
                $newCoach,
                auth()->user(),
                $validated['reason']
            );

            return back()->with('success', "Participant {$participant->name} successfully reassigned to Coach {$newCoach->name}.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
