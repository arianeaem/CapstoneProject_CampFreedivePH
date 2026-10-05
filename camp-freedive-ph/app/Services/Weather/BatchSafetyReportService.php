<?php

namespace App\Services\Weather;

use App\Models\Batch;
use App\Models\User;
use App\Services\WeatherForecastService;
use App\Services\WeatherSafetyMLService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Gets all the data for the Safety Monitoring batch page: Day 1 / Day 2 results
 * (runs them again if the forecast is newer), the 24-hour data, the ML model summary,
 * the model comparison and the history.
 */
class BatchSafetyReportService
{
    public function __construct(
        protected WeatherForecastService $forecastService,
        protected WeatherSafetyMLService $mlService
    ) {}

    /**
     * @return array<string, mixed> data for admin.weather.show
     */
    public function build(Batch $batch, ?User $operator = null): array
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

        // Run the assessment if it was never done or the forecast cache is newer
        $lastForecastUpdate = Cache::get('forecast:last_updated_at');
        $needsSync = !$day1Assessment || !$day2Assessment;

        if (!$needsSync && !$isConcluded && $lastForecastUpdate) {
            $lastAssessed = $day1Assessment->assessed_at ?? $day1Assessment->created_at;
            if ($lastAssessed && Carbon::parse($lastForecastUpdate)->greaterThan($lastAssessed)) {
                $needsSync = true;
            }
        }

        if ($needsSync) {
            $this->forecastService->assessBatch($batch, null, $operator);
            $batch->refresh();
            $day1Assessment = $batch->latestDay1Assessment;
            $day2Assessment = $batch->latestDay2Assessment;
        }

        // Overall is the worse of Day 1 and Day 2
        $overallClassification = 'Safe';
        if ($day1Assessment && $day2Assessment) {
            $rank1 = WeatherForecastService::RISK_RANK[$day1Assessment->overall_classification] ?? 1;
            $rank2 = WeatherForecastService::RISK_RANK[$day2Assessment->overall_classification] ?? 1;
            $worseRank = max($rank1, $rank2);
            $overallClassification = array_search($worseRank, WeatherForecastService::RISK_RANK) ?: 'Safe';
        }

        // Group old runs (Day 1 and Day 2 from the same run)
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

            // Find the other day from the same run (within 3 minutes)
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

        // 24-hour data for Day 1 and Day 2
        $day1Date = $batch->start_date->format('Y-m-d');
        $day2Date = $batch->end_date ? $batch->end_date->format('Y-m-d') : $batch->start_date->copy()->addDay()->format('Y-m-d');
        $day1Continuous24h = Cache::get("forecast:date:{$day1Date}");
        $day2Continuous24h = Cache::get("forecast:date:{$day2Date}");

        // Refresh the 16-day cache if it's missing or expired (e.g. after a server restart)
        if ((!$day1Continuous24h || empty($day1Continuous24h['hourly'])) && Carbon::now()->diffInDays($batch->start_date, false) <= 16) {
            try {
                $master = $this->forecastService->updateAllForecasts(16);
                $day1Continuous24h = $master['daily_summaries'][$day1Date] ?? Cache::get("forecast:date:{$day1Date}");
                $day2Continuous24h = $master['daily_summaries'][$day2Date] ?? Cache::get("forecast:date:{$day2Date}");
            } catch (\Throwable $e) {
                // Open-Meteo is down, use the database instead
            }
        }

        // If the cache is still empty, use the saved hourly assessments
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

        // ML model results
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

        // Model comparison (same as the booking page)
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

        return compact(
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
            'modelComparison',
            'isConcluded'
        );
    }
}
