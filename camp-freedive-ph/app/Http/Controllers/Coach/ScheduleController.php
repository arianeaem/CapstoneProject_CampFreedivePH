<?php

namespace App\Http\Controllers\Coach;

use App\Http\Controllers\Controller;
use App\Models\AssignmentReleaseRequest;
use App\Models\ParticipantAssignment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ScheduleController extends Controller
{
    /**
     * Page 3: my schedule (upcoming and past).
     */
    public function index(Request $request): View
    {
        $coach = Auth::user();
        $today = Carbon::today();
        $activeTab = $request->input('tab', ($request->filled('date_from') || $request->filled('date_to') || $request->filled('class_type')) ? 'history' : 'upcoming');

        // 1. Upcoming assignments
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

        // Group by batch
        $upcomingBatches = [];
        foreach ($upcomingRaw->groupBy('batch_id') as $batchId => $assignments) {
            $firstAssignment = $assignments->first();
            $batch = $firstAssignment->batch;
            if (!$batch) continue;

            $students = $assignments->pluck('participant')->unique('id');

            // Count per class
            $classCounts = [];
            foreach ($students as $s) {
                $cType = $s->booking?->formatted_class_type ?? 'Freediving';
                $classCounts[$cType] = ($classCounts[$cType] ?? 0) + 1;
            }

            // Weather
            // The worse of Day 1 and Day 2 (dive windows), same as the batch's overall rating
            $rank = fn ($a) => \App\Services\WeatherForecastService::RISK_RANK[$a?->overall_classification] ?? -1;
            $d1 = $rank($batch->latestDay2Assessment) > $rank($batch->latestDay1Assessment)
                ? $batch->latestDay2Assessment
                : $batch->latestDay1Assessment;
            $weatherClass = $d1 ? $d1->overall_classification : 'Safe';
            $weatherBadge = $d1 ? $d1->classification_badge : [
                'label' => 'Safe',
                'class' => 'bg-emerald-50 text-emerald-700',
            ];

            // Emergency release can be requested any time
            $diveStart = $batch->start_date->copy()->setTime(6, 30);
            $hoursUntilDive = Carbon::now()->diffInHours($diveStart, false);
            $canRequestRelease = true;

            // Was a release request already sent?
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
                'weather_peak' => $d1?->peak_label,
                'assessment' => $d1,
                'hours_until_dive' => max(0, $hoursUntilDive),
                'can_request_release' => $canRequestRelease,
                'release_request' => $releaseRequest,
            ];
        }

        // 2. Past dives
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

        // Filter by date range
        if ($request->filled('date_from')) {
            $historyQuery->whereDate('dive_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $historyQuery->whereDate('dive_date', '<=', $request->input('date_to'));
        }

        // Filter by class
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
