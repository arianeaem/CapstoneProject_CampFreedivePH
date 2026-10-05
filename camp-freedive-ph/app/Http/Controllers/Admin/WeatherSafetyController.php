<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\WeatherForecastService;
use App\Services\WeatherSafetyMLService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * Administrative Weather & Marine Safety Operations Controller.
 *
 * Operational Responsibilities:
 * 1. Batch Monitoring Roster (Page 1): Tracks all active freediving batches within the 16-day forecast window.
 * 2. Deep-Dive Weather Dashboard (Page 2): Renders 24-hour continuous physical profiles and dual-engine
 *    comparisons (Native 9-variable heuristic vs Python ML ONNX models).
 * 3. Administrative Manual Overrides: Allows authorized operators to enforce storm signals (TCWS 1-5),
 *    gale warnings, or local squall alerts, escalating batches to Critical Risk.
 * 4. Automated Cancellation & Refund Trigger: Integrates one-click batch cancellation, triggering 100% force
 *    majeure refund entitlements and background-queued customer cancellation emails.
 */
class WeatherSafetyController extends Controller
{
    public function __construct(
        protected WeatherForecastService $forecastService,
        protected WeatherSafetyMLService $mlService
    ) {}

    /**
     * Page 1: Weather & Safety Monitoring Batch Roster.
     *
     * @param Request $request Contains filters for risk classification, status, and date range.
     * @return View Renders the administrative batch safety roster.
     */
    public function index(Request $request): View
    {
        $query = Batch::with([
            'bookings',
            'riskAssessments' => fn($q) => $q->orderBy('assessed_at', 'desc'),
            'manualOverrides',
        ]);

        // Filter out batches that do not yet have forecast data (beyond 16-day model horizon and no recorded assessment)
        $maxForecastHorizon = Carbon::today(WeatherForecastService::TIMEZONE)->addDays(WeatherForecastService::MAX_FORECAST_DAYS);
        $query->where(function ($q) use ($maxForecastHorizon) {
            $q->whereDate('start_date', '<=', $maxForecastHorizon)
              ->orWhereHas('riskAssessments', function ($sub) {
                  $sub->whereNotIn('overall_classification', ['Not Available']);
              });
        });

        // Filter: Risk Classification
        if ($request->filled('risk')) {
            $query->where('risk_classification', $request->input('risk'));
        }

        // Filter: Status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter: Date Range
        if ($request->filled('date_from')) {
            $query->whereDate('start_date', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('start_date', '<=', $request->input('date_to'));
        }

        // Critical and High Risk count (only for active/upcoming batches, excluding completed, cancelled, or past batches)
        $criticalCount = (clone $query)
            ->whereIn('risk_classification', ['high_risk', 'critical_risk'])
            ->whereNotIn('status', ['completed', 'cancelled', 'cancelled_by_camp'])
            ->whereDate('end_date', '>=', Carbon::today(WeatherForecastService::TIMEZONE))
            ->count();

        // Sort safety monitoring to latest first
        $perPage = max(4, min(100, (int) $request->input('per_page', 12)));
        $batches = $query->orderBy('start_date', 'desc')->orderBy('id', 'desc')->paginate($perPage)->withQueryString();

        // 24-Hour Master Continuous Cache Info
        $lastUpdatedAt = Cache::get('forecast:last_updated_at');
        $masterForecast = Cache::get('forecast:continuous_16d');
        if (!$masterForecast) {
            try {
                $masterForecast = $this->forecastService->updateAllForecasts(16);
                $lastUpdatedAt = Cache::get('forecast:last_updated_at');
            } catch (\Throwable $e) {
                // Ignore API failure
            }
        }

        // ML Microservice & Circuit Breaker Status
        $mlSafetyUrl = config('services.ml_safety.url', 'http://127.0.0.1:8001');
        $circuitStatus = $this->mlService->getCircuitStatus();
        $isMLReachable = false;
        try {
            $res = Http::timeout(1)->get("{$mlSafetyUrl}/health");
            $isMLReachable = $res->successful();
        } catch (\Throwable $e) {
            $isMLReachable = false;
        }

        // Prepare batch ML and risk assessments
        $batchMLAssessments = [];
        foreach ($batches as $b) {
            $d1Date = $b->start_date->format('Y-m-d');
            $d2Date = $b->end_date ? $b->end_date->format('Y-m-d') : $b->start_date->copy()->addDay()->format('Y-m-d');
            $horizonInfo = WeatherForecastService::getOperationalHorizon($b);
            $isConcluded = ($horizonInfo['status'] === 'CONCLUDED') || ($b->end_date && $b->end_date->isPast()) || in_array($b->status, ['completed', 'cancelled_by_camp']);

            $d1ML = ($isMLReachable && $circuitStatus['is_available'] && !$isConcluded) 
                ? $this->forecastService->assessMLSafetyForDate($d1Date, '08:00', '18:00') 
                : null;
            $d2ML = ($isMLReachable && $circuitStatus['is_available'] && !$isConcluded) 
                ? $this->forecastService->assessMLSafetyForDate($d2Date, '08:00', '18:00') 
                : null;

            if ($d1ML || $d2ML) {
                $rec1 = $d1ML['overall_recommendation'] ?? 'Safe';
                $rec2 = $d2ML['overall_recommendation'] ?? 'Safe';
                $wRank = max(WeatherForecastService::RISK_RANK[$rec1] ?? 1, WeatherForecastService::RISK_RANK[$rec2] ?? 1);
                $wRec = array_search($wRank, WeatherForecastService::RISK_RANK) ?: 'Safe';
                $daysOut = $horizonInfo['days_out'] ?? max(0, Carbon::now(WeatherForecastService::TIMEZONE)->startOfDay()->diffInDays($b->start_date->copy()->startOfDay(), false));
                $leadTimeHours = $horizonInfo['lead_time_hours'] ?? max(1, Carbon::now(WeatherForecastService::TIMEZONE)->diffInHours($b->start_date->copy()->setTime(9, 30), false));
                $routedBucket = $d1ML['routed_horizon_bucket'] ?? WeatherSafetyMLService::snapToClosestHorizon((int) $leadTimeHours);
                $isBeyond7d = $daysOut > 7 || ($leadTimeHours > 168);

                $d1Conf = ($isBeyond7d || ($d1ML['confidence'] ?? 'high') === 'low') ? 'low' : 'high';
                $d2Conf = (($daysOut + 1) > 7 || ($d2ML['confidence'] ?? 'high') === 'low') ? 'low' : 'high';
                $batchConfidence = ($d1Conf === 'low' || $d2Conf === 'low' || $isBeyond7d) ? 'low' : 'high';

                $confidenceTier = match(true) {
                    $isBeyond7d => 'LOW_CONFIDENCE_CLIMATOLOGY_BOUND',
                    $batchConfidence === 'low' => 'LOW_CONFIDENCE_ML_UNCERTAIN',
                    $daysOut > 3 => 'MEDIUM_CONFIDENCE_MULTI_HORIZON',
                    $daysOut > 1 => 'MODERATE_HIGH_CONFIDENCE',
                    default => 'HIGH_CONFIDENCE',
                };

                $servingSource = match(true) {
                    $isBeyond7d => 'Batangas Seasonal Climatology (> 168h)',
                    default => "Multi-Horizon ML Regressors (H = {$routedBucket}h)",
                };

                $opStatus = ($horizonInfo['status'] === 'CONCLUDED') ? 'CONCLUDED' : ($d1ML['operational_status'] ?? $d2ML['operational_status'] ?? $horizonInfo['status']);
                $opLabel = ($horizonInfo['status'] === 'CONCLUDED') ? ($horizonInfo['label'] ?? 'Concluded Session') : ($d1ML['operational_status_label'] ?? $d2ML['operational_status_label'] ?? $horizonInfo['label']);

                $batchMLAssessments[$b->id] = [
                    'batch' => $b,
                    'overall_recommendation' => $wRec,
                    'operational_status' => $opStatus,
                    'operational_status_label' => $opLabel,
                    'confidence' => $batchConfidence,
                    'confidence_tier' => $confidenceTier,
                    'serving_source' => $servingSource,
                    'confidence_advisory' => ($batchConfidence === 'low') ? "{$wRec}. Lead time is beyond the 7-day multi-horizon ML boundary (168h)." : null,
                    'day1' => $d1ML,
                    'day2' => $d2ML,
                    'lead_time_hours' => $leadTimeHours,
                    'routed_horizon_bucket' => $routedBucket,
                    'is_beyond_7d' => $isBeyond7d,
                    'safety_threshold_triggered' => ($d1ML['safety_threshold_triggered'] ?? $d1ML['hard_gate_triggered'] ?? false) || ($d2ML['safety_threshold_triggered'] ?? $d2ML['hard_gate_triggered'] ?? false),
                    'hard_gate_triggered' => ($d1ML['safety_threshold_triggered'] ?? $d1ML['hard_gate_triggered'] ?? false) || ($d2ML['safety_threshold_triggered'] ?? $d2ML['hard_gate_triggered'] ?? false),
                ];
            } else {
                // Concluded or physics fallback assessment from recorded DB data
                $existingD1 = $b->riskAssessments->where('day_number', 1)->first() ?? $b->riskAssessments->filter(fn($a) => $a->dive_date?->toDateString() === $b->start_date?->toDateString())->first();
                $existingD2 = $b->riskAssessments->where('day_number', 2)->first() ?? $b->riskAssessments->filter(fn($a) => $a->dive_date?->toDateString() === $b->end_date?->toDateString())->first();
                
                $overallRec = $b->risk_classification ? ucfirst(str_replace('_', ' ', $b->risk_classification)) : ($existingD1?->overall_classification ?? 'Safe');
                if ($existingD1 && $existingD2) {
                    $r1 = WeatherForecastService::RISK_RANK[$existingD1->overall_classification] ?? 1;
                    $r2 = WeatherForecastService::RISK_RANK[$existingD2->overall_classification] ?? 1;
                    $overallRec = array_search(max($r1, $r2), WeatherForecastService::RISK_RANK) ?: $overallRec;
                }

                $batchMLAssessments[$b->id] = [
                    'batch' => $b,
                    'overall_recommendation' => $overallRec,
                    'operational_status' => $isConcluded ? 'CONCLUDED' : ($horizonInfo['status'] ?? 'EXTENDED_TREND_OUTLOOK'),
                    'operational_status_label' => $isConcluded ? 'Concluded Session' : ($horizonInfo['label'] ?? 'Operational Monitoring'),
                    'confidence' => 'high',
                    'confidence_tier' => $isConcluded ? 'ARCHIVED_RECORD' : 'PHYSICS_BACKUP',
                    'serving_source' => $isConcluded ? 'Archived Operational Telemetry' : 'Open-Meteo Marine Physics',
                    'confidence_advisory' => null,
                    'day1' => $existingD1 ? [
                        'overall_recommendation' => $existingD1->overall_classification,
                        'worst_hour' => $existingD1->worst_hour ? $existingD1->worst_hour->format('g:i A') : 'N/A',
                        'worst_window' => $existingD1->worst_window ?? 'N/A',
                    ] : null,
                    'day2' => $existingD2 ? [
                        'overall_recommendation' => $existingD2->overall_classification,
                        'worst_hour' => $existingD2->worst_hour ? $existingD2->worst_hour->format('g:i A') : 'N/A',
                        'worst_window' => $existingD2->worst_window ?? 'N/A',
                    ] : null,
                    'safety_threshold_triggered' => false,
                    'hard_gate_triggered' => false,
                ];
            }
        }

        return view('admin.weather.index', compact(
            'batches',
            'criticalCount',
            'lastUpdatedAt',
            'masterForecast',
            'isMLReachable',
            'circuitStatus',
            'mlSafetyUrl',
            'batchMLAssessments'
        ));
    }

    /**
     * One-Click Operator Sync 16-Day 24-Hour Forecast Cache.
     */
    public function syncCache(): RedirectResponse
    {
        try {
            $result = $this->forecastService->updateAllForecasts(16);

            // Auto-sync all active batches so risk assessments and audit trails match latest telemetry
            $today = Carbon::today(WeatherForecastService::TIMEZONE);
            $activeBatches = Batch::whereNotIn('status', ['completed', 'cancelled', 'cancelled_by_camp'])
                ->where('end_date', '>=', $today->toDateString())
                ->get();
            foreach ($activeBatches as $b) {
                $this->forecastService->assessBatch($b, null, auth()->user());
            }

            return back()->with('success', "Successfully synced 24-hour continuous weather & marine forecast cache and updated assessments for {$activeBatches->count()} active batch(es).");
        } catch (Exception $e) {
            return back()->with('error', "Forecast cache sync failed: " . $e->getMessage());
        }
    }


    /**
     * Page 2: Deep-Dive Batch Weather Dashboard.
     */
    public function show(Batch $batch): View
    {
        $batch->load([
            'bookings.participants',
            'riskAssessments.hourlyAssessments',
            'manualOverrides.operator',
            'notificationLogs',
        ]);

        $day1Assessment = $batch->latestDay1Assessment;
        $day2Assessment = $batch->latestDay2Assessment;
        $latestOverride = $batch->latestManualOverride;

        $horizonInfo = WeatherForecastService::getOperationalHorizon($batch);
        $isConcluded = ($horizonInfo['status'] === 'CONCLUDED') || ($batch->end_date && $batch->end_date->isPast()) || in_array($batch->status, ['completed', 'cancelled_by_camp']);

        // Auto-run assessment if not yet assessed or if active batch is behind latest forecast cache update
        $lastForecastUpdate = Cache::get('forecast:last_updated_at');
        $needsSync = !$day1Assessment || !$day2Assessment;

        if (!$needsSync && !$isConcluded && $lastForecastUpdate) {
            $lastAssessed = $day1Assessment->assessed_at ?? $day1Assessment->created_at;
            if ($lastAssessed && Carbon::parse($lastForecastUpdate)->greaterThan($lastAssessed)) {
                $needsSync = true;
            }
        }

        if ($needsSync) {
            $this->forecastService->assessBatch($batch, null, auth()->user());
            $batch->refresh();
            $day1Assessment = $batch->latestDay1Assessment;
            $day2Assessment = $batch->latestDay2Assessment;
        }

        // Overall classification: worse of Day 1 and Day 2
        $overallClassification = 'Safe';
        if ($day1Assessment && $day2Assessment) {
            $rank1 = WeatherForecastService::RISK_RANK[$day1Assessment->overall_classification] ?? 1;
            $rank2 = WeatherForecastService::RISK_RANK[$day2Assessment->overall_classification] ?? 1;
            $worseRank = max($rank1, $rank2);
            $overallClassification = array_search($worseRank, WeatherForecastService::RISK_RANK) ?: 'Safe';
        }

        // Group past assessment runs (pair Day 1 and Day 2 assessments from the same assessment run)
        $allAssessments = $batch->riskAssessments()
            ->with(['assessor'])
            ->orderBy('id', 'desc')
            ->get();

        $runs = [];
        $visited = [];
        foreach ($allAssessments as $item) {
            if (isset($visited[$item->id])) {
                continue;
            }

            $runGroup = collect([$item]);
            $visited[$item->id] = true;

            // Look for paired assessment for the opposite day number from the same run (within 3 minutes)
            $otherDay = ((int) $item->day_number === 1) ? 2 : 1;
            $pair = $allAssessments->first(function ($candidate) use ($item, $otherDay, $visited) {
                if (isset($visited[$candidate->id])) {
                    return false;
                }
                if ((int) $candidate->day_number !== $otherDay) {
                    return false;
                }
                if (!$item->assessed_at || !$candidate->assessed_at) {
                    return false;
                }
                return abs($item->assessed_at->diffInSeconds($candidate->assessed_at)) <= 180;
            });

            if ($pair) {
                $runGroup->push($pair);
                $visited[$pair->id] = true;
            }

            $timestampKey = $item->assessed_at ? $item->assessed_at->format('Y-m-d H:i:s') : 'run_' . $item->id;
            $runs[$timestampKey] = $runGroup;
        }

        $assessmentRuns = collect($runs);

        // Fetch 24-Hour Continuous Profiles for Day 1 and Day 2
        $day1Date = $batch->start_date->format('Y-m-d');
        $day2Date = $batch->end_date ? $batch->end_date->format('Y-m-d') : $batch->start_date->copy()->addDay()->format('Y-m-d');
        $day1Continuous24h = Cache::get("forecast:date:{$day1Date}");
        $day2Continuous24h = Cache::get("forecast:date:{$day2Date}");

        // Auto-refresh continuous 16-day cache on demand if missing or expired (e.g. IDE/server restarted)
        if ((!$day1Continuous24h || empty($day1Continuous24h['hourly'])) && Carbon::now()->diffInDays($batch->start_date, false) <= 16) {
            try {
                $master = $this->forecastService->updateAllForecasts(16);
                $day1Continuous24h = $master['daily_summaries'][$day1Date] ?? Cache::get("forecast:date:{$day1Date}");
                $day2Continuous24h = $master['daily_summaries'][$day2Date] ?? Cache::get("forecast:date:{$day2Date}");
            } catch (\Throwable $e) {
                // If Open-Meteo API is temporarily unreachable, gracefully fallback to DB
            }
        }

        // Resilient Fallback: If 24h continuous cache is still empty, populate from persisted DB hourly assessments
        if (empty($day1Continuous24h['hourly']) && $day1Assessment && $day1Assessment->hourlyAssessments->isNotEmpty()) {
            $day1Continuous24h = [
                'date' => $day1Date,
                'hourly' => $day1Assessment->hourlyAssessments->map(fn($h) => [
                    'hour' => (int) $h->forecast_time->format('H'),
                    'time' => $h->forecast_time->format('H:i'),
                    'classification' => $h->classification,
                    'wave_height' => (float) $h->wave_height,
                    'wave_period' => (float) $h->wave_period,
                    'swell_height' => (float) $h->swell_height,
                    'ocean_current' => (float) $h->ocean_current,
                    'wind_wave_height' => (float) $h->wind_wave_height,
                    'rain' => (float) $h->rain,
                    'sea_level_pressure' => (float) $h->sea_level_pressure,
                    'wind_speed' => (float) $h->wind_speed,
                    'wind_direction' => (float) $h->wind_direction,
                ])->toArray(),
            ];
        }

        if (empty($day2Continuous24h['hourly']) && $day2Assessment && $day2Assessment->hourlyAssessments->isNotEmpty()) {
            $day2Continuous24h = [
                'date' => $day2Date,
                'hourly' => $day2Assessment->hourlyAssessments->map(fn($h) => [
                    'hour' => (int) $h->forecast_time->format('H'),
                    'time' => $h->forecast_time->format('H:i'),
                    'classification' => $h->classification,
                    'wave_height' => (float) $h->wave_height,
                    'wave_period' => (float) $h->wave_period,
                    'swell_height' => (float) $h->swell_height,
                    'ocean_current' => (float) $h->ocean_current,
                    'wind_wave_height' => (float) $h->wind_wave_height,
                    'rain' => (float) $h->rain,
                    'sea_level_pressure' => (float) $h->sea_level_pressure,
                    'wind_speed' => (float) $h->wind_speed,
                    'wind_direction' => (float) $h->wind_direction,
                ])->toArray(),
            ];
        }

        // ML Safety Assessments (Dual-Engine microservice pipeline)
        $overridesData = $latestOverride ? [
            'tcws_signal' => $latestOverride->tcws_signal,
            'gale_warning' => $latestOverride->gale_warning,
            'tsunami_warning' => $latestOverride->tsunami_warning,
        ] : null;

        $day1MLAssessment = $this->forecastService->assessMLSafetyForDate($day1Date, '00:00', '23:00', $overridesData);
        $day2MLAssessment = $this->forecastService->assessMLSafetyForDate($day2Date, '00:00', '23:00', $overridesData);

        $batchMLAssessment = null;
        if ($day1MLAssessment || $day2MLAssessment) {
            $mlRec1 = $day1MLAssessment['overall_recommendation'] ?? 'Safe';
            $mlRec2 = $day2MLAssessment['overall_recommendation'] ?? 'Safe';
            $worseMLRank = max(WeatherForecastService::RISK_RANK[$mlRec1] ?? 1, WeatherForecastService::RISK_RANK[$mlRec2] ?? 1);
            $worseMLRec = array_search($worseMLRank, WeatherForecastService::RISK_RANK) ?: 'Safe';

            $horizonInfo = WeatherForecastService::getOperationalHorizon($batch);
            $daysOut = $horizonInfo['days_out'] ?? max(0, Carbon::now(WeatherForecastService::TIMEZONE)->startOfDay()->diffInDays($batch->start_date->copy()->startOfDay(), false));
            $leadTimeHours = $horizonInfo['lead_time_hours'] ?? max(1, Carbon::now(WeatherForecastService::TIMEZONE)->diffInHours($batch->start_date->copy()->setTime(9, 30), false));
            $d1RoutedBucket = $day1MLAssessment['routed_horizon_bucket'] ?? WeatherSafetyMLService::snapToClosestHorizon((int) $leadTimeHours);
            $d2LeadTimeHours = max(1, $leadTimeHours + 24);
            $d2RoutedBucket = $day2MLAssessment['routed_horizon_bucket'] ?? WeatherSafetyMLService::snapToClosestHorizon((int) $d2LeadTimeHours);
            $isBeyond7d = $daysOut > 7 || ($leadTimeHours > 168);

            $d1Conf = ($isBeyond7d || ($day1MLAssessment['confidence'] ?? 'high') === 'low') ? 'low' : 'high';
            $d2Conf = (($daysOut + 1) > 7 || ($day2MLAssessment['confidence'] ?? 'high') === 'low') ? 'low' : 'high';
            $batchConfidence = ($d1Conf === 'low' || $d2Conf === 'low' || $isBeyond7d) ? 'low' : 'high';

            $confidenceTier = match(true) {
                $isBeyond7d => 'LOW_CONFIDENCE_CLIMATOLOGY_BOUND',
                $batchConfidence === 'low' => 'LOW_CONFIDENCE_ML_UNCERTAIN',
                $daysOut > 3 => 'MEDIUM_CONFIDENCE_MULTI_HORIZON',
                $daysOut > 1 => 'MODERATE_HIGH_CONFIDENCE',
                default => 'HIGH_CONFIDENCE',
            };

            $servingSource = match(true) {
                $isBeyond7d => 'Batangas Seasonal Climatology (> 168h)',
                default => "Multi-Horizon ML Regressors (H = {$d1RoutedBucket}h)",
            };

            $opStatus = ($horizonInfo['status'] === 'CONCLUDED') ? 'CONCLUDED' : ($day1MLAssessment['operational_status'] ?? $day2MLAssessment['operational_status'] ?? $horizonInfo['status']);
            $opLabel = ($horizonInfo['status'] === 'CONCLUDED') ? ($horizonInfo['label'] ?? 'Concluded Session') : ($day1MLAssessment['operational_status_label'] ?? $day2MLAssessment['operational_status_label'] ?? $horizonInfo['label']);

            $batchMLAssessment = [
                'overall_recommendation' => $worseMLRec,
                'ml_recommendation' => $worseMLRec,
                'ml_classification' => $worseMLRec,
                'operational_status' => $opStatus,
                'operational_status_label' => $opLabel,
                'confidence' => $batchConfidence,
                'confidence_tier' => $confidenceTier,
                'serving_source' => $servingSource,
                'confidence_advisory' => ($batchConfidence === 'low') ? "{$worseMLRec}. Lead time is beyond the 7-day multi-horizon ML boundary (168h)." : null,
                'day1' => $day1MLAssessment,
                'day2' => $day2MLAssessment,
                'lead_time_hours' => $leadTimeHours,
                'day1_routed_bucket' => $d1RoutedBucket,
                'day2_routed_bucket' => $d2RoutedBucket,
                'is_beyond_7d' => $isBeyond7d,
                'is_authoritative_go' => ($day1MLAssessment['is_authoritative_go'] ?? false) && ($day2MLAssessment['is_authoritative_go'] ?? false),
                'safety_threshold_triggered' => ($day1MLAssessment['safety_threshold_triggered'] ?? $day1MLAssessment['hard_gate_triggered'] ?? false) || ($day2MLAssessment['safety_threshold_triggered'] ?? $day2MLAssessment['hard_gate_triggered'] ?? false),
                'hard_gate_triggered' => ($day1MLAssessment['safety_threshold_triggered'] ?? $day1MLAssessment['hard_gate_triggered'] ?? false) || ($day2MLAssessment['safety_threshold_triggered'] ?? $day2MLAssessment['hard_gate_triggered'] ?? false),
            ];
        } else {
            $isConcluded = ($batch->end_date && $batch->end_date->isPast()) || in_array($batch->status, ['completed', 'cancelled_by_camp']);
            $batchMLAssessment = [
                'overall_recommendation' => $overallClassification,
                'ml_recommendation' => $overallClassification,
                'ml_classification' => $overallClassification,
                'operational_status' => $isConcluded ? 'CONCLUDED' : 'PHYSICS_FALLBACK',
                'operational_status_label' => $isConcluded ? 'Concluded Session' : 'Operational Monitoring',
                'confidence' => 'high',
                'confidence_tier' => $isConcluded ? 'ARCHIVED_RECORD' : 'PHYSICS_BACKUP',
                'serving_source' => $isConcluded ? 'Archived Operational Telemetry' : 'Open-Meteo Marine Physics Backup',
                'confidence_advisory' => null,
                'day1' => $day1Assessment ? [
                    'overall_recommendation' => $day1Assessment->overall_classification,
                    'worst_hour' => $day1Assessment->worst_hour ? $day1Assessment->worst_hour->format('g:i A') : 'N/A',
                    'worst_window' => $day1Assessment->worst_window ?? 'N/A',
                    'hourly_assessments' => $day1Continuous24h['hourly'] ?? [],
                ] : null,
                'day2' => $day2Assessment ? [
                    'overall_recommendation' => $day2Assessment->overall_classification,
                    'worst_hour' => $day2Assessment->worst_hour ? $day2Assessment->worst_hour->format('g:i A') : 'N/A',
                    'worst_window' => $day2Assessment->worst_window ?? 'N/A',
                    'hourly_assessments' => $day2Continuous24h['hourly'] ?? [],
                ] : null,
                'is_authoritative_go' => true,
                'safety_threshold_triggered' => false,
                'hard_gate_triggered' => false,
            ];
        }

        // Side-by-side model comparison (same engines as the booking page's Dive Safety Evaluation)
        $modelComparison = null;
        if (!$isConcluded) {
            try {
                $modelComparison = $this->forecastService->previewDateAssessment($batch->start_date->copy())['engines'] ?? null;
            } catch (\Throwable $e) {
                $modelComparison = null;
            }
        }

        $mlSafetyUrl = config('services.ml_safety.url', 'http://127.0.0.1:8001');
        $circuitStatus = $this->mlService->getCircuitStatus();
        $isMLReachable = false;
        try {
            $res = Http::timeout(1)->get("{$mlSafetyUrl}/health");
            $isMLReachable = $res->successful();
        } catch (\Throwable $e) {
            $isMLReachable = false;
        }

        return view('admin.weather.show', compact(
            'batch',
            'day1Assessment',
            'day2Assessment',
            'latestOverride',
            'overallClassification',
            'assessmentRuns',
            'day1Continuous24h',
            'day2Continuous24h',
            'day1MLAssessment',
            'day2MLAssessment',
            'batchMLAssessment',
            'circuitStatus',
            'isMLReachable',
            'modelComparison'
        ));
    }

    /**
     * One-Click Operator Run Assessment.
     */
    public function assess(Batch $batch): RedirectResponse
    {
        try {
            $this->forecastService->assessBatch($batch, null, auth()->user());
            return back()->with('success', "Weather risk assessed for batch {$batch->batch_code}.");
        } catch (Exception $e) {
            return back()->with('error', "Assessment failed: " . $e->getMessage());
        }
    }

    /**
     * Submit Manual Override (PAGASA-style advisories).
     */
    public function override(Request $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validate([
            'tcws_signal' => 'nullable|integer|min:0|max:5',
            'gale_warning' => 'nullable|boolean',
            'thunderstorm_advisory' => 'nullable|boolean',
            'typhoon_within_distance' => 'nullable|boolean',
            'tsunami_warning' => 'nullable|boolean',
            'reason' => 'required|string|max:1000',
            'cancel_batch' => 'nullable|boolean',
        ]);

        try {
            $cancelBatch = $request->boolean('cancel_batch');
            $this->forecastService->applyManualOverride(
                $batch,
                $validated,
                auth()->user(),
                $cancelBatch,
                $validated['reason']
            );

            $msg = "Manual override applied to batch {$batch->batch_code}. Both days forced to Critical Risk.";
            if ($cancelBatch) {
                $msg .= " Batch cancelled, 100% force majeure refund eligibility triggered, and customer cancellation notifications dispatched.";
            }

            return back()->with('success', $msg);
        } catch (Exception $e) {
            return back()->with('error', "Override failed: " . $e->getMessage());
        }
    }

    /**
     * Confirm Whole-Batch Cancellation from Risk Assessment or Override.
     */
    public function cancel(Request $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:1000',
        ]);

        try {
            $sentCount = $this->forecastService->cancelBatchWithRefundsAndNotifications(
                $batch,
                $validated['cancellation_reason'],
                auth()->user()
            );

            return back()->with('success', "Batch {$batch->batch_code} cancelled. {$sentCount} cancellation notification email(s) dispatched to affected bookings.");
        } catch (Exception $e) {
            return back()->with('error', "Cancellation failed: " . $e->getMessage());
        }
    }
}
