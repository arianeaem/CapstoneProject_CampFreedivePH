<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\AssignmentReleaseRequest;
use App\Models\Batch;
use App\Models\ParticipantAssignment;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    public function __construct(
        protected WeatherForecastService $forecastService
    ) {}

    /**
     * Page 3: My Assigned Schedule (Upcoming & History).
     */
    public function index(Request $request): View
    {
        $coach = Auth::user();
        $today = Carbon::today();
        $activeTab = $request->input('tab', ($request->filled('date_from') || $request->filled('date_to') || $request->filled('class_type')) ? 'history' : 'upcoming');

        // 1. UPCOMING ASSIGNMENTS (Future Confirmed Batches)
        $upcomingAssignmentsQuery = ParticipantAssignment::with([
            'batch.riskAssessments',
            'batch.latestManualOverride',
            'participant',
            'booking',
        ])
        ->where('coach_id', $coach->id)
        ->where('status', 'assigned')
        ->whereDate('dive_date', '>=', $today)
        ->orderBy('dive_date', 'asc');

        $upcomingRaw = $upcomingAssignmentsQuery->get();

        // Group by batch_id
        $upcomingBatches = [];
        foreach ($upcomingRaw->groupBy('batch_id') as $batchId => $assignments) {
            $firstAssignment = $assignments->first();
            $batch = $firstAssignment->batch;
            if (!$batch) continue;

            $students = $assignments->pluck('participant')->unique('id');

            // Class type breakdown
            $classCounts = [];
            foreach ($students as $s) {
                $cType = $s->booking?->formatted_class_type ?? 'Freediving';
                $classCounts[$cType] = ($classCounts[$cType] ?? 0) + 1;
            }

            // Weather classification
            $d1 = $batch->latestDay1Assessment;
            $weatherClass = $d1 ? $d1->overall_classification : 'Safe';
            $weatherBadge = $d1 ? $d1->classification_badge : [
                'label' => 'Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
            ];

            // Emergency release requests are available regardless of lead time.
            $diveStart = $batch->start_date->copy()->setTime(6, 30);
            $hoursUntilDive = Carbon::now()->diffInHours($diveStart, false);
            $canRequestRelease = true;

            // Check if release request already submitted
            $releaseRequest = AssignmentReleaseRequest::where('coach_id', $coach->id)
                ->where('batch_id', $batch->id)
                ->first();

            $upcomingBatches[] = [
                'batch' => $batch,
                'dive_date' => $firstAssignment->dive_date,
                'students' => $students,
                'students_count' => $students->count(),
                'is_current_dive' => $firstAssignment->dive_date?->isSameDay($today) ?? false,
                'class_counts' => $classCounts,
                'weather_class' => $weatherClass,
                'weather_badge' => $weatherBadge,
                'assessment' => $d1,
                'hours_until_dive' => max(0, $hoursUntilDive),
                'can_request_release' => $canRequestRelease,
                'release_request' => $releaseRequest,
            ];
        }

        // 2. PAST ASSIGNMENT HISTORY (Archival completed dives)
        $historyQuery = ParticipantAssignment::with([
            'batch',
            'participant',
            'booking',
        ])
        ->where('coach_id', $coach->id)
        ->where(function ($q) use ($today) {
            $q->whereDate('dive_date', '<', $today)
              ->orWhere('status', 'completed');
        });

        // Filter: Date Range
        if ($request->filled('date_from')) {
            $historyQuery->whereDate('dive_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $historyQuery->whereDate('dive_date', '<=', $request->input('date_to'));
        }

        // Filter: Class Type
        if ($request->filled('class_type')) {
            $historyQuery->whereHas('booking', function ($bq) use ($request) {
                $bq->where('class_type', $request->input('class_type'));
            });
        }

        $historyRaw = $historyQuery->orderBy('dive_date', 'desc')->get();

        $historyBatches = [];
        $totalPastStudentsCount = 0;

        foreach ($historyRaw->groupBy('batch_id') as $batchId => $assignments) {
            $firstAssignment = $assignments->first();
            $batch = $firstAssignment->batch;
            if (!$batch) continue;

            $students = $assignments->pluck('participant')->unique('id');
            $totalPastStudentsCount += $students->count();

            $classCounts = [];
            foreach ($students as $s) {
                $cType = $s->booking?->formatted_class_type ?? 'Freediving';
                $classCounts[$cType] = ($classCounts[$cType] ?? 0) + 1;
            }

            $historyBatches[] = [
                'batch' => $batch,
                'dive_date' => $firstAssignment->dive_date,
                'students' => $students,
                'students_count' => $students->count(),
                'class_counts' => $classCounts,
            ];
        }

        $totalCompletedBatchesCount = count($historyBatches);

        return view('coach.schedule.index', compact(
            'coach',
            'activeTab',
            'upcomingBatches',
            'historyBatches',
            'totalPastStudentsCount',
            'totalCompletedBatchesCount'
        ));
    }
}
