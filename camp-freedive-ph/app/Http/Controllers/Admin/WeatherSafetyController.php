<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Weather\BatchSafetyReportService;
use App\Services\WeatherForecastService;
use App\ViewModels\BatchSafetyViewModel;
use App\Services\WeatherSafetyMLService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use App\Http\Requests\Admin\Weather\ApplyOverrideRequest;
use App\Http\Requests\Admin\Weather\CancelBatchRequest;

/**
 * Admin pages for weather and sea safety.
 *
 * - page 1: list of batches in the next 16 days and their risk
 * - page 2: hourly weather for one batch, rule-based vs ML model
 * - admin overrides (storm signal, gale warning, squall) that make a batch Critical Risk
 * - cancel a whole batch (full refund, emails go out through the queue)
 */
class WeatherSafetyController extends Controller
{
    /** Non-weather reasons the admin can pick for an override. */
    public const OTHER_HAZARDS = [
        'oil_spill' => 'Oil spill',
        'red_tide' => 'Red tide / harmful algal bloom',
        'jellyfish' => 'Jellyfish bloom',
        'no_sail_order' => 'Coast Guard / local government no-sail order',
        'pollution' => 'Water pollution / contamination',
        'marine_accident' => 'Marine accident nearby',
        'other' => 'Other (describe below)',
    ];

    public function __construct(
        protected WeatherForecastService $forecastService,
        protected WeatherSafetyMLService $mlService
    ) {}

    /**
     * Page 1: batch list with weather risk.
     *
     * @param Request $request filters: risk, status, date range
     * @return View
     */
    public function index(Request $request): View
    {
        $query = Batch::with([
            'bookings',
            'riskAssessments' => fn($q) => $q->orderBy('assessed_at', 'desc'),
            'manualOverrides',
        ]);

        // Hide batches with no forecast yet (more than 16 days away and never assessed)
        $maxForecastHorizon = Carbon::today(WeatherForecastService::TIMEZONE)->addDays(WeatherForecastService::MAX_FORECAST_DAYS);
        $query->where(function ($q) use ($maxForecastHorizon) {
            $q->whereDate('start_date', '<=', $maxForecastHorizon)
              ->orWhereHas('riskAssessments', function ($sub) {
                  $sub->whereNotIn('overall_classification', ['Not Available']);
              });
        });

        // Filter by risk
        if ($request->filled('risk')) {
            $query->where('risk_classification', $request->input('risk'));
        }

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

        // Count Critical and High Risk (upcoming batches only)
        $criticalCount = (clone $query)
            ->whereIn('risk_classification', ['high_risk', 'critical_risk'])
            ->whereNotIn('status', ['completed', 'cancelled', 'cancelled_by_camp'])
            ->whereDate('end_date', '>=', Carbon::today(WeatherForecastService::TIMEZONE))
            ->count();

        // Newest first
        $perPage = max(4, min(100, (int) $request->input('per_page', 12)));
        $batches = $query->orderBy('start_date', 'desc')->orderBy('id', 'desc')->paginate($perPage)->withQueryString();

        // Forecast cache info
        $lastUpdatedAt = Cache::get('forecast:last_updated_at');
        $masterForecast = Cache::get('forecast:continuous_16d');
        if (!$masterForecast) {
            try {
                $masterForecast = $this->forecastService->updateAllForecasts(16);
                $lastUpdatedAt = Cache::get('forecast:last_updated_at');
            } catch (\Throwable $e) {
                // Ignore API errors
            }
        }

        // ML service and circuit breaker status
        $mlSafetyUrl = config('services.ml_safety.url', 'http://127.0.0.1:8001');
        $circuitStatus = $this->mlService->getCircuitStatus();
        $isMLReachable = false;
        try {
            $res = Http::timeout(1)->get("{$mlSafetyUrl}/health");
            $isMLReachable = $res->successful();
        } catch (\Throwable $e) {
            $isMLReachable = false;
        }

        // Get the ML and risk results for each batch
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
                // Past batch or fallback: use the saved assessment
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
     * Refresh the 16-day forecast cache.
     */
    public function syncCache(): RedirectResponse
    {
        try {
            $result = $this->forecastService->updateAllForecasts(16);

            // Re-assess all active batches with the new forecast
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
     * Page 2: weather details for one batch.
     */
    public function show(Batch $batch, BatchSafetyReportService $report): View
    {
        $data = $report->build($batch, auth()->user());

        return view('admin.weather.show', $data + ['vm' => new BatchSafetyViewModel($data)]);
    }

    /**
     * Run the assessment for a batch.
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
     * Save an admin override (like a PAGASA advisory).
     */
    public function override(ApplyOverrideRequest $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validated();

        // Save the hazard name (or the admin's own text for "Other")
        $hazard = $validated['other_hazard'] ?? null;
        $validated['other_hazard'] = match (true) {
            !$hazard => null,
            $hazard === 'other' => trim($validated['other_hazard_detail']),
            default => self::OTHER_HAZARDS[$hazard],
        };
        unset($validated['other_hazard_detail']);

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
     * Cancel the whole batch.
     */
    public function cancel(CancelBatchRequest $request, Batch $batch): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $sentCount = $this->forecastService->cancelBatchWithRefundsAndNotifications(
                $batch,
                $validated['cancellation_reason'],
                auth()->user()
            );

            return back()->with('success', "Batch {$batch->batch_code} is cancelled. {$sentCount} guest(s) were emailed and their full refunds are waiting in Refunds.");
        } catch (Exception $e) {
            return back()->with('error', "Cancellation failed: " . $e->getMessage());
        }
    }
}
