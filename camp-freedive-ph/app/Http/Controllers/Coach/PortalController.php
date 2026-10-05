<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\CoachAvailability;
use App\Models\CoachOpening;
use App\Models\CoachRequest;
use App\Models\ParticipantAssignment;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function __construct(
        protected WeatherForecastService $forecastService
    ) {}

    /**
     * Page 1: coach dashboard.
     */
    public function index(): View
    {
        $coach = Auth::user();
        $today = Carbon::today('Asia/Manila');

        // 1. Next dive assignment
        $nextAssignment = ParticipantAssignment::with(['batch.riskAssessments', 'participant', 'booking'])
            ->where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->whereDate('dive_date', '>=', $today)
            ->orderBy('dive_date', 'asc')
            ->first();

        $nextSessionData = null;
        if ($nextAssignment && $nextAssignment->batch) {
            $batch = $nextAssignment->batch;
            $assignedParticipants = ParticipantAssignment::with(['participant', 'booking'])
                ->where('coach_id', $coach->id)
                ->where('batch_id', $batch->id)
                ->where('status', 'assigned')
                ->get()
                ->pluck('participant')
                ->unique('id');

            // Class breakdown
            $classBreakdown = [];
            foreach ($assignedParticipants as $p) {
                $type = $p->booking?->formatted_class_type ?? 'Freediving Class';
                $classBreakdown[$type] = ($classBreakdown[$type] ?? 0) + 1;
            }

            // Weather for this batch
            $d1Assessment = $batch->latestDay1Assessment;
            $weatherClass = $d1Assessment ? $d1Assessment->overall_classification : 'Safe';
            $weatherBadge = $d1Assessment ? $d1Assessment->classification_badge : [
                'label' => 'Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
            ];

            // Emergency release can be requested any time
            $diveStart = $batch->start_date->copy()->setTime(6, 30);
            $hoursUntilDive = max(0, Carbon::now('Asia/Manila')->diffInHours($diveStart, false));
            $canRequestRelease = true;

            $nextSessionData = [
                'batch' => $batch,
                'dive_date' => $nextAssignment->dive_date,
                'students' => $assignedParticipants,
                'students_count' => $assignedParticipants->count(),
                'class_breakdown' => $classBreakdown,
                'weather_class' => $weatherClass,
                'weather_badge' => $weatherBadge,
                'model_comparison' => $this->forecastService->modelComparisonForBatch($batch),
                'hours_until_dive' => $hoursUntilDive,
                'can_request_release' => $canRequestRelease,
                'is_shared_pool' => false,
            ];
        } elseif (!$nextAssignment) {
            // Also check if the coach is in the batch team before the trip
            $nextBatch = Batch::whereDate('start_date', '>=', $today)
                ->whereIn('status', ['confirmed', 'open'])
                ->orderBy('start_date', 'asc')
                ->get()
                ->first(fn($b) => $b->assigned_coaches->pluck('id')->contains($coach->id));

            if ($nextBatch) {
                $batch = $nextBatch;
                $allBatchParticipants = $batch->bookings()
                    ->whereNotIn('status', ['cancelled_by_camp', 'cancelled_by_guest', 'cancelled', 'pending_downpayment'])
                    ->with('participants.booking')
                    ->get()
                    ->flatMap->participants
                    ->unique('id');

                $classBreakdown = [];
                foreach ($allBatchParticipants as $p) {
                    $type = $p->booking?->formatted_class_type ?? 'Freediving Class';
                    $classBreakdown[$type] = ($classBreakdown[$type] ?? 0) + 1;
                }

                $d1Assessment = $batch->latestDay1Assessment;
                $weatherClass = $d1Assessment ? $d1Assessment->overall_classification : 'Safe';
                $weatherBadge = $d1Assessment ? $d1Assessment->classification_badge : [
                    'label' => 'Safe',
                    'class' => 'bg-emerald-50 text-emerald-700',
                ];

                $diveStart = $batch->start_date->copy()->setTime(6, 30);
                $hoursUntilDive = max(0, Carbon::now('Asia/Manila')->diffInHours($diveStart, false));
                $canRequestRelease = $hoursUntilDive > 48;

                $nextSessionData = [
                    'batch' => $batch,
                    'dive_date' => $batch->start_date,
                    'students' => $allBatchParticipants,
                    'students_count' => $allBatchParticipants->count(),
                    'class_breakdown' => $classBreakdown,
                    'weather_class' => $weatherClass,
                    'weather_badge' => $weatherBadge,
                    'model_comparison' => $this->forecastService->modelComparisonForBatch($batch),
                    'hours_until_dive' => $hoursUntilDive,
                    'can_request_release' => $canRequestRelease,
                    'is_shared_pool' => true,
                ];
            }
        }

        // 2. Counts
        $availableDaysCount = CoachAvailability::where('coach_id', $coach->id)
            ->where('status', 'available')
            ->whereDate('date', '>=', $today)
            ->count();

        $pendingRequestsCount = CoachRequest::where('coach_id', $coach->id)
            ->where('status', 'pending')
            ->count();

        $upcomingConfirmedDivesCount = Batch::whereDate('start_date', '>=', $today)
            ->whereIn('status', ['confirmed', 'open'])
            ->get()
            ->filter(fn($b) => $b->assigned_coaches->pluck('id')->contains($coach->id))
            ->count();

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

        $activeOpeningsCount = CoachOpening::where('status', 'open')
            ->whereDate('dive_date', '>=', $today)
            ->whereNotIn('batch_id', $excludeBatchIds)
            ->count();

        $totalStudentsMentored = ParticipantAssignment::where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->distinct('participant_id')
            ->count('participant_id');

        // 3. Next 3 batches
        $upcomingAssignments = ParticipantAssignment::with(['batch.riskAssessments', 'participant', 'booking'])
            ->where('coach_id', $coach->id)
            ->where('status', 'assigned')
            ->whereDate('dive_date', '>=', $today)
            ->orderBy('dive_date', 'asc')
            ->get()
            ->groupBy('batch_id')
            ->take(3);

        // 4. Open slots (not counting batches already assigned/approved)
        $openCoachOpenings = CoachOpening::where('status', 'open')
            ->whereDate('dive_date', '>=', $today)
            ->whereNotIn('batch_id', $excludeBatchIds)
            ->with(['batch', 'requests' => fn($q) => $q->where('coach_id', $coach->id)])
            ->orderBy('dive_date', 'asc')
            ->take(3)
            ->get();

        // 5. Availability for the next 7 days
        $quickDays = [];
        $cursor = $today->copy();
        for ($i = 0; $i < 7; $i++) {
            $dateStr = $cursor->format('Y-m-d');
            $assignment = ParticipantAssignment::with('batch')
                ->where('coach_id', $coach->id)
                ->where('status', 'assigned')
                ->where(function ($q) use ($dateStr) {
                    $q->whereDate('dive_date', $dateStr)
                      ->orWhereHas('batch', function ($bq) use ($dateStr) {
                          $bq->whereDate('start_date', '<=', $dateStr)
                             ->whereDate('end_date', '>=', $dateStr);
                      });
                })
                ->first();

            $isAssigned = $assignment !== null;

            if (!$isAssigned) {
                $isAssigned = CoachAvailability::where('coach_id', $coach->id)
                    ->where('status', 'assigned')
                    ->whereDate('date', $dateStr)
                    ->exists();
            }

            $isAvailable = CoachAvailability::where('coach_id', $coach->id)
                ->where('status', 'available')
                ->whereDate('date', $dateStr)
                ->exists();

            $status = $isAssigned ? 'assigned' : ($isAvailable ? 'available' : 'not_set');

            $assignedDayNumber = 1;
            if ($isAssigned) {
                $batch = $assignment?->batch;
                if ($batch && $batch->start_date && $batch->end_date) {
                    if ($dateStr === $batch->end_date->format('Y-m-d') || $cursor->isSunday()) {
                        $assignedDayNumber = 2;
                    } else {
                        $assignedDayNumber = 1;
                    }
                } elseif ($cursor->isSunday()) {
                    $assignedDayNumber = 2;
                } else {
                    $assignedDayNumber = 1;
                }
            }

            $quickDays[] = [
                'date' => $cursor->copy(),
                'date_str' => $dateStr,
                'is_today' => $cursor->isToday(),
                'is_weekend' => $cursor->isWeekend(),
                'status' => $status,
                'assigned_day_number' => $assignedDayNumber,
            ];
            $cursor->addDay();
        }

        return view('coach.dashboard', compact(
            'coach',
            'nextSessionData',
            'availableDaysCount',
            'pendingRequestsCount',
            'upcomingConfirmedDivesCount',
            'activeOpeningsCount',
            'totalStudentsMentored',
            'upcomingAssignments',
            'openCoachOpenings',
            'quickDays'
        ));
    }
}
