<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BookingParticipant;
use App\Models\User;
use App\Services\CoachMatchingService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Requests\Admin\Coaches\ReassignStudentRequest;

class CoachRosterController extends Controller
{
    public function __construct(
        protected CoachMatchingService $matchingService
    ) {}

    /**
     * Page 1: coach list.
     */
    public function index(Request $request): View
    {
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
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $coaches = $query->orderBy('name')->get();

        // Filter by available date (done in PHP)
        if ($request->filled('available_on')) {
            $date = Carbon::parse($request->input('available_on'))->format('Y-m-d');
            $coaches = $coaches->filter(function ($coach) use ($date) {
                $avail = $coach->coachAvailabilities->firstWhere('date', $date);
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

        $activeCount = User::where('role', 'coach')->where('status', 'active')->count();
        $inactiveCount = User::where('role', 'coach')->where('status', 'inactive')->count();

        // Number of students with no coach (for the banner)
        $unassignedStudentsCount = BookingParticipant::whereHas('booking', function ($q) {
            $q->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'completed', 'no_show', 'pending_downpayment']);
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

        return view('admin.coaches.index', compact(
            'coaches',
            'activeCount',
            'inactiveCount',
            'unassignedStudentsCount'
        ));
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

        // 1. Assigned students (active / upcoming)
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
     * Move a student from this coach to another one.
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

            return back()->with('success', "Student {$participant->name} successfully reassigned to Coach {$newCoach->name}.");
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
