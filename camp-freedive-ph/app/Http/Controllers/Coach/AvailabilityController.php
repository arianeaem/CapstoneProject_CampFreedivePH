<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\AssignmentReleaseRequest;
use App\Models\Batch;
use App\Models\CoachAvailability;
use App\Models\ParticipantAssignment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use App\Http\Requests\Coach\ToggleAvailabilityRequest;
use App\Http\Requests\Coach\BulkUpdateAvailabilityRequest;
use App\Http\Requests\Coach\RequestReleaseRequest;

class AvailabilityController extends Controller
{
    /**
     * Page 2: Coach Availability Calendar.
     */
    public function index(Request $request): View
    {
        $coach = Auth::user();

        // Month Navigation (Default to current month or requested year/month)
        $year = (int) $request->input('year', Carbon::now()->year);
        $month = (int) $request->input('month', Carbon::now()->month);

        $currentMonth = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $prevMonth = $currentMonth->copy()->subMonth();
        $nextMonth = $currentMonth->copy()->addMonth();

        $startOfCalendar = $currentMonth->copy()->startOfWeek(Carbon::SUNDAY);
        $endOfCalendar = $currentMonth->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        // Fetch all availabilities for this coach within calendar window
        $availabilities = CoachAvailability::where('coach_id', $coach->id)
            ->whereBetween('date', [$startOfCalendar->format('Y-m-d'), $endOfCalendar->format('Y-m-d')])
            ->get()
            ->keyBy(fn($a) => $a->date->format('Y-m-d'));

        // Fetch all active assignments for this coach in calendar window
        $rawAssignments = ParticipantAssignment::with('batch')
            ->where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->where(function ($q) use ($startOfCalendar, $endOfCalendar) {
                $q->whereBetween('dive_date', [$startOfCalendar->format('Y-m-d'), $endOfCalendar->format('Y-m-d')])
                  ->orWhereHas('batch', function ($bq) use ($startOfCalendar, $endOfCalendar) {
                      $bq->where('start_date', '<=', $endOfCalendar->format('Y-m-d'))
                         ->where('end_date', '>=', $startOfCalendar->format('Y-m-d'));
                  });
            })
            ->get();

        // Map assignments to every date covered by the batch (e.g. Saturday + Sunday for 2D1N batches)
        $assignmentsByDate = collect();
        foreach ($rawAssignments as $assignment) {
            $batch = $assignment->batch;
            $dates = [];
            if ($batch && $batch->start_date && $batch->end_date) {
                $c = $batch->start_date->copy();
                while ($c->lte($batch->end_date)) {
                    $dates[] = [
                        'date_str' => $c->format('Y-m-d'),
                        'day_num' => ($c->format('Y-m-d') === $batch->start_date->format('Y-m-d')) ? 1 : 2,
                    ];
                    $c->addDay();
                }
            } elseif ($assignment->dive_date) {
                $dates[] = [
                    'date_str' => $assignment->dive_date->format('Y-m-d'),
                    'day_num' => 1,
                ];
                if ($assignment->dive_date->isSaturday()) {
                    $dates[] = [
                        'date_str' => $assignment->dive_date->copy()->addDay()->format('Y-m-d'),
                        'day_num' => 2,
                    ];
                }
            }

            foreach ($dates as $dInfo) {
                $dStr = $dInfo['date_str'];
                if (!$assignmentsByDate->has($dStr)) {
                    $assignmentsByDate->put($dStr, collect());
                }
                $assignmentsByDate->get($dStr)->push([
                    'assignment' => $assignment,
                    'day_num' => $dInfo['day_num'],
                ]);
            }
        }

        // Fetch active release requests
        $releaseRequests = AssignmentReleaseRequest::where('coach_id', $coach->id)
            ->whereIn('status', ['pending', 'approved'])
            ->get()
            ->keyBy(fn($r) => $r->dive_date->format('Y-m-d'));

        // Build calendar grid days
        $calendarDays = [];
        $dayCursor = $startOfCalendar->copy();

        while ($dayCursor->lte($endOfCalendar)) {
            $dateStr = $dayCursor->format('Y-m-d');
            $assignmentList = $assignmentsByDate->get($dateStr, collect());
            $isAssigned = $assignmentList->isNotEmpty();
            $firstAssignedEntry = $assignmentList->first();
            $firstAssignment = is_array($firstAssignedEntry) ? ($firstAssignedEntry['assignment'] ?? null) : $firstAssignedEntry;
            $batch = $firstAssignment?->batch;

            // Also check if CoachAvailability is explicitly marked as assigned
            $availRecord = $availabilities->get($dateStr);
            if (!$isAssigned && $availRecord && $availRecord->status === 'assigned') {
                $isAssigned = true;
            }

            $assignedDayNumber = 1;
            if ($isAssigned) {
                if (is_array($firstAssignedEntry) && isset($firstAssignedEntry['day_num'])) {
                    $assignedDayNumber = $firstAssignedEntry['day_num'];
                } elseif ($batch && $batch->start_date && $batch->end_date) {
                    $assignedDayNumber = ($dateStr === $batch->end_date->format('Y-m-d') || $dayCursor->isSunday()) ? 2 : 1;
                } elseif ($dayCursor->isSunday()) {
                    $assignedDayNumber = 2;
                } else {
                    $assignedDayNumber = 1;
                }
            }

            $hasReleaseRequest = $releaseRequests->has($dateStr);
            $releaseRequest = $releaseRequests->get($dateStr);

            $status = 'unset';
            if ($isAssigned) {
                $status = 'assigned';
            } elseif ($availRecord) {
                $status = $availRecord->status;
            }

            // Emergency release requests remain available regardless of lead time.
            $diveStart = $dayCursor->copy()->setTime(6, 30);
            $hoursUntilDive = max(0, Carbon::now()->diffInHours($diveStart, false));
            $canRequestRelease = $isAssigned && !$hasReleaseRequest;

            $calendarDays[] = [
                'date' => $dayCursor->copy(),
                'date_str' => $dateStr,
                'day_number' => (int) $dayCursor->format('j'),
                'is_current_month' => $dayCursor->month === $currentMonth->month,
                'is_today' => $dayCursor->isToday(),
                'is_past' => $dayCursor->isPast() && !$dayCursor->isToday(),
                'is_weekend' => $dayCursor->isWeekend(),
                'status' => $status,
                'is_assigned' => $isAssigned,
                'assigned_day_number' => $assignedDayNumber,
                'batch' => $batch,
                'students_count' => $assignmentList->count(),
                'has_release_request' => $hasReleaseRequest,
                'release_request' => $releaseRequest,
                'can_request_release' => $canRequestRelease,
                'hours_until_dive' => $hoursUntilDive,
            ];

            $dayCursor->addDay();
        }

        return view('coach.availability.index', compact(
            'coach',
            'currentMonth',
            'prevMonth',
            'nextMonth',
            'calendarDays',
            'year',
            'month'
        ));
    }

    /**
     * Toggle a single 2D1N date pair availability.
     */
    public function toggle(ToggleAvailabilityRequest $request): JsonResponse|RedirectResponse
    {
        $coach = Auth::user();
        $startDate = Carbon::parse($request->input('date'))->startOfDay();

        // Prevent modifying past dates
        if ($startDate->isPast() && !$startDate->isToday()) {
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => 'Cannot modify past dates.'], 422);
            }
            return back()->with('error', 'Cannot modify availability for past dates.');
        }

        // 2D1N Auto-Pairing: If Saturday -> Sunday, if Sunday -> Saturday, otherwise Day + (Day + 1)
        if ($startDate->isSaturday()) {
            $day1 = $startDate->copy();
            $day2 = $startDate->copy()->addDay();
        } elseif ($startDate->isSunday()) {
            $day1 = $startDate->copy()->subDay();
            $day2 = $startDate->copy();
        } else {
            $day1 = $startDate->copy();
            $day2 = $startDate->copy()->addDay();
        }

        $datesToUpdate = [$day1->format('Y-m-d'), $day2->format('Y-m-d')];

        // Check if either date is already assigned via ParticipantAssignment or CoachAvailability
        $hasAssignment = ParticipantAssignment::where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->where(function ($q) use ($datesToUpdate) {
                $q->whereIn('dive_date', $datesToUpdate)
                  ->orWhereHas('batch', function ($bq) use ($datesToUpdate) {
                      $bq->where(function ($sub) use ($datesToUpdate) {
                          foreach ($datesToUpdate as $dt) {
                              $sub->orWhere(function ($s) use ($dt) {
                                  $s->whereDate('start_date', '<=', $dt)
                                    ->whereDate('end_date', '>=', $dt);
                              });
                          }
                      });
                  });
            })
            ->exists();

        if (!$hasAssignment) {
            $hasAssignment = CoachAvailability::where('coach_id', $coach->id)
                ->where('status', 'assigned')
                ->whereIn('date', $datesToUpdate)
                ->exists();
        }

        if ($hasAssignment) {
            $msg = 'Assigned dates are locked from self-editing. Please submit an emergency release request if you cannot attend.';
            if ($request->wantsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        // Check current status of day 1 to determine toggle target
        $currentRecord = CoachAvailability::where('coach_id', $coach->id)
            ->where('date', $day1->format('Y-m-d'))
            ->first();

        // If currently 'available' -> remove availability (unselect); otherwise -> set to 'available'
        if ($currentRecord && $currentRecord->status === 'available') {
            CoachAvailability::where('coach_id', $coach->id)
                ->whereIn('date', $datesToUpdate)
                ->delete();

            $newStatus = 'unset';
            $message = "Availability removed for {$day1->format('M d')} and {$day2->format('M d, Y')}.";
        } else {
            foreach ($datesToUpdate as $d) {
                CoachAvailability::updateOrCreate(
                    [
                        'coach_id' => $coach->id,
                        'date' => $d,
                    ],
                    [
                        'status' => 'available',
                        'notes' => 'Selected via Coach Portal availability calendar.',
                    ]
                );
            }

            $newStatus = 'available';
            $message = "Availability selected for {$day1->format('M d')} and {$day2->format('M d, Y')}.";
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'status' => $newStatus,
                'dates' => $datesToUpdate,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Bulk Edit Mode: Apply availability status across multiple selected date pairs.
     */
    public function bulkUpdate(BulkUpdateAvailabilityRequest $request): JsonResponse|RedirectResponse
    {
        $coach = Auth::user();
        $targetStatus = $request->input('status');
        $rawDates = $request->input('dates');

        $expandedDates = [];
        foreach ($rawDates as $dStr) {
            $d = Carbon::parse($dStr)->startOfDay();
            if ($d->isPast() && !$d->isToday()) {
                continue;
            }

            // 2D1N pairing
            if ($d->isSaturday()) {
                $expandedDates[] = $d->format('Y-m-d');
                $expandedDates[] = $d->copy()->addDay()->format('Y-m-d');
            } elseif ($d->isSunday()) {
                $expandedDates[] = $d->copy()->subDay()->format('Y-m-d');
                $expandedDates[] = $d->format('Y-m-d');
            } else {
                $expandedDates[] = $d->format('Y-m-d');
                $expandedDates[] = $d->copy()->addDay()->format('Y-m-d');
            }
        }

        $uniqueDates = array_values(array_unique($expandedDates));

        // Filter out locked assigned dates
        $assignedDatesFromAssignments = ParticipantAssignment::with('batch')
            ->where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->get()
            ->flatMap(function ($a) {
                if ($a->batch && $a->batch->start_date && $a->batch->end_date) {
                    $dates = [];
                    $cur = $a->batch->start_date->copy();
                    while ($cur->lte($a->batch->end_date)) {
                        $dates[] = $cur->format('Y-m-d');
                        $cur->addDay();
                    }
                    return $dates;
                }
                return [$a->dive_date ? $a->dive_date->format('Y-m-d') : null];
            })
            ->filter()
            ->toArray();

        $assignedDatesFromAvail = CoachAvailability::where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->pluck('date')
            ->map(fn($d) => Carbon::parse($d)->format('Y-m-d'))
            ->toArray();

        $lockedAssignedDates = array_unique(array_merge($assignedDatesFromAssignments, $assignedDatesFromAvail));

        $updatedCount = 0;
        foreach ($uniqueDates as $d) {
            if (in_array($d, $lockedAssignedDates)) {
                continue; // Skip locked assigned dates
            }

            if ($targetStatus === 'available') {
                CoachAvailability::updateOrCreate(
                    [
                        'coach_id' => $coach->id,
                        'date' => $d,
                    ],
                    [
                        'status' => 'available',
                        'notes' => 'Updated via Coach Portal Bulk Edit.',
                    ]
                );
            } else {
                CoachAvailability::where('coach_id', $coach->id)
                    ->where('date', $d)
                    ->delete();
            }
            $updatedCount++;
        }

        $label = $targetStatus === 'available' ? 'Available' : 'Unavailable';
        $message = "Successfully updated {$updatedCount} days to {$label}. (Assigned dates remained locked).";

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'updated_count' => $updatedCount,
            ]);
        }

        return back()->with('success', $message);
    }

    /**
     * Submit an Emergency Release Request for an assigned batch/date.
     * Emergency releases may be requested at any time.
     */
    public function requestRelease(RequestReleaseRequest $request): RedirectResponse
    {
        $coach = Auth::user();
        $batch = Batch::findOrFail($request->input('batch_id'));
        $diveDate = Carbon::parse($request->input('dive_date'))->startOfDay();

        // Check if existing pending request
        $existing = AssignmentReleaseRequest::where('coach_id', $coach->id)
            ->where('batch_id', $batch->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return back()->with('error', 'You already have a pending release request submitted for this session.');
        }

        $release = AssignmentReleaseRequest::create([
            'coach_id' => $coach->id,
            'batch_id' => $batch->id,
            'dive_date' => $diveDate,
            'reason' => $request->input('reason'),
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        app(\App\Services\AdminNotificationService::class)->coachEmergencyRelease($release->load(['coach', 'batch']));

        return back()->with('success', 'Your emergency release request has been submitted to Camp Admin for review.');
    }
}
