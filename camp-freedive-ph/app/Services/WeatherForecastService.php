<?php

namespace App\Services;

use App\Mail\BatchWeatherCancellationMail;
use App\Models\Batch;
use App\Models\BatchRiskAssessment;
use App\Models\BatchStatusLog;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\ForecastAccuracyLog;
use App\Models\ForecastDaily;
use App\Models\ForecastHourly;
use App\Models\ForecastSnapshot;
use App\Models\HourlyAssessment;
use App\Models\ManualOverride;
use App\Models\NotificationLog;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\ExternalApi\ExternalApiClient;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Weather and sea safety checks for our dive site in Anilao, Batangas.
 *
 * What it does:
 * - scores each hour using 9 weather/sea values (waves, swell, wind, current, rain, pressure, etc.)
 * - adds extra risk when several bad conditions happen at the same time
 * - gets 16 days of hourly forecast from Open-Meteo and keeps it in the cache
 * - compares the rule-based result with the ML model result
 * - handles batch risk, admin overrides and weather cancellations (full refund)
 * - saves forecast snapshots so we can check later how accurate they were
 */
class WeatherForecastService
{
    protected ExternalApiClient $apiClient;

    public function __construct(?ExternalApiClient $apiClient = null)
    {
        $this->apiClient = $apiClient ?? app(ExternalApiClient::class);
    }

    // Dive site location (Anilao / Mabini, Batangas)
    public const LATITUDE = 13.6874;
    public const LONGITUDE = 120.8931;
    public const TIMEZONE = 'Asia/Manila';
    public const MAX_FORECAST_DAYS = 16;

    // Weights for the 9 weather values (they add up to 1.0)
    public const WEIGHTS = [
        'wave_height' => 0.160,
        'wind_speed' => 0.150,
        'ocean_current' => 0.140,
        'swell_height' => 0.125,
        'wave_period' => 0.115,
        'wind_wave_height' => 0.100,
        'rain' => 0.080,
        'sea_level_pressure' => 0.070,
        'wind_direction' => 0.060,
    ];

    public const MEANING_MAP = [
        'Very Safe' => 'Conditions are optimal for freediving. Environmental hazards are minimal.',
        'Safe' => 'Conditions are generally safe, but normal safety protocols should still be followed.',
        'Moderate' => 'Some conditions may affect safety or comfort. Increased monitoring is needed.',
        'High Risk' => 'Conditions present significant hazards that could compromise participant safety.',
        'Critical Risk' => 'Conditions are unsafe for freediving due to severe weather or sea state.',
        'Not Available' => 'Forecast model not yet available for dates beyond 16 days.',
    ];

    public const RISK_RANK = [
        'Not Available' => 0,
        'Very Safe' => 1,
        'Safe' => 2,
        'Moderate' => 3,
        'High Risk' => 4,
        'Critical Risk' => 5,
    ];

    /**
     * Get how reliable the forecast is based on how many days away the date is.
     *
     * Days 1-3:  High   - ok to use for go/no-go decisions
     * Days 4-7:  Medium - good for seeing trends
     * Days 8-16: Low    - rough trend only, don't use for go/no-go
     */
    public static function getReliabilityCategory(int|float $daysOut): array
    {
        if ($daysOut <= 3) {
            return [
                'level' => 'high',
                'range' => 'Days 1-3',
                'label' => 'High Reliability',
                'badge_class' => 'bg-emerald-50 text-emerald-700',
                'dot_color' => 'bg-emerald-500',
                'description' => 'Highly actionable. Use directly for operational safety window greenlighting.',
                'actionable' => true,
            ];
        } elseif ($daysOut <= 7) {
            return [
                'level' => 'medium',
                'range' => 'Days 4-7',
                'label' => 'Medium Reliability',
                'badge_class' => 'bg-amber-50 text-amber-700',
                'dot_color' => 'bg-amber-500',
                'description' => 'Excellent for spotting long-range trends, shifting winds, or monsoon setups.',
                'actionable' => true,
            ];
        } else {
            return [
                'level' => 'low',
                'range' => 'Days 8-16',
                'label' => 'Low Reliability',
                'badge_class' => 'bg-rose-50 text-rose-700',
                'dot_color' => 'bg-rose-500',
                'description' => 'Climatological trend only. Do not use for safety-critical go/no-go logic.',
                'actionable' => false,
            ];
        }
    }

    /**
     * Get the forecast status of a batch based on how far away the dive is.
     *
     * - CONCLUDED: dive is already done
     * - TACTICAL_CLEARANCE: dive day, 1 hour or less before
     * - PROVISIONAL_TREND_OUTLOOK: within 24 hours
     * - EXTENDED_TREND_OUTLOOK: more than 24 hours away
     * - BEYOND_HORIZON: more than 16 days away
     */
    public static function getOperationalHorizon(Batch|Carbon $dateOrBatch): array
    {
        $now = Carbon::now(self::TIMEZONE);

        if ($dateOrBatch instanceof Batch) {
            $startDate = $dateOrBatch->start_date->copy()->startOfDay();
            $leadTimeHours = max(0, $now->diffInHours($dateOrBatch->start_date->copy()->setTime(9, 30), false));
            $daysOut = $now->startOfDay()->diffInDays($startDate, false);

            $isPastOrConcluded = in_array($dateOrBatch->status, ['completed', 'cancelled', 'cancelled_by_camp']) ||
                                 in_array($dateOrBatch->lifecycle_status, ['completed', 'cancelled', 'cancelled_by_camp']) ||
                                 ($dateOrBatch->end_date && $dateOrBatch->end_date->copy()->endOfDay()->isPast()) ||
                                 ($dateOrBatch->start_date->copy()->endOfDay()->isPast() && !$dateOrBatch->end_date);

            if ($isPastOrConcluded) {
                return [
                    'status' => 'CONCLUDED',
                    'label' => 'Concluded Session',
                    'description' => 'Dive operations have concluded for this batch.',
                    'days_out' => $daysOut,
                    'lead_time_hours' => $leadTimeHours,
                ];
            }
        } else {
            $startDate = $dateOrBatch->copy()->startOfDay();
            $leadTimeHours = max(0, $now->diffInHours($dateOrBatch->copy()->setTime(9, 30), false));
            $daysOut = $now->startOfDay()->diffInDays($startDate, false);

            if ($dateOrBatch->copy()->endOfDay()->isPast()) {
                return [
                    'status' => 'CONCLUDED',
                    'label' => 'Concluded Session',
                    'description' => 'Dive date is in the past.',
                    'days_out' => $daysOut,
                    'lead_time_hours' => $leadTimeHours,
                ];
            }
        }

        if ($daysOut < 0) {
            return [
                'status' => 'CONCLUDED',
                'label' => 'Concluded Session',
                'description' => 'Dive date is in the past.',
                'days_out' => $daysOut,
                'lead_time_hours' => $leadTimeHours,
            ];
        }

        if ($startDate->isToday() || $leadTimeHours <= 1) {
            return [
                'status' => 'TACTICAL_CLEARANCE',
                'label' => 'Live Departure Clearance (1 hour before departure)',
                'description' => 'Real-time dockside clearance for immediate departure.',
                'days_out' => $daysOut,
                'lead_time_hours' => $leadTimeHours,
            ];
        }

        if ($leadTimeHours <= 24 || $daysOut <= 1) {
            return [
                'status' => 'PROVISIONAL_TREND_OUTLOOK',
                'label' => '24-Hour Planning Forecast (Final clearance evaluated 1 hour before departure)',
                'description' => '24-hour advance planning outlook. Final go/no-go cleared 1h before departure.',
                'days_out' => $daysOut,
                'lead_time_hours' => $leadTimeHours,
            ];
        }

        if ($daysOut <= self::MAX_FORECAST_DAYS) {
            return [
                'status' => 'EXTENDED_TREND_OUTLOOK',
                'label' => 'Extended Planning Outlook (Advance Planning)',
                'description' => 'Medium to long-range forecast for advance scheduling.',
                'days_out' => $daysOut,
                'lead_time_hours' => $leadTimeHours,
            ];
        }

        return [
            'status' => 'BEYOND_HORIZON',
            'label' => 'Beyond 16-Day Forecast Horizon',
            'description' => 'Forecast models unlock 16 days before dive date.',
            'days_out' => $daysOut,
            'lead_time_hours' => $leadTimeHours,
        ];
    }

    /**
     * Check the risk for a 2-day batch using the 4 dive windows:
     * Day 1 AM (09:30-12:00) and PM (15:30-17:30), same for Day 2.
     */
    public function assessBatch(Batch $batch, ?array $overrides = null, ?User $assessedBy = null): array
    {
        $result = DB::transaction(function () use ($batch, $overrides, $assessedBy) {
            $startDate = $batch->start_date->copy()->startOfDay();
            $endDate = $batch->end_date ? $batch->end_date->copy()->startOfDay() : $startDate->copy()->addDay();

            // If the batch is already done and was assessed before, just return the last assessment
            $isFinished = in_array($batch->status, ['completed', 'cancelled', 'cancelled_by_camp']) || 
                          in_array($batch->lifecycle_status, ['completed', 'cancelled', 'cancelled_by_camp']) ||
                          $endDate->isPast();

            $existingDay1 = $batch->riskAssessments()->where('day_number', 1)->first();
            $existingDay2 = $batch->riskAssessments()->where('day_number', 2)->first();

            if ($isFinished && $existingDay1 && $existingDay2 && empty($overrides)) {
                $overallClassification = $batch->risk_classification 
                    ? ucfirst(str_replace('_', ' ', $batch->risk_classification)) 
                    : $existingDay1->overall_classification;

                return [
                    'batch' => $batch,
                    'overall_classification' => $overallClassification,
                    'day1' => [
                        'assessment_id' => $existingDay1->id,
                        'day_number' => 1,
                        'date' => $existingDay1->dive_date->format('Y-m-d'),
                        'classification' => $existingDay1->overall_classification,
                        'weighted_score_pct' => $existingDay1->weighted_score_pct,
                        'recommended_action' => $existingDay1->recommended_action,
                        'worst_hour' => $existingDay1->worst_hour ? $existingDay1->worst_hour->format('g:i A') : 'N/A',
                        'worst_window' => $existingDay1->worst_window ?? 'N/A',
                        'am' => ['classification' => $existingDay1->overall_classification, 'hourly' => []],
                        'pm' => ['classification' => $existingDay1->overall_classification, 'hourly' => []],
                    ],
                    'day2' => [
                        'assessment_id' => $existingDay2->id,
                        'day_number' => 2,
                        'date' => $existingDay2->dive_date->format('Y-m-d'),
                        'classification' => $existingDay2->overall_classification,
                        'weighted_score_pct' => $existingDay2->weighted_score_pct,
                        'recommended_action' => $existingDay2->recommended_action,
                        'worst_hour' => $existingDay2->worst_hour ? $existingDay2->worst_hour->format('g:i A') : 'N/A',
                        'worst_window' => $existingDay2->worst_window ?? 'N/A',
                        'am' => ['classification' => $existingDay2->overall_classification, 'hourly' => []],
                        'pm' => ['classification' => $existingDay2->overall_classification, 'hourly' => []],
                    ],
                ];
            }

            $assessedAt = now();

            // Day 1
            $day1Result = $this->assessDay($batch, 1, $startDate, $overrides, $assessedBy, $assessedAt);

            // Day 2
            $day2Result = $this->assessDay($batch, 2, $endDate, $overrides, $assessedBy, $assessedAt);

            // Overall result is the worse of Day 1 and Day 2
            $rank1 = self::RISK_RANK[$day1Result['classification']] ?? 1;
            $rank2 = self::RISK_RANK[$day2Result['classification']] ?? 1;
            $worseRank = max($rank1, $rank2);
            $overallClassification = array_search($worseRank, self::RISK_RANK) ?: 'Safe';

            // Slug version for batches.risk_classification
            $riskSlug = match ($overallClassification) {
                'Very Safe' => 'very_safe',
                'Safe' => 'safe',
                'Moderate' => 'moderate',
                'High Risk' => 'high_risk',
                'Critical Risk' => 'critical_risk',
                'Not Available' => null, // no forecast yet = not assessed
                default => 'safe',
            };

            $batch->update([
                'risk_classification' => $riskSlug,
            ]);

            // Also get the ML model result
            $day1ML = $this->assessMLSafetyForDate($startDate->format('Y-m-d'), '08:00', '18:00', $overrides);
            $day2ML = $this->assessMLSafetyForDate($endDate->format('Y-m-d'), '08:00', '18:00', $overrides);

            $batchML = null;
            if ($day1ML || $day2ML) {
                $mlRec1 = $day1ML['overall_recommendation'] ?? 'Safe';
                $mlRec2 = $day2ML['overall_recommendation'] ?? 'Safe';
                $worseMLRank = max(self::RISK_RANK[$mlRec1] ?? 1, self::RISK_RANK[$mlRec2] ?? 1);
                $worseMLRec = array_search($worseMLRank, self::RISK_RANK) ?: 'Safe';

                $horizonInfo = self::getOperationalHorizon($batch);
                $batchML = [
                    'overall_recommendation' => $worseMLRec,
                    'ml_recommendation' => $worseMLRec,
                    'ml_classification' => $worseMLRec,
                    'operational_status' => $day1ML['operational_status'] ?? $day2ML['operational_status'] ?? $horizonInfo['status'],
                    'operational_status_label' => $day1ML['operational_status_label'] ?? $day2ML['operational_status_label'] ?? $horizonInfo['label'],
                    'day1' => $day1ML,
                    'day2' => $day2ML,
                    'is_authoritative_go' => ($day1ML['is_authoritative_go'] ?? false) && ($day2ML['is_authoritative_go'] ?? false),
                    'safety_threshold_triggered' => ($day1ML['safety_threshold_triggered'] ?? $day1ML['hard_gate_triggered'] ?? false) || ($day2ML['safety_threshold_triggered'] ?? $day2ML['hard_gate_triggered'] ?? false),
                    'hard_gate_triggered' => ($day1ML['safety_threshold_triggered'] ?? $day1ML['hard_gate_triggered'] ?? false) || ($day2ML['safety_threshold_triggered'] ?? $day2ML['hard_gate_triggered'] ?? false),
                ];
            }

            AuditLogger::log(
                'BATCH_ASSESSED',
                "Weather risk assessed for batch {$batch->batch_code}: Day 1={$day1Result['classification']}, Day 2={$day2Result['classification']} (Overall: {$overallClassification}).",
                $assessedBy,
                $assessedBy ? $assessedBy->name : 'System'
            );

            return [
                'batch' => $batch,
                'overall_classification' => $overallClassification,
                'evaluating_engine' => $batchML ? 'primary_ml' : 'open_meteo_physics_backup',
                'evaluating_engine_label' => $batchML ? 'Primary AI Model' : 'Open-Meteo Marine Physics Backup',
                'day1' => array_merge($day1Result, ['ml_assessment' => $day1ML]),
                'day2' => array_merge($day2Result, ['ml_assessment' => $day2ML]),
                'ml_assessment' => $batchML,
            ];
        });

        $classification = $result['overall_classification'] ?? 'Safe';
        if (in_array($classification, ['High Risk', 'Critical Risk'], true)) {
            $assessedBatch = $batch->fresh();
            app(\App\Services\AdminNotificationService::class)->risk($assessedBatch, $classification);
            // Less than 18 hours before the dive: email participants and admins (Critical = reschedule/refund, High = heads-up)
            app(\App\Services\WeatherRiskNotifier::class)->handle($assessedBatch, $classification);
        }

        return $result;
    }

    /**
     * Check one day (AM and PM windows). The worse window is used for the day.
     */
    public function assessDay(Batch $batch, int $dayNumber, Carbon $date, ?array $overrides, ?User $assessedBy, ?Carbon $assessedAt = null): array
    {
        $assessedAt = $assessedAt ?? now();
        $daysOut = Carbon::now(self::TIMEZONE)->diffInDays($date->copy()->startOfDay(), false);
        $leadTimeHours = max(0, Carbon::now(self::TIMEZONE)->diffInHours($date->copy()->setTime(9, 30), false));
        $overrideTriggered = $this->checkOverrideConditions($overrides);

        // Date is already past (for finished batches)
        if (!$overrideTriggered && $daysOut < -1) {
            $finalClass = match ($batch->risk_classification) {
                'very_safe' => 'Very Safe',
                'safe' => 'Safe',
                'moderate' => 'Moderate',
                'high_risk' => 'High Risk',
                'critical_risk' => 'Critical Risk',
                default => 'Not Available',
            };

            $recommendedAction = self::MEANING_MAP[$finalClass] ?? 'Concluded batch operations.';
            $defaultScore = match ($finalClass) {
                'Very Safe' => 15.0,
                'Safe' => 30.0,
                'Moderate' => 50.0,
                'High Risk' => 70.0,
                'Critical Risk' => 100.0,
                'Not Available' => 0.0,
                default => 25.0,
            };

            $riskAssessment = BatchRiskAssessment::create([
                'batch_id' => $batch->id,
                'day_number' => $dayNumber,
                'dive_date' => $date->format('Y-m-d'),
                'lead_time_hours' => 0,
                'overall_classification' => $finalClass,
                'weighted_score_pct' => $defaultScore,
                'recommended_action' => $recommendedAction,
                'worst_window' => '10:00-12:00',
                'worst_hour' => $date->copy()->setTime(11, 0),
                'override_triggered' => false,
                'override_details' => $overrides,
                'assessed_by' => $assessedBy?->id,
                'assessed_at' => $assessedAt,
            ]);

            return [
                'assessment_id' => $riskAssessment->id,
                'day_number' => $dayNumber,
                'date' => $date->format('Y-m-d'),
                'lead_time_hours' => 0,
                'classification' => $finalClass,
                'weighted_score_pct' => $defaultScore,
                'recommended_action' => $recommendedAction,
                'worst_window' => '10:00-12:00',
                'worst_hour' => '11:00 AM',
                'am' => ['classification' => $finalClass, 'hourly' => []],
                'pm' => ['classification' => $finalClass, 'hourly' => []],
            ];
        }

        // Date is more than 16 days away, outside the forecast range
        if (!$overrideTriggered && $daysOut > self::MAX_FORECAST_DAYS) {
            $dayClassification = 'Not Available';
            $recommendedAction = "Forecast model is not yet available beyond 16 days out. Assessment will unlock on " . $date->copy()->subDays(16)->format('M d, Y') . " (16 days before dive date).";

            $riskAssessment = BatchRiskAssessment::create([
                'batch_id' => $batch->id,
                'day_number' => $dayNumber,
                'dive_date' => $date->format('Y-m-d'),
                'lead_time_hours' => $leadTimeHours,
                'overall_classification' => $dayClassification,
                'weighted_score_pct' => null,
                'recommended_action' => $recommendedAction,
                'worst_window' => null,
                'worst_hour' => null,
                'override_triggered' => false,
                'override_details' => $overrides,
                'assessed_by' => $assessedBy?->id,
                'assessed_at' => $assessedAt,
            ]);

            return [
                'assessment_id' => $riskAssessment->id,
                'day_number' => $dayNumber,
                'date' => $date->format('Y-m-d'),
                'lead_time_hours' => $leadTimeHours,
                'classification' => $dayClassification,
                'weighted_score_pct' => null,
                'recommended_action' => $recommendedAction,
                'worst_window' => 'N/A',
                'worst_hour' => 'N/A',
                'am' => ['classification' => 'Not Available', 'hourly' => []],
                'pm' => ['classification' => 'Not Available', 'hourly' => []],
            ];
        }

        // AM window (09:30 - 12:00)
        $amData = $this->assessWindow($date->format('Y-m-d'), '09:30', '12:00', 'am', $overrides);

        // PM window (15:30 - 17:30)
        $pmData = $this->assessWindow($date->format('Y-m-d'), '15:30', '17:30', 'pm', $overrides);

        // Whole daytime (06:00 - 18:00)
        $cachedDay = $this->getCachedDayForecast($date->format('Y-m-d'));
        if (!$cachedDay && !$overrideTriggered) {
            try {
                $this->updateAllForecasts(16);
                $cachedDay = $this->getCachedDayForecast($date->format('Y-m-d'));
            } catch (\Throwable $e) {}
        }

        // ML model check for the dive hours
        $mlAssessment = !$overrideTriggered ? $this->assessMLSafetyForDate($date->format('Y-m-d'), '08:00', '18:00', $overrides) : null;
        // Anything missing counts as "Not Available" (rank 0), never as Safe
        $mlRank = $mlAssessment ? (self::RISK_RANK[$mlAssessment['overall_recommendation'] ?? 'Not Available'] ?? 0) : 0;

        // The day's rating is the whole day (06:00-18:00) and the ML check (08:00-18:00).
        // The AM/PM open-water windows are kept as hourly detail and do not change the rating.
        $amRank = self::RISK_RANK[$amData['classification']] ?? 0;
        $pmRank = self::RISK_RANK[$pmData['classification']] ?? 0;
        $daytimeRank = self::RISK_RANK[$cachedDay['daytime_classification'] ?? 'Not Available'] ?? 0;

        if ($overrideTriggered || $daytimeRank === 5 || $mlRank === 5) {
            $dayClassification = 'Critical Risk';
            $weightedScorePct = 100.0;
            $worstWindow = ($pmRank >= $amRank) ? '15:30-17:30' : '09:30-12:00';
            $worstHour = ($pmRank >= $amRank) ? $pmData['worst_hour'] : $amData['worst_hour'];
            $recommendedAction = self::MEANING_MAP['Critical Risk'];
        } else {
            $dayRank = max($daytimeRank, $mlRank);
            $dayClassification = array_search($dayRank, self::RISK_RANK) ?: 'Not Available';
            $weightedScorePct = $cachedDay['daytime_score_pct'] ?? null;
            $worstWindow = ($pmRank >= $amRank) ? '15:30-17:30' : '09:30-12:00';
            $worstHour = ($pmRank >= $amRank) ? $pmData['worst_hour'] : $amData['worst_hour'];
            $recommendedAction = self::MEANING_MAP[$dayClassification] ?? 'Proceed with caution.';

            if ($dayClassification === 'Not Available') {
                $weightedScorePct = null;
                $worstWindow = null;
                $worstHour = null;
                $recommendedAction = "Open-Meteo's sea forecast (waves, swell, currents) only reaches about 9-10 days ahead, "
                    . 'so this day cannot be rated yet. It will be rated automatically once the data is available.';
            }
        }

        // Roughest hour of the whole day, kept only when rougher than the day's rating
        $dayPeak = (!$overrideTriggered && !empty($cachedDay['peak'])
            && (self::RISK_RANK[$cachedDay['peak']['classification']] ?? 0) >= (self::RISK_RANK[$dayClassification] ?? 0))
            ? $cachedDay['peak'] : null;

        // Save the day assessment
        $riskAssessment = BatchRiskAssessment::create([
            'batch_id' => $batch->id,
            'day_number' => $dayNumber,
            'dive_date' => $date->format('Y-m-d'),
            'lead_time_hours' => $leadTimeHours,
            'overall_classification' => $dayClassification,
            'weighted_score_pct' => $overrideTriggered ? null : $weightedScorePct,
            'recommended_action' => $recommendedAction,
            'worst_window' => $worstWindow,
            'worst_hour' => $worstHour,
            'override_triggered' => $overrideTriggered,
            'override_details' => $overrides,
            'peak_classification' => $dayPeak['classification'] ?? null,
            'peak_time' => $dayPeak['time'] ?? null,
            'peak_reason' => $dayPeak['reason'] ?? null,
            'assessed_by' => $assessedBy?->id,
            'assessed_at' => $assessedAt,
        ]);

        // Save hourly rows for AM
        foreach ($amData['hourly'] as $h) {
            HourlyAssessment::create(array_merge($h, [
                'risk_assessment_id' => $riskAssessment->id,
                'window_type' => 'am',
                'open_water_window' => '09:30-12:00',
            ]));
        }

        // Save hourly rows for PM
        foreach ($pmData['hourly'] as $h) {
            HourlyAssessment::create(array_merge($h, [
                'risk_assessment_id' => $riskAssessment->id,
                'window_type' => 'pm',
                'open_water_window' => '15:30-17:30',
            ]));
        }

        return [
            'assessment_id' => $riskAssessment->id,
            'day_number' => $dayNumber,
            'date' => $date->format('Y-m-d'),
            'lead_time_hours' => $leadTimeHours,
            'classification' => $dayClassification,
            'weighted_score_pct' => $weightedScorePct,
            'recommended_action' => $recommendedAction,
            'worst_window' => $worstWindow,
            'worst_hour' => $worstHour ? Carbon::parse($worstHour)->format('g:i A') : 'N/A',
            'am' => $amData,
            'pm' => $pmData,
        ];
    }

    /**
     * Weather preview for the date picked on the booking form.
     *
     * Runs both forecast engines so we can compare them:
     * - legacy: Open-Meteo forecast, then our ONNX safety model
     * - historical: our site model (/forecast/site + climatology)
     * Legacy is the main answer. If it is not available we use historical.
     * Both results are returned under 'engines'.
     */
    public function previewDateAssessment(Carbon $startDate, bool $historicalReplay = false): array
    {
        $endDate = $startDate->copy()->addDay();
        $daysOut = Carbon::today(self::TIMEZONE)->diffInDays($startDate->copy()->startOfDay(), false);

        if ($daysOut < 0 && !$historicalReplay) {
            return [
                'available' => false,
                'message' => "Selected date is in the past.",
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ];
        }

        // Both trip days must be inside the 16-day Open-Meteo range (today = day 0)
        $legacy = (!$historicalReplay && ($daysOut + 1) < self::MAX_FORECAST_DAYS)
            ? $this->previewFromApiForecastThenModel($startDate, $endDate, (int) $daysOut)
            : null;
        $historical = $this->previewFromHistoricalModel($startDate, $historicalReplay);

        $legacy = $legacy ? $this->withOverallPeak($legacy) : null;
        $historical = $this->withOverallPeak($historical);
        $primary = $legacy ?? $historical;
        $primary['engines'] = [
            'historical' => $this->summarizePreviewEngine('Historical Model', $historical),
            'legacy' => $this->summarizePreviewEngine('Legacy (Open-Meteo + ONNX)', $legacy),
        ];

        return $primary;
    }

    /**
     * Historical vs legacy comparison for a batch (same as the booking page).
     * Returns null for past batches or if it's not available.
     */
    public function modelComparisonForBatch(Batch $batch): ?array
    {
        if (!$batch->start_date || $batch->start_date->copy()->startOfDay()->lt(Carbon::today(self::TIMEZONE))) {
            return null;
        }

        try {
            return $this->previewDateAssessment($batch->start_date->copy())['engines'] ?? null;
        } catch (\Throwable $e) {
            Log::info("[WeatherForecastService] Model comparison unavailable for batch {$batch->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Short summary of one engine's result for the comparison on the booking form.
     */
    /** Adds the roughest day peak to a preview when it is rougher than the overall rating. */
    private function withOverallPeak(array $preview): array
    {
        $overallRank = self::RISK_RANK[$preview['overall_classification'] ?? 'Not Available'] ?? 0;
        $peak = collect([$preview['day1']['peak'] ?? null, $preview['day2']['peak'] ?? null])
            ->filter()
            ->sortByDesc(fn ($p) => self::RISK_RANK[$p['classification']] ?? 0)
            ->first();
        $peak = $peak && (self::RISK_RANK[$peak['classification']] ?? 0) >= $overallRank ? $peak : null;

        $preview['peak'] = $peak;
        $preview['peak_label'] = self::peakLabel($peak);

        return $preview;
    }

    protected function summarizePreviewEngine(string $label, ?array $preview): array
    {
        if (empty($preview['available'])) {
            return ['label' => $label, 'available' => false];
        }

        $day = fn (array $d) => [
            'date' => $d['date'] ?? null,
            'classification' => $d['classification'] ?? 'Safe',
            'worst_hour' => $d['worst_hour'] ?? 'N/A',
            'recommended_action' => $d['recommended_action'] ?? null,
            'peak_label' => self::peakLabel($d['peak'] ?? null),
        ];
        $seasonal = ($preview['day1']['seasonal_estimate'] ?? false) && ($preview['day2']['seasonal_estimate'] ?? false);

        return [
            'label' => $label,
            'available' => true,
            'overall_classification' => $preview['overall_classification'] ?? 'Safe',
            'peak_label' => $preview['peak_label'] ?? null,
            'data_source' => $preview['data_source'] ?? null,
            'is_seasonal_estimate' => $seasonal,
            'day1' => $day($preview['day1'] ?? []),
            'day2' => $day($preview['day2'] ?? []),
        ];
    }

    /**
     * Historical model preview using forecast_daily / forecast_hourly.
     * If that fails we check the dive windows using Open-Meteo directly.
     */
    protected function previewFromHistoricalModel(Carbon $startDate, bool $historicalReplay = false): array
    {
        $endDate = $startDate->copy()->addDay();
        $today = Carbon::today(self::TIMEZONE);
        $daysOut = $today->diffInDays($startDate->copy()->startOfDay(), false);

        if ($historicalReplay) {
            $replayIssuedAt = $startDate->copy()->startOfDay()->subHour()->toIso8601String();
            $this->forecastSite(
                issuedAt: $replayIssuedAt,
                days: 2,
                forceStale: false
            );
        }

        $isBenchmark = ($daysOut > self::MAX_FORECAST_DAYS);
        $reliability = $isBenchmark ? 'Seasonal Baseline' : self::getReliabilityCategory($daysOut);

        // Check if both days are in the cache
        $d1Key = $startDate->format('Y-m-d');
        $d2Key = $endDate->format('Y-m-d');

        $d1Cache = $this->getCachedDayForecast($d1Key);
        $d2Cache = $this->getCachedDayForecast($d2Key);

        if (!$d1Cache || !$d2Cache) {
            try {
                $this->updateAllForecasts(16);
                $d1Cache = $this->getCachedDayForecast($d1Key);
                $d2Cache = $this->getCachedDayForecast($d2Key);
            } catch (\Throwable $e) {
                // Ignore the error, we fall back to the window check below
            }
        }

        if ($d1Cache && $d2Cache) {
            // Gets the window results and weather readings for one day
            $extractDayDetails = function (array $cache, Carbon $date, string $conf, ?string $advisory) use ($daysOut) {
                $hourly = $cache['hourly'] ?? [];
                $daily = $cache['daily'] ?? [];

                $maxScoreAll = -1;
                $worstHourAll = null;

                $maxScore0618 = -1;
                $worstHour0618 = null;
                $worstTier0618 = 'Safe';
                $worstLabel0618 = '';
                $worstScoreDetails0618 = [];

                $maxScoreAM = -1;
                $worstHourAM = null;
                $worstTierAM = 'Safe';

                $maxScorePM = -1;
                $worstHourPM = null;
                $worstTierPM = 'Safe';

                $peakHs = 0.0;
                $peakHsP90 = 0.0;
                $hsSource = 'climatology';

                $peakCurrent = 0.0;
                $peakCurrentP90 = 0.0;
                $currentSource = 'climatology';

                $maxWindMs = 0.0;
                $maxGustMs = 0.0;

                foreach ($hourly as $h) {
                    $score = $h['weighted_score_pct'] ?? ((self::RISK_RANK[$h['tier'] ?? ($h['classification'] ?? 'Safe')] ?? 1) * 20);
                    $isoTime = $h['iso_time'] ?? ($h['forecast_time'] ?? null);
                    $hourInt = $isoTime ? (int) Carbon::parse($isoTime)->hour : 12;

                    if ($score > $maxScoreAll) {
                        $maxScoreAll = $score;
                        $worstHourAll = $isoTime;
                    }

                    // Daytime hours (06:00 - 18:00)
                    if ($hourInt >= 6 && $hourInt <= 18) {
                        if ($score > $maxScore0618) {
                            $maxScore0618 = $score;
                            $worstHour0618 = $isoTime;
                            $worstTier0618 = $h['tier'] ?? ($h['classification'] ?? 'Safe');
                            $worstLabel0618 = $h['label'] ?? '';
                            $worstScoreDetails0618 = $h['score_details'] ?? [];
                        }

                        $hHs = (float)($h['hs_p50'] ?? ($h['hs']['p50'] ?? 0));
                        $hHsP90 = (float)($h['hs_p90'] ?? ($h['hs']['p90'] ?? 0));
                        if ($hHs > $peakHs) $peakHs = $hHs;
                        if ($hHsP90 > $peakHsP90) $peakHsP90 = $hHsP90;
                        if (!empty($h['hs_source'])) $hsSource = $h['hs_source'];
                        elseif (!empty($h['hs']['source'])) $hsSource = $h['hs']['source'];

                        $hCurr = (float)($h['current_speed_p50'] ?? ($h['current_speed']['p50'] ?? 0));
                        $hCurrP90 = (float)($h['current_speed_p90'] ?? ($h['current_speed']['p90'] ?? 0));
                        if ($hCurr > $peakCurrent) $peakCurrent = $hCurr;
                        if ($hCurrP90 > $peakCurrentP90) $peakCurrentP90 = $hCurrP90;
                        if (!empty($h['current_speed_source'])) $currentSource = $h['current_speed_source'];
                        elseif (!empty($h['current_speed']['source'])) $currentSource = $h['current_speed']['source'];

                        $hWind = (float)($h['wind_speed_p50'] ?? ($h['wind_speed']['p50'] ?? 0));
                        $hGust = (float)($h['wind_gust_p50'] ?? ($h['wind_gust']['p50'] ?? 0));
                        if ($hWind > $maxWindMs) $maxWindMs = $hWind;
                        if ($hGust > $maxGustMs) $maxGustMs = $hGust;
                    }

                    // AM window (09:30 - 12:00)
                    if ($hourInt >= 9 && $hourInt <= 12) {
                        if ($score > $maxScoreAM) {
                            $maxScoreAM = $score;
                            $worstHourAM = $isoTime;
                            $worstTierAM = $h['tier'] ?? ($h['classification'] ?? 'Safe');
                        }
                    }

                    // PM window (15:30 - 17:30)
                    if ($hourInt >= 15 && $hourInt <= 18) {
                        if ($score > $maxScorePM) {
                            $maxScorePM = $score;
                            $worstHourPM = $isoTime;
                            $worstTierPM = $h['tier'] ?? ($h['classification'] ?? 'Safe');
                        }
                    }
                }

                $rainDaily = (float)($daily['rain_daily_mm_p50'] ?? ($cache['rain_daily_mm'] ?? 0));
                $rainLabel = $daily['rain_label'] ?? ($cache['rain_label'] ?? 'Light Rain');
                $rainScore = $daily['rain_score'] ?? ($cache['rain_score'] ?? 0);
                $pWet = (float)($daily['p_wet'] ?? ($cache['p_wet'] ?? 0));
                $pHighGust = (float)($daily['p_high_gust'] ?? ($cache['p_high_gust'] ?? 0));

                $dayClassification = $cache['overall_classification'] ?? ($worstTier0618 ?: 'Safe');

                return [
                    'date' => $date->format('M d, Y'),
                    'classification' => $dayClassification,
                    'peak' => !empty($cache['peak']) && (self::RISK_RANK[$cache['peak']['classification']] ?? 0) >= (self::RISK_RANK[$dayClassification] ?? 0) ? $cache['peak'] : null,
                    'confidence' => $conf,
                    'confidence_advisory' => $advisory,
                    'recommended_action' => self::MEANING_MAP[$dayClassification] ?? 'Conditions are generally safe, but normal safety protocols should still be followed.',
                    'worst_hour' => $worstHour0618 ? Carbon::parse($worstHour0618)->format('g:i A') : ($worstHourAll ? Carbon::parse($worstHourAll)->format('g:i A') : '11:00 AM'),
                    'lead_time' => max(0, Carbon::now(self::TIMEZONE)->diffInHours($date->copy()->setTime(9, 30), false)) . ' hours',
                    // Results per window
                    'operational_hours' => [
                        'window' => '06:00 - 18:00',
                        'worst_tier' => $worstTier0618,
                        'worst_hour' => $worstHour0618 ? Carbon::parse($worstHour0618)->format('g:i A') : 'N/A',
                        'label' => $worstLabel0618,
                        'score_details' => $worstScoreDetails0618,
                    ],
                    'am' => [
                        'window' => '09:30 - 12:00',
                        'classification' => $worstTierAM,
                        'worst_hour' => $worstHourAM ? Carbon::parse($worstHourAM)->format('g:i A') : '11:00 AM',
                    ],
                    'pm' => [
                        'window' => '15:30 - 17:30',
                        'classification' => $worstTierPM,
                        'worst_hour' => $worstHourPM ? Carbon::parse($worstHourPM)->format('g:i A') : '4:00 PM',
                    ],
                    // Weather readings
                    'physics' => [
                        'hs_p50_m' => round($peakHs, 2),
                        'hs_p90_m' => round($peakHsP90, 2),
                        'hs_source' => $hsSource,
                        'current_speed_p50_ms' => round($peakCurrent, 2),
                        'current_speed_p90_ms' => round($peakCurrentP90, 2),
                        'current_source' => $currentSource,
                        'wind_speed_ms' => round($maxWindMs, 2),
                        'wind_speed_kmh' => round($maxWindMs * 3.6, 1),
                        'wind_gust_ms' => round($maxGustMs, 2),
                        'wind_gust_kmh' => round($maxGustMs * 3.6, 1),
                        'rain_daily_mm' => $rainDaily,
                        'rain_label' => $rainLabel,
                        'rain_score' => $rainScore,
                        'p_wet' => round($pWet * 100, 1) . '%',
                        'p_high_gust' => round($pHighGust * 100, 1) . '%',
                    ],
                    'seasonal_estimate' => $hsSource === 'climatology' && $currentSource === 'climatology',
                    // Extra keys used by the UI
                    'wave_height_m' => round($peakHs, 2),
                    'current_speed_ms' => round($peakCurrent, 2),
                    'wind_speed_ms' => round($maxWindMs, 2),
                    'wind_speed_kmh' => round($maxWindMs * 3.6, 1),
                    'wind_gust_ms' => round($maxGustMs, 2),
                    'wind_gust_kmh' => round($maxGustMs * 3.6, 1),
                    'rain_daily_mm' => $rainDaily,
                    'rain_label' => $rainLabel,
                    'p_wet' => $pWet,
                    'p_high_gust' => $pHighGust,
                ];
            };

            $d1Confidence = 'high';
            $d2Confidence = 'high';
            $overallConfidence = 'high';
            $overallAdvisory = null;

            $d1Details = $extractDayDetails($d1Cache, $startDate, $d1Confidence, ($d1Confidence === 'low') ? "Confidence is low this far out, recheck in 2 days." : null);
            $d2Details = $extractDayDetails($d2Cache, $endDate, $d2Confidence, ($d2Confidence === 'low') ? "Confidence is low this far out, recheck in 2 days." : null);

            $seasonalEstimate = ($d1Details['seasonal_estimate'] ?? false)
                && ($d2Details['seasonal_estimate'] ?? false);
            if ($seasonalEstimate) {
                $reliability = [
                    'level' => 'seasonal',
                    'range' => 'Climatology',
                    'label' => 'Seasonal estimate',
                    'badge_class' => 'bg-slate-50 text-slate-700',
                    'dot_color' => 'bg-slate-500',
                    'description' => 'Typical conditions for this time of year. Storms are not detected; check PAGASA advisories.',
                    'actionable' => false,
                ];
                $overallConfidence = 'seasonal';
                $overallAdvisory = $reliability['description'];
            } else {
                $reliability = self::getReliabilityCategory($daysOut);
            }

            $day1Class = $d1Details['classification'];
            $day2Class = $d2Details['classification'];

            // Trip result is the worse of Day 1 and Day 2
            $worseRank = max(self::RISK_RANK[$day1Class] ?? 1, self::RISK_RANK[$day2Class] ?? 1);
            $overallClass = array_search($worseRank, self::RISK_RANK) ?: 'Safe';

            $dataSource = (
                $d1Details['physics']['hs_source'] === 'model'
                || $d2Details['physics']['hs_source'] === 'model'
                || $d1Details['physics']['current_source'] === 'model'
                || $d2Details['physics']['current_source'] === 'model'
            )
                ? 'Camp FreedivePH Multi-Source Physics Pipeline (CMEMS & ERA5)'
                : 'Camp FreedivePH Historical Climatological Baseline';

            return [
                'available' => true,
                'start_date' => $startDate->format('Y-m-d'),
                'start_date_formatted' => $startDate->format('M d, Y (l)'),
                'end_date' => $endDate->format('Y-m-d'),
                'end_date_formatted' => $endDate->format('M d, Y (l)'),
                'overall_classification' => $overallClass,
                'confidence' => $overallConfidence,
                'confidence_advisory' => $overallAdvisory,
                'data_source' => $dataSource,
                'days_out' => $daysOut,
                'reliability' => $reliability,
                'historical_replay' => $historicalReplay,
                'historical_replay_label' => $historicalReplay ? 'Historical replay' : null,
                'day1' => $d1Details,
                'day2' => $d2Details,
                'advisory_notes' => [
                    'Forecast models are updated continuously as the scheduled trip approaches.',
                    'Final go/no-go departure decision is subject to Camp FreedivePH operator confirmation.',
                ],
            ];
        }

        // Fallback: check the dive windows using Open-Meteo
        $day1AM = $this->assessWindow($startDate->format('Y-m-d'), '09:30', '12:00', 'am');
        $day1PM = $this->assessWindow($startDate->format('Y-m-d'), '15:30', '17:30', 'pm');
        $day1Class = (self::RISK_RANK[$day1PM['classification']] ?? 1) > (self::RISK_RANK[$day1AM['classification']] ?? 1)
            ? $day1PM['classification']
            : $day1AM['classification'];

        $day2AM = $this->assessWindow($endDate->format('Y-m-d'), '09:30', '12:00', 'am');
        $day2PM = $this->assessWindow($endDate->format('Y-m-d'), '15:30', '17:30', 'pm');
        $day2Class = (self::RISK_RANK[$day2PM['classification']] ?? 1) > (self::RISK_RANK[$day2AM['classification']] ?? 1)
            ? $day2PM['classification']
            : $day2AM['classification'];

        $worseRank = max(self::RISK_RANK[$day1Class] ?? 1, self::RISK_RANK[$day2Class] ?? 1);
        $overallClass = array_search($worseRank, self::RISK_RANK) ?: 'Safe';

        $worstHourD1 = (self::RISK_RANK[$day1PM['classification']] ?? 1) > (self::RISK_RANK[$day1AM['classification']] ?? 1)
            ? ($day1PM['worst_hour'] ?: $day1AM['worst_hour'])
            : ($day1AM['worst_hour'] ?: $day1PM['worst_hour']);

        $worstHourD2 = (self::RISK_RANK[$day2PM['classification']] ?? 1) > (self::RISK_RANK[$day2AM['classification']] ?? 1)
            ? ($day2PM['worst_hour'] ?: $day2AM['worst_hour'])
            : ($day2AM['worst_hour'] ?: $day2PM['worst_hour']);

        $d1Confidence = ($daysOut >= 4) ? 'low' : 'high';
        $d2Confidence = (($daysOut + 1) >= 4) ? 'low' : 'high';
        $overallConfidence = ($daysOut >= 4) ? 'low' : 'high';
        $overallAdvisory = ($overallConfidence === 'low') ? "Confidence is low this far out, recheck in 2 days." : null;

        return [
            'available' => true,
            'historical_replay' => $historicalReplay,
            'historical_replay_label' => $historicalReplay ? 'Historical replay' : null,
            'start_date' => $startDate->format('Y-m-d'),
            'start_date_formatted' => $startDate->format('M d, Y (l)'),
            'end_date' => $endDate->format('Y-m-d'),
            'end_date_formatted' => $endDate->format('M d, Y (l)'),
            'overall_classification' => $overallClass,
            'confidence' => $overallConfidence,
            'confidence_advisory' => $overallAdvisory,
            'data_source' => 'Live Open-Meteo Marine & Weather Radar (Anilao, Batangas)',
            'days_out' => $daysOut,
            'reliability' => $reliability,
            'day1' => [
                'date' => $startDate->format('M d, Y'),
                'classification' => $day1Class,
                'confidence' => $d1Confidence,
                'confidence_advisory' => ($d1Confidence === 'low') ? "Confidence is low this far out, recheck in 2 days." : null,
                'recommended_action' => self::MEANING_MAP[$day1Class] ?? 'Proceed with caution.',
                'worst_hour' => $worstHourD1 ? Carbon::parse($worstHourD1)->format('g:i A') : '11:00 AM',
                'lead_time' => max(0, Carbon::now(self::TIMEZONE)->diffInHours($startDate->copy()->setTime(9, 30), false)) . ' hours',
            ],
            'day2' => [
                'date' => $endDate->format('M d, Y'),
                'classification' => $day2Class,
                'confidence' => $d2Confidence,
                'confidence_advisory' => ($d2Confidence === 'low') ? "Confidence is low this far out, recheck in 2 days." : null,
                'recommended_action' => self::MEANING_MAP[$day2Class] ?? 'Proceed with caution.',
                'worst_hour' => $worstHourD2 ? Carbon::parse($worstHourD2)->format('g:i A') : '11:00 AM',
                'lead_time' => max(0, Carbon::now(self::TIMEZONE)->diffInHours($endDate->copy()->setTime(9, 30), false)) . ' hours',
            ],
            'advisory_notes' => [
                'Forecast models are updated hourly as the scheduled trip approaches.',
                'Final go/no-go departure decision is subject to Camp FreedivePH operator confirmation.',
            ],
        ];
    }

    /**
     * Booking preview: Open-Meteo forecast, then our safety model (/assess-booking).
     *
     * The forecast is scored with our rules first. Then the same hourly data is sent to
     * the model and the model's answer is used for the day. If the model is down we use
     * the rule-based result. Returns null if there is no forecast so the caller can use
     * the historical model instead.
     */
    protected function previewFromApiForecastThenModel(Carbon $startDate, Carbon $endDate, int $daysOut): ?array
    {
        $days = [];

        foreach ([1 => $startDate, 2 => $endDate] as $dayNumber => $date) {
            $dateKey = $date->format('Y-m-d');

            // Step 1: get the Open-Meteo forecast (cache first)
            $apiDay = Cache::get("forecast:date:{$dateKey}");
            if (empty($apiDay)) {
                try {
                    $apiDay = $this->updateAllForecasts(self::MAX_FORECAST_DAYS)['daily_summaries'][$dateKey] ?? null;
                } catch (\Throwable $e) {
                    Log::info("[WeatherForecastService] Open-Meteo forecast unavailable for preview: " . $e->getMessage());
                    return null;
                }
            }
            if (empty($apiDay) || empty($apiDay['hourly']) || ($apiDay['overall_classification'] ?? null) === 'Not Available') {
                return null;
            }

            // The day's rating is the whole day (06:00-18:00); dive times are not used for it
            $apiClass = $apiDay['overall_classification'] ?? 'Not Available';
            $apiPeak = $apiDay['peak'] ?? null;

            // Worst daytime hour (06:00 - 18:00) based on the rule score
            $worstApiHour = null;
            $worstApiScore = -1;
            foreach ($apiDay['hourly'] as $h) {
                $hour = (int) ($h['hour'] ?? 12);
                if ($hour >= 6 && $hour <= 18 && ($h['weighted_score_pct'] ?? 0) > $worstApiScore) {
                    $worstApiScore = $h['weighted_score_pct'] ?? 0;
                    $worstApiHour = $h['iso_time'] ?? null;
                }
            }

            // Step 2: send the same forecast to our safety model
            $model = $this->assessMLSafetyForDate($dateKey, '06:00', '18:00');
            $modelClass = $model['overall_recommendation'] ?? null;

            $classification = $modelClass ?? $apiClass;
            $worstHour = $model['worst_hour']['timestamp'] ?? $worstApiHour;

            $days[$dayNumber] = [
                'date' => $date->format('M d, Y'),
                'classification' => $classification,
                'confidence' => ($daysOut + $dayNumber - 1) >= 4 ? 'low' : 'high',
                'confidence_advisory' => ($daysOut + $dayNumber - 1) >= 4 ? "Confidence is low this far out, recheck in 2 days." : null,
                'recommended_action' => self::MEANING_MAP[$classification] ?? 'Proceed with caution.',
                'worst_hour' => $worstHour ? Carbon::parse($worstHour)->format('g:i A') : 'N/A',
                'lead_time' => max(0, Carbon::now(self::TIMEZONE)->diffInHours($date->copy()->setTime(9, 30), false)) . ' hours',
                'evaluating_engine' => $modelClass ? 'api_forecast_then_model' : 'api_forecast_rules',
                'api_classification' => $apiClass,
                'model_classification' => $modelClass,
                'model_primary_hazard' => $model['worst_hour']['primary_hazard'] ?? null,
                'peak' => $apiPeak && (self::RISK_RANK[$apiPeak['classification']] ?? 0) >= (self::RISK_RANK[$classification] ?? 0) ? $apiPeak : null,
                'seasonal_estimate' => false,
                // Weather readings from the forecast (06:00 - 18:00)
                'wave_height_m' => round((float) ($apiDay['max_wave_height'] ?? 0), 2),
                'current_speed_ms' => round((float) ($apiDay['avg_ocean_current'] ?? 0), 2),
                'wind_speed_kmh' => round((float) ($apiDay['avg_wind_speed'] ?? 0), 1),
                'wind_speed_ms' => round((float) ($apiDay['avg_wind_speed'] ?? 0) / 3.6, 2),
                'wind_gust_kmh' => round((float) ($apiDay['max_wind_speed'] ?? 0), 1),
                'wind_gust_ms' => round((float) ($apiDay['max_wind_speed'] ?? 0) / 3.6, 2),
                'rain_daily_mm' => round((float) ($apiDay['total_rain'] ?? 0), 2),
            ];
        }

        $worseRank = max(self::RISK_RANK[$days[1]['classification']] ?? 1, self::RISK_RANK[$days[2]['classification']] ?? 1);
        $overallClass = array_search($worseRank, self::RISK_RANK) ?: 'Safe';
        $usedModel = $days[1]['model_classification'] !== null || $days[2]['model_classification'] !== null;
        $overallConfidence = $daysOut >= 4 ? 'low' : 'high';

        return [
            'available' => true,
            'historical_replay' => false,
            'historical_replay_label' => null,
            'start_date' => $startDate->format('Y-m-d'),
            'start_date_formatted' => $startDate->format('M d, Y (l)'),
            'end_date' => $endDate->format('Y-m-d'),
            'end_date_formatted' => $endDate->format('M d, Y (l)'),
            'overall_classification' => $overallClass,
            'confidence' => $overallConfidence,
            'confidence_advisory' => $overallConfidence === 'low' ? "Confidence is low this far out, recheck in 2 days." : null,
            'evaluating_engine' => $usedModel ? 'api_forecast_then_model' : 'api_forecast_rules',
            'data_source' => $usedModel
                ? 'Open-Meteo API forecast → Camp FreedivePH safety model'
                : 'Open-Meteo API forecast (rule-based)',
            'days_out' => $daysOut,
            'reliability' => self::getReliabilityCategory($daysOut),
            'day1' => $days[1],
            'day2' => $days[2],
            'advisory_notes' => [
                'Forecast models are updated hourly as the scheduled trip approaches.',
                'Final go/no-go departure decision is subject to Camp FreedivePH operator confirmation.',
            ],
        ];
    }

    /**
     * Get the ML safety result for a date.
     * Always returns one of: Very Safe, Safe, Moderate, High Risk, Critical Risk.
     */
    public function assessMLSafetyForDate(
        string $date,
        string $startTime = '08:00',
        string $endTime = '17:30',
        ?array $overrides = null
    ): ?array {
        try {
            $mlService = app(WeatherSafetyMLService::class);
            if (!$mlService->isEnabled()) {
                return null;
            }

            // Step 1: model input is the Open-Meteo forecast (cache first). Only hours where
            // Open-Meteo has the weather AND the current are sent, so the model service never
            // has to fill a missing current from CMEMS or climatology.
            $hourlyReadings = [];
            $weather = $this->fetchOpenMeteoWeather($date);
            $marine = $this->fetchOpenMeteoMarine($date);
            $times = $weather['time'] ?? $marine['time'] ?? [];

            foreach ($times as $idx => $isoTime) {
                if (!isset($weather['wind_speed_10m'][$idx], $weather['pressure_msl'][$idx], $marine['ocean_current_velocity'][$idx])) {
                    continue;
                }
                $windSpeed = (float) $weather['wind_speed_10m'][$idx];
                $hourlyReadings[] = [
                    'timestamp' => $isoTime,
                    'wind_speed' => $windSpeed,
                    'wind_gust' => (float) ($weather['wind_gusts_10m'][$idx] ?? $windSpeed),
                    'wind_dir' => (float) ($weather['wind_direction_10m'][$idx] ?? 0.0),
                    'slp' => (float) $weather['pressure_msl'][$idx],
                    'rain_rate_mm_hr' => (float) ($weather['rain'][$idx] ?? $weather['precipitation'][$idx] ?? 0.0),
                    'ocean_current_velocity' => (float) $marine['ocean_current_velocity'][$idx] * 0.27778,
                ];
            }

            // If the API is down, use the cached Open-Meteo day forecast (real values only)
            if (empty($hourlyReadings)) {
                $dayForecast = $this->getCachedDayForecast($date);
                foreach ($dayForecast['hourly'] ?? [] as $h) {
                    if (!isset($h['wind_speed'], $h['sea_level_pressure'], $h['ocean_current'])) {
                        continue;
                    }
                    $hourlyReadings[] = [
                        'timestamp' => $h['iso_time'] ?? sprintf('%sT%02d:00:00+08:00', $date, $h['hour'] ?? 12),
                        'wind_speed' => (float) $h['wind_speed'],
                        'wind_gust' => (float) ($h['wind_gusts'] ?? $h['wind_speed']),
                        'wind_dir' => (float) ($h['wind_direction'] ?? 0.0),
                        'slp' => (float) $h['sea_level_pressure'],
                        'rain_rate_mm_hr' => (float) ($h['rain'] ?? 0.0),
                        'ocean_current_velocity' => (float) $h['ocean_current'],
                    ];
                }
            }

            if (empty($hourlyReadings)) {
                return null;
            }

            $boundaryWeather = $mlService->formatBoundaryWeather($hourlyReadings);
            $pagasaPayload = [
                'tcws_signal' => (int) ($overrides['tcws_signal'] ?? 0),
                'gale_warning' => (bool) ($overrides['gale_warning'] ?? false),
                'tsunami_warning' => (bool) ($overrides['tsunami_warning'] ?? false),
            ];

            return $mlService->assessBookingSession($date, $startTime, $endTime, $boundaryWeather, $pagasaPayload);
        } catch (\Throwable $e) {
            Log::info("[WeatherForecastService] ML safety evaluation fallback: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Apply an admin override (like a PAGASA advisory) to a batch.
     */
    public function applyManualOverride(Batch $batch, array $overrideData, User $operator, bool $cancelBatch = false, ?string $cancelReason = null): array
    {
        return DB::transaction(function () use ($batch, $overrideData, $operator, $cancelBatch, $cancelReason) {
            $isOverrideActive = $this->checkOverrideConditions($overrideData);

            $overrideRecord = ManualOverride::create([
                'batch_id' => $batch->id,
                'tcws_signal' => (int) ($overrideData['tcws_signal'] ?? 0),
                'gale_warning' => (bool) ($overrideData['gale_warning'] ?? false),
                'thunderstorm_advisory' => (bool) ($overrideData['thunderstorm_advisory'] ?? false),
                'typhoon_within_distance' => (bool) ($overrideData['typhoon_within_distance'] ?? false),
                'tsunami_warning' => (bool) ($overrideData['tsunami_warning'] ?? false),
                'other_hazard' => ($overrideData['other_hazard'] ?? null) ?: null,
                'reason' => $overrideData['reason'] ?? 'PAGASA Marine Weather Advisory',
                'cancelled_batch' => $cancelBatch,
                'applied_by' => $operator->id,
                'created_at' => now(),
            ]);

            // Assess the batch again with the override
            $assessmentResult = $this->assessBatch($batch, $overrideData, $operator);

            // Cancel the batch if the admin asked for it
            if ($cancelBatch && $isOverrideActive) {
                $reasonText = $cancelReason ?: ($overrideData['reason'] ?: 'Camp cancellation due to active PAGASA severe weather advisory');
                $this->cancelBatchWithRefundsAndNotifications($batch, $reasonText, $operator);
            }

            AuditLogger::log(
                'MANUAL_OVERRIDE_APPLIED',
                "Manual weather override applied to batch {$batch->batch_code}. "
                    . ($isOverrideActive ? 'Critical Risk forced.' : ($this->overrideFloor($overrideData) ? 'At least High Risk.' : 'No forced rating.'))
                    . " Cancelled=" . ($cancelBatch ? 'Yes' : 'No'),
                $operator,
                $operator->name
            );

            return [
                'override' => $overrideRecord,
                'assessment' => $assessmentResult,
            ];
        });
    }

    /**
     * Cancel the whole batch, mark refunds as 100% and email the participants.
     */
    public function cancelBatchWithRefundsAndNotifications(Batch $batch, string $cancellationReason, User $operator): int
    {
        $batch->update([
            'status' => 'cancelled_by_camp',
            'lifecycle_status' => 'cancelled_by_camp',
            'cancelled_at' => now(),
            'cancellation_reason' => $cancellationReason,
        ]);

        BatchStatusLog::create([
            'batch_id' => $batch->id,
            'old_status' => $batch->status,
            'new_status' => 'cancelled_by_camp',
            'changed_by' => $operator->id,
            'note' => "Cancelled by Camp due to weather safety advisory: {$cancellationReason}",
        ]);

        // Skip bookings that are already cancelled or done so nobody gets emailed twice
        $connectedBookings = $batch->bookings()
            ->whereNotIn('status', ['cancelled', 'cancelled_by_guest', 'cancelled_by_camp', 'completed', 'no_show'])
            ->get();

        $notificationsSent = 0;

        foreach ($connectedBookings as $booking) {
            $bookingOldStatus = $booking->status;
            $booking->update(['status' => 'cancelled_by_camp']);

            // Weather cancellation means full refund
            foreach ($booking->payments()->where('status', 'completed')->get() as $payment) {
                RefundRequest::create([
                    'payment_id' => $payment->id,
                    'booking_id' => $booking->id,
                    'requested_by' => 'camp_force_majeure',
                    'requested_at' => now(),
                    'eligibility_calculated' => [
                        'days_until_dive' => max(0, Carbon::now()->diffInDays($booking->start_date, false)),
                        'eligible_for_refund' => true,
                        'refund_percentage' => 100,
                        'window_label' => 'Camp Cancellation (100% Force Majeure Refund)',
                        'policy_action_text' => 'Camp cancelled batch due to marine safety / weather. Full refund approved.',
                    ],
                    'status' => 'pending',
                    'notes' => "Weather cancellation for batch {$batch->batch_code}: {$cancellationReason}",
                ]);
            }

            // Short summary of the email, saved in the notification log
            $scheduledDateStr = $booking->start_date->format('M d, Y') . ' - ' . $booking->end_date->format('M d, Y');
            $paid = (float) $booking->payments()->where('status', 'completed')->sum('amount');
            $messageBody = "Hello {$booking->contact_name}, we cancelled your trip on {$scheduledDateStr} for your safety.\n\nWhy: {$cancellationReason}\n\n"
                . ($paid > 0
                    ? 'A full refund of PHP ' . number_format($paid, 2) . ' has been started. No action needed.'
                    : 'No payment was received yet, so there is nothing to refund.')
                . "\n\nPrefer another date? Reply to this email and we will move the booking for free instead of refunding.";

            NotificationLog::create([
                'batch_id' => $batch->id,
                'booking_id' => $booking->id,
                'recipient_email' => $booking->contact_email,
                'recipient_name' => $booking->contact_name,
                'subject' => "Your dive on {$scheduledDateStr} has been cancelled for your safety",
                'message_body' => $messageBody,
                'channel' => 'email',
                'sent_by' => $operator->id,
                'sent_at' => now(),
            ]);

            // Send the email through the queue so the admin page doesn't have to wait
            try {
                Mail::to($booking->contact_email)->queue(
                    new BatchWeatherCancellationMail($booking, $batch, $cancellationReason)
                );
            } catch (\Throwable $e) {
                Log::warning("Failed to queue weather cancellation email for booking #{$booking->booking_number}: " . $e->getMessage());
            }

            BookingStatusLog::create([
                'booking_id' => $booking->id,
                'old_status' => $bookingOldStatus,
                'new_status' => 'cancelled_by_camp',
                'changed_by' => $operator->id,
                'note' => "Cancelled by Camp due to weather safety. Queued notification to {$booking->contact_email}.",
            ]);

            $notificationsSent++;
        }

        return $notificationsSent;
    }

    /**
     * Check if any override is turned on.
     */
    public function checkOverrideConditions(?array $overrides): bool
    {
        if (empty($overrides)) {
            return false;
        }

        $tcwsSignal = (int) ($overrides['tcws_signal'] ?? 0);
        $galeWarning = (bool) ($overrides['gale_warning'] ?? false);
        $thunderstorm = (bool) ($overrides['thunderstorm_advisory'] ?? false);
        $typhoon = (bool) ($overrides['typhoon_within_distance'] ?? false);
        $tsunami = (bool) ($overrides['tsunami_warning'] ?? false);
        // Non-weather problems (oil spill, red tide, no-sail order, ...) also mean Critical Risk
        $otherHazard = !empty($overrides['other_hazard']);

        return ($tcwsSignal >= 3 || $galeWarning || $thunderstorm || $typhoon || $tsunami || $otherHazard);
    }

    /**
     * Lowest rating an override allows without forcing Critical:
     * PAGASA Signal No. 1-2 = at least High Risk (Signal No. 3+ is Critical above).
     */
    public function overrideFloor(?array $overrides): ?string
    {
        $signal = (int) ($overrides['tcws_signal'] ?? 0);

        return ($signal >= 1 && $signal <= 2) ? 'High Risk' : null;
    }

    /**
     * Check one dive window (e.g. 09:30-12:00 or 15:30-17:30).
     */
    protected function assessWindow(string $plannedDate, string $diveStart, string $diveEnd, string $windowType, ?array $overrides = null): array
    {
        // Uses Open-Meteo data (cached)
        return $this->evaluateWindowNatively($plannedDate, $diveStart, $diveEnd, $windowType, $overrides);
    }

    /**
     * Score a dive window using Open-Meteo data.
     */
    protected function evaluateWindowNatively(string $plannedDate, string $diveStart, string $diveEnd, string $windowType, ?array $overrides): array
    {
        $overrideTriggered = $this->checkOverrideConditions($overrides);
        $overrideFloor = $this->overrideFloor($overrides);

        // AM 09:30-12:00 -> 10:00, 11:00, 12:00
        // PM 15:30-17:30 -> 16:00, 17:00
        $hours = $windowType === 'am' ? [10, 11, 12] : [16, 17];
        
        $hourlyList = [];

        // Get marine and weather data from Open-Meteo
        $marineData = $this->fetchOpenMeteoMarine($plannedDate);
        $weatherData = $this->fetchOpenMeteoWeather($plannedDate);

        $windowWindSpeeds = [];
        $windowWindGusts = [];
        $windowWaveHeights = [];
        $windowWavePeriods = [];
        $windowWindWavePeriods = [];
        $windowSwellPeriods = [];
        $windowSwellHeights = [];
        $windowWindWaveHeights = [];
        $windowOceanCurrents = [];
        $windowRains = [];
        $windowPressures = [];
        $windowWindDirections = [];

        foreach ($hours as $hour) {
            $timeStr = sprintf('%sT%02d:00:00+08:00', $plannedDate, $hour);
            $idx = $hour; // index in the hourly array

            // Skip hours without real Open-Meteo values (sea data stops ~9-10 days ahead)
            if (!isset(
                $marineData['wave_height'][$idx], $marineData['wave_period'][$idx], $marineData['swell_wave_height'][$idx],
                $marineData['wind_wave_height'][$idx], $marineData['ocean_current_velocity'][$idx],
                $weatherData['wind_speed_10m'][$idx], $weatherData['pressure_msl'][$idx]
            )) {
                continue;
            }

            $waveHeight = isset($marineData['wave_height'][$idx]) ? (float)$marineData['wave_height'][$idx] : 0.70;
            $wavePeriod = isset($marineData['wave_period'][$idx]) ? (float)$marineData['wave_period'][$idx] : 6.10;
            $swellHeight = isset($marineData['swell_wave_height'][$idx]) ? (float)$marineData['swell_wave_height'][$idx] : 0.60;
            $windWaveHeight = isset($marineData['wind_wave_height'][$idx]) ? (float)$marineData['wind_wave_height'][$idx] : 0.35;
            $windWavePeriod = isset($marineData['wind_wave_period'][$idx]) ? (float)$marineData['wind_wave_period'][$idx] : null;
            $swellPeriod = isset($marineData['swell_wave_period'][$idx]) ? (float)$marineData['swell_wave_period'][$idx] : null;
            
            // Open-Meteo gives current in km/h, convert to m/s
            $rawCurrent = isset($marineData['ocean_current_velocity'][$idx]) ? (float)$marineData['ocean_current_velocity'][$idx] : 1.1;
            $oceanCurrent = round($rawCurrent * 0.27778, 2);

            $precip = isset($weatherData['precipitation'][$idx]) ? (float)$weatherData['precipitation'][$idx] : 0.0;
            $rawRain = isset($weatherData['rain'][$idx]) ? (float)$weatherData['rain'][$idx] : 0.0;
            $showers = isset($weatherData['showers'][$idx]) ? (float)$weatherData['showers'][$idx] : 0.0;
            $rain = max($precip, $rawRain, $showers);

            $pressure = isset($weatherData['pressure_msl'][$idx]) ? (float)$weatherData['pressure_msl'][$idx] : 1010.5;
            $rawWindSpeed = isset($weatherData['wind_speed_10m'][$idx]) ? (float)$weatherData['wind_speed_10m'][$idx] : 12.5;
            $windGusts = isset($weatherData['wind_gusts_10m'][$idx]) ? (float)$weatherData['wind_gusts_10m'][$idx] : 0.0;
            $windDir = isset($weatherData['wind_direction_10m'][$idx]) ? (float)$weatherData['wind_direction_10m'][$idx] : 245.0;

            $windowWindSpeeds[] = $rawWindSpeed;
            $windowWindGusts[] = $windGusts;
            $windowWaveHeights[] = $waveHeight;
            $windowWavePeriods[] = $wavePeriod;
            $windowWindWavePeriods[] = $windWavePeriod;
            $windowSwellPeriods[] = $swellPeriod;
            $windowSwellHeights[] = $swellHeight;
            $windowWindWaveHeights[] = $windWaveHeight;
            $windowOceanCurrents[] = $oceanCurrent;
            $windowRains[] = $rain;
            $windowPressures[] = $pressure;
            $windowWindDirections[] = $windDir;

            // Score per hour
            $hourScores = [
                'wave_height' => $this->scoreWaveHeight($waveHeight),
                'wind_speed' => $this->scoreWindSpeed($rawWindSpeed, $windGusts),
                'ocean_current' => $this->scoreOceanCurrent($oceanCurrent),
                'swell_height' => $this->scoreSwellHeight($swellHeight),
                'wave_period' => $this->scoreWavePeriods($wavePeriod, $windWavePeriod, $windWaveHeight, $swellPeriod, $swellHeight),
                'wind_wave_height' => $this->scoreWindWaveHeight($windWaveHeight),
                'rain' => $this->scoreRain($rain),
                'sea_level_pressure' => $this->scoreSeaLevelPressure($pressure),
                'wind_direction' => $this->scoreWindDirection($windDir),
            ];
            $hourWeightedPct = $this->computeWeightedScore($hourScores);
            $hourClass = $this->raiseToFloor($this->classifyScores($hourScores, $hourWeightedPct), $overrideFloor);

            $hourlyList[] = [
                'forecast_time' => Carbon::parse($timeStr),
                'wave_height' => $waveHeight,
                'wave_period' => $wavePeriod,
                'swell_height' => $swellHeight,
                'wind_wave_height' => $windWaveHeight,
                'ocean_current' => $oceanCurrent,
                'rain' => $rain,
                'sea_level_pressure' => $pressure,
                'wind_speed' => $rawWindSpeed,
                'wind_gusts' => $windGusts,
                'wind_direction' => $windDir,
                'weighted_score_pct' => $overrideTriggered ? null : $hourWeightedPct,
                'classification' => $overrideTriggered ? 'Critical Risk' : $hourClass,
                'recommended_action' => self::MEANING_MAP[$overrideTriggered ? 'Critical Risk' : $hourClass] ?? 'Proceed with caution.',
                'is_worst_hour_in_window' => false,
            ];
        }

        // No real data for any hour of the window: no rating (an override still forces Critical)
        if (empty($hourlyList)) {
            return [
                'planned_date' => $plannedDate,
                'window_type' => $windowType,
                'dive_start' => $diveStart,
                'dive_end' => $diveEnd,
                'classification' => $overrideTriggered ? 'Critical Risk' : 'Not Available',
                'weighted_score_pct' => null,
                'sustained_wind_speed' => null,
                'max_wind_gust' => null,
                'mean_wave_height' => null,
                'mean_ocean_current' => null,
                'worst_hour' => null,
                'hourly' => [],
            ];
        }

        $count = count($hourlyList);
        $meanWindSpeed = $count ? array_sum($windowWindSpeeds) / $count : 12.0;
        $maxWindGust = !empty($windowWindGusts) ? max($windowWindGusts) : 0.0;
        $meanWaveHeight = $count ? array_sum($windowWaveHeights) / $count : 0.70;
        $meanSwellHeight = $count ? array_sum($windowSwellHeights) / $count : 0.60;
        $meanOceanCurrent = $count ? array_sum($windowOceanCurrents) / $count : 0.20;
        $totalRain = array_sum($windowRains);
        $maxRainRate = !empty($windowRains) ? max($windowRains) : 0.0;
        $meanPressure = $count ? array_sum($windowPressures) / $count : 1010.5;
        $meanWavePeriod = $count ? array_sum($windowWavePeriods) / $count : 6.0;
        $meanWindWaveHeight = $count ? array_sum($windowWindWaveHeights) / $count : 0.35;
        $meanWindDirection = $count ? array_sum($windowWindDirections) / $count : 245.0;

        // Hard limits for the window (any one of these = Critical Risk):
        // 1. Wind >= 42 km/h (10-min average)
        // 2. Gust >= 48 km/h
        // 3. Waves >= 1.8 m (30-min average)
        // 4. Swell >= 1.8 m (30-min average)
        // 5. Current >= 0.8 m/s (10-min average)
        // 6. Rain >= 25 mm total or >= 25 mm/hr
        // 7. Pressure <= 998 hPa or drops 2 hPa or more in 3 hours
        $isPhysicalBreach = (
            $meanWindSpeed >= 42.0 ||
            $maxWindGust >= 48.0 ||
            $meanWaveHeight >= 1.80 ||
            $meanSwellHeight >= 1.80 ||
            $meanOceanCurrent >= 0.80 ||
            $totalRain >= 25.0 ||
            $maxRainRate >= 25.0 ||
            $meanPressure <= 998.0
        );

        $windowScores = [
            'wave_height' => $this->scoreWaveHeight($meanWaveHeight),
            'wind_speed' => $this->scoreWindSpeed($meanWindSpeed, $maxWindGust),
            'ocean_current' => $this->scoreOceanCurrent($meanOceanCurrent),
            'swell_height' => $this->scoreSwellHeight($meanSwellHeight),
            'wave_period' => $this->scoreWavePeriods($meanWavePeriod, $this->meanOrNull($windowWindWavePeriods), $meanWindWaveHeight, $this->meanOrNull($windowSwellPeriods), $meanSwellHeight),
            'wind_wave_height' => $this->scoreWindWaveHeight($meanWindWaveHeight),
            'rain' => $this->scoreRain($maxRainRate),
            'sea_level_pressure' => $this->scoreSeaLevelPressure($meanPressure),
            'wind_direction' => $this->scoreWindDirection($meanWindDirection),
        ];

        $windowWeightedScorePct = $isPhysicalBreach ? 100.0 : $this->computeWeightedScore($windowScores);
        $windowClass = ($overrideTriggered || $isPhysicalBreach)
            ? 'Critical Risk'
            : $this->raiseToFloor($this->classifyScores($windowScores, $windowWeightedScorePct), $overrideFloor);

        // Find the worst hour in the window
        $worstScore = -1;
        $worstHour = null;
        foreach ($hourlyList as $item) {
            $s = $item['weighted_score_pct'] ?? 0;
            if ($s > $worstScore) {
                $worstScore = $s;
                $worstHour = $item['forecast_time']->format('Y-m-d\TH:i:sP');
            }
        }

        foreach ($hourlyList as &$item) {
            if ($item['forecast_time']->format('Y-m-d\TH:i:sP') === $worstHour || count($hourlyList) === 1) {
                $item['is_worst_hour_in_window'] = true;
            }
        }

        return [
            'planned_date' => $plannedDate,
            'window_type' => $windowType,
            'dive_start' => $diveStart,
            'dive_end' => $diveEnd,
            'classification' => $windowClass,
            'weighted_score_pct' => $overrideTriggered ? null : $windowWeightedScorePct,
            'sustained_wind_speed' => round($meanWindSpeed, 1),
            'max_wind_gust' => round($maxWindGust, 1),
            'mean_wave_height' => round($meanWaveHeight, 2),
            'mean_ocean_current' => round($meanOceanCurrent, 2),
            'worst_hour' => $worstHour,
            'hourly' => $hourlyList,
        ];
    }

    /**
     * Get 16 days of hourly marine and weather forecast from Open-Meteo
     * and save it in the cache.
     */
    public function updateAllForecasts(int $forecastDays = 16): array
    {
        $cachedContinuous = Cache::get('forecast:continuous_16d');

        // 1. Marine forecast (16 days, one API call)
        try {
            $marineRes = $this->apiClient->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine', [
                'query' => [
                    'latitude' => config('forecast.site_lat', self::LATITUDE),
                    'longitude' => config('forecast.site_lon', self::LONGITUDE),
                    'timezone' => self::TIMEZONE,
                    'forecast_days' => $forecastDays,
                    'hourly' => 'wave_height,wave_period,swell_wave_height,wind_wave_height,ocean_current_velocity,swell_wave_period,wind_wave_period',
                ],
                'timeout' => 10,
                'without_verifying' => true,
            ]);
            $marineHourly = $marineRes->successful() ? ($marineRes->json()['hourly'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('Open-Meteo marine fetch warning: ' . $e->getMessage());
            $marineHourly = [];
        }

        // 2. Weather forecast (16 days, one API call)
        try {
            $weatherRes = $this->apiClient->execute('open_meteo', 'GET', 'https://api.open-meteo.com/v1/forecast', [
                'query' => [
                    'latitude' => config('forecast.site_lat', self::LATITUDE),
                    'longitude' => config('forecast.site_lon', self::LONGITUDE),
                    'timezone' => self::TIMEZONE,
                    // Use forecast_days, not start/end dates. Open-Meteo checks dates in UTC,
                    // so a Manila end_date gets a 400 error between 12 AM and 8 AM PH time.
                    'forecast_days' => $forecastDays,
                    'hourly' => 'precipitation,rain,showers,pressure_msl,wind_speed_10m,wind_gusts_10m,wind_direction_10m',
                ],
                'timeout' => 10,
                'without_verifying' => true,
            ]);
            $weatherHourly = $weatherRes->successful() ? ($weatherRes->json()['hourly'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('Open-Meteo weather fetch warning: ' . $e->getMessage());
            $weatherHourly = [];
        }

        if (empty($marineHourly) && empty($weatherHourly)) {
            if (!empty($cachedContinuous) && is_array($cachedContinuous)) {
                Log::info('Serving stale 16-day continuous forecast from cache due to external provider unavailability.');
                return $cachedContinuous;
            }
            throw new Exception("Unable to communicate with Open-Meteo forecast servers.");
        }

        // 3. Group the hours by day
        $times = $marineHourly['time'] ?? $weatherHourly['time'] ?? [];
        $dayBuckets = [];
        $dailySummaries = [];

        foreach ($times as $index => $isoTime) {
            $dt = Carbon::parse($isoTime);
            $dateKey = $dt->format('Y-m-d');
            $hour = $dt->hour;

            if (!isset($dayBuckets[$dateKey])) {
                $dayBuckets[$dateKey] = [
                    'date' => $dateKey,
                    'marine' => [
                        'time' => [],
                        'wave_height' => [],
                        'wave_period' => [],
                        'swell_wave_period' => [],
                        'wind_wave_period' => [],
                        'swell_wave_height' => [],
                        'wind_wave_height' => [],
                        'ocean_current_velocity' => [],
                    ],
                    'weather' => [
                        'time' => [],
                        'rain' => [],
                        'pressure_msl' => [],
                        'wind_speed_10m' => [],
                        'wind_gusts_10m' => [],
                        'wind_direction_10m' => [],
                    ],
                    'hourly_scores' => [],
                ];
            }

            // Open-Meteo leaves the sea values empty past its marine range (~9-10 days ahead).
            // Missing values stay null, so nothing is rated on made-up numbers.
            $num = fn (array $series, string $key) => isset($series[$key][$index]) ? (float) $series[$key][$index] : null;

            // Marine values
            $dayBuckets[$dateKey]['marine']['time'][] = $isoTime;
            $wHeight = $num($marineHourly, 'wave_height');
            $wPeriod = $num($marineHourly, 'wave_period');
            $sHeight = $num($marineHourly, 'swell_wave_height');
            $wwHeight = $num($marineHourly, 'wind_wave_height');
            $rawCurrent = $num($marineHourly, 'ocean_current_velocity');
            $swPeriod = $num($marineHourly, 'swell_wave_period');
            $wwPeriod = $num($marineHourly, 'wind_wave_period');
            $hasMarine = $wHeight !== null && $wPeriod !== null && $sHeight !== null && $wwHeight !== null && $rawCurrent !== null;

            $dayBuckets[$dateKey]['marine']['wave_height'][] = $wHeight;
            $dayBuckets[$dateKey]['marine']['wave_period'][] = $wPeriod;
            $dayBuckets[$dateKey]['marine']['swell_wave_period'][] = $swPeriod;
            $dayBuckets[$dateKey]['marine']['wind_wave_period'][] = $wwPeriod;
            $dayBuckets[$dateKey]['marine']['swell_wave_height'][] = $sHeight;
            $dayBuckets[$dateKey]['marine']['wind_wave_height'][] = $wwHeight;
            $dayBuckets[$dateKey]['marine']['ocean_current_velocity'][] = $rawCurrent;

            // Weather values
            $dayBuckets[$dateKey]['weather']['time'][] = $isoTime;
            $rawWindSpd = $num($weatherHourly, 'wind_speed_10m');
            $pressVal = $num($weatherHourly, 'pressure_msl');
            $hasWeather = $rawWindSpd !== null && $pressVal !== null;
            $rainVal = $hasWeather ? max($num($weatherHourly, 'precipitation') ?? 0.0, $num($weatherHourly, 'rain') ?? 0.0, $num($weatherHourly, 'showers') ?? 0.0) : null;
            $windGustsVal = $num($weatherHourly, 'wind_gusts_10m') ?? $rawWindSpd;
            $windSpd = $rawWindSpd === null ? null : max($rawWindSpd, ($windGustsVal ?? 0.0) * 0.75);
            $windDir = $num($weatherHourly, 'wind_direction_10m');

            $dayBuckets[$dateKey]['weather']['rain'][] = $rainVal;
            $dayBuckets[$dateKey]['weather']['pressure_msl'][] = $pressVal;
            $dayBuckets[$dateKey]['weather']['wind_speed_10m'][] = $windSpd;
            $dayBuckets[$dateKey]['weather']['wind_gusts_10m'][] = $windGustsVal;
            $dayBuckets[$dateKey]['weather']['wind_direction_10m'][] = $windDir;

            // Score for this hour (0-100%)
            $oceanCurrent = $rawCurrent === null ? null : round($rawCurrent * 0.27778, 2);

            // Hard limits (Coast Guard small boat limits). The weather ones still count when
            // Open-Meteo has no sea data for the hour.
            $isWeatherBreach = $hasWeather && (
                $rawWindSpd >= 38.0 || $windGustsVal >= 48.0 ||
                $rainVal >= 25.0 ||
                $pressVal <= 998.0
            );
            $isPhysicalBreach = $isWeatherBreach || ($hasMarine && (
                $wHeight >= 1.80 || $sHeight >= 1.80 ||
                $oceanCurrent >= 0.80
            ));

            $scores = !($hasMarine && $hasWeather) ? null : [
                'wave_height' => $this->scoreWaveHeight($wHeight),
                'wind_speed' => $this->scoreWindSpeed($rawWindSpd, $windGustsVal),
                'ocean_current' => $this->scoreOceanCurrent($oceanCurrent),
                'swell_height' => $this->scoreSwellHeight($sHeight),
                'wave_period' => $this->scoreWavePeriods($wPeriod, $wwPeriod, $wwHeight, $swPeriod, $sHeight),
                'wind_wave_height' => $this->scoreWindWaveHeight($wwHeight),
                'rain' => $this->scoreRain($rainVal),
                'sea_level_pressure' => $this->scoreSeaLevelPressure($pressVal),
                'tide_height' => 0,
                'wind_direction' => $this->scoreWindDirection($windDir ?? 0.0),
            ];

            if ($isPhysicalBreach) {
                $weightedScorePct = 100.0;
                $hourClass = 'Critical Risk';
            } elseif ($scores === null) {
                $weightedScorePct = null;
                $hourClass = 'Not Available';
            } else {
                $weightedScorePct = $this->computeWeightedScore($scores);
                $hourClass = $this->classifyScores($scores, $weightedScorePct);
            }

            $dayBuckets[$dateKey]['hourly_scores'][$hour] = [
                'hour' => $hour,
                'iso_time' => $isoTime,
                'weighted_score_pct' => $weightedScorePct,
                'classification' => $hourClass,
                'wave_height' => $wHeight,
                'wave_period' => $wPeriod,
                'swell_height' => $sHeight,
                'ocean_current' => $oceanCurrent,
                'wind_wave_height' => $wwHeight,
                'rain' => $rainVal,
                'sea_level_pressure' => $pressVal,
                'wind_speed' => $rawWindSpd,
                'wind_gusts' => $windGustsVal,
                'wind_direction' => $windDir,
                'tide_height' => 0.0,
                'sea_data_available' => $hasMarine,
            ];
        }

        // 4. Save each day in the cache with a summary
        $snapshotRows = [];
        foreach ($dayBuckets as $dateKey => $bucket) {
            Cache::put("forecast:marine_cache:{$dateKey}", $bucket['marine'], now()->addMinutes(60));
            Cache::put("forecast:weather_cache:{$dateKey}", $bucket['weather'], now()->addMinutes(60));

            // Daytime values (06:00 - 18:00)
            $daytimeHours = range(6, 18);
            $daytimeWinds = [];
            $daytimeGusts = [];
            $daytimeWaves = [];
            $daytimeSwells = [];
            $daytimeCurrents = [];
            $daytimeRains = [];
            $daytimePressures = [];
            $daytimePeriods = [];
            $daytimeWindWavePeriods = [];
            $daytimeSwellPeriods = [];
            $daytimeWindWaves = [];
            $daytimeWindDirs = [];

            // Only hours Open-Meteo really has: weather and sea values are collected separately
            foreach ($daytimeHours as $dh) {
                if (isset($bucket['weather']['wind_speed_10m'][$dh], $bucket['weather']['pressure_msl'][$dh])) {
                    $daytimeWinds[] = $bucket['weather']['wind_speed_10m'][$dh];
                    $daytimeGusts[] = $bucket['weather']['wind_gusts_10m'][$dh] ?? $bucket['weather']['wind_speed_10m'][$dh];
                    $daytimeRains[] = $bucket['weather']['rain'][$dh] ?? 0.0;
                    $daytimePressures[] = $bucket['weather']['pressure_msl'][$dh];
                    $daytimeWindDirs[] = $bucket['weather']['wind_direction_10m'][$dh] ?? 0.0;
                }
                $m = $bucket['marine'];
                if (isset($m['wave_height'][$dh], $m['swell_wave_height'][$dh], $m['ocean_current_velocity'][$dh], $m['wave_period'][$dh], $m['wind_wave_height'][$dh])) {
                    $daytimeWaves[] = $m['wave_height'][$dh];
                    $daytimeSwells[] = $m['swell_wave_height'][$dh];
                    $daytimeCurrents[] = $m['ocean_current_velocity'][$dh] * 0.27778;
                    $daytimePeriods[] = $m['wave_period'][$dh];
                    $daytimeWindWavePeriods[] = $m['wind_wave_period'][$dh] ?? null;
                    $daytimeSwellPeriods[] = $m['swell_wave_period'][$dh] ?? null;
                    $daytimeWindWaves[] = $m['wind_wave_height'][$dh];
                }
            }

            $weatherAvailable = !empty($daytimeWinds);
            $seaAvailable = !empty($daytimeWaves);
            $dayCount = count($daytimeWinds) ?: 1;
            $seaCount = count($daytimeWaves) ?: 1;
            $meanDaytimeWind = array_sum($daytimeWinds) / $dayCount;
            $maxDaytimeGust = !empty($daytimeGusts) ? max($daytimeGusts) : 0.0;
            $meanDaytimeWave = array_sum($daytimeWaves) / $seaCount;
            $meanDaytimeSwell = array_sum($daytimeSwells) / $seaCount;
            $meanDaytimeCurrent = array_sum($daytimeCurrents) / $seaCount;
            $daytimeRainTotal = array_sum($daytimeRains);
            $daytimeMaxRainRate = !empty($daytimeRains) ? max($daytimeRains) : 0.0;
            $meanDaytimePressure = $weatherAvailable ? array_sum($daytimePressures) / $dayCount : null;
            $meanDaytimePeriod = array_sum($daytimePeriods) / $seaCount;
            $meanDaytimeWindWave = array_sum($daytimeWindWaves) / $seaCount;
            $meanDaytimeWindDir = array_sum($daytimeWindDirs) / $dayCount;

            // A fast pressure drop (>= 2.5 hPa in 3h) only counts if strong gusts (>= 38 km/h)
            // or heavy rain (>= 15 mm/hr) happen in the SAME 3-hour window. The normal midday
            // pressure dip (~2.5-3 hPa) plus a breezy hour at another time is not a squall.
            $hasCompoundPressureBreach = false;
            for ($i = 0; $i < count($daytimePressures) - 3; $i++) {
                if ($daytimePressures[$i] - $daytimePressures[$i + 3] < 2.5) {
                    continue;
                }
                $windowGust = max(array_slice($daytimeGusts, $i, 4));
                $windowRain = max(array_slice($daytimeRains, $i, 4));
                if ($windowGust >= 38.0 || $windowRain >= 15.0) {
                    $hasCompoundPressureBreach = true;
                    break;
                }
            }

            // Hard limits (Coast Guard small boat limits). The weather ones still count without sea data.
            $isDaytimePhysicalBreach = ($weatherAvailable && (
                $meanDaytimeWind >= 42.0 ||
                $maxDaytimeGust >= 48.0 ||
                $daytimeRainTotal >= 25.0 ||
                $daytimeMaxRainRate >= 25.0 ||
                $meanDaytimePressure <= 998.0 ||
                $hasCompoundPressureBreach
            )) || ($seaAvailable && (
                $meanDaytimeWave >= 1.80 ||
                $meanDaytimeSwell >= 1.80 ||
                $meanDaytimeCurrent >= 0.80
            ));

            $daytimeScores = [
                'wave_height' => $this->scoreWaveHeight($meanDaytimeWave),
                'wind_speed' => $this->scoreWindSpeed($meanDaytimeWind, $maxDaytimeGust),
                'ocean_current' => $this->scoreOceanCurrent($meanDaytimeCurrent),
                'swell_height' => $this->scoreSwellHeight($meanDaytimeSwell),
                'wave_period' => $this->scoreWavePeriods($meanDaytimePeriod, $this->meanOrNull($daytimeWindWavePeriods), $meanDaytimeWindWave, $this->meanOrNull($daytimeSwellPeriods), $meanDaytimeSwell),
                'wind_wave_height' => $this->scoreWindWaveHeight($meanDaytimeWindWave),
                'rain' => $this->scoreRain($daytimeMaxRainRate),
                'sea_level_pressure' => $this->scoreSeaLevelPressure($meanDaytimePressure ?? 1013.0),
                'wind_direction' => $this->scoreWindDirection($meanDaytimeWindDir),
            ];

            if ($isDaytimePhysicalBreach) {
                $daytimeScorePct = 100.0;
                $daytimeClass = 'Critical Risk';
            } elseif (!$seaAvailable || !$weatherAvailable) {
                // No real sea (or weather) data from Open-Meteo for this day: no rating
                $daytimeScorePct = null;
                $daytimeClass = 'Not Available';
            } else {
                $daytimeScorePct = $this->computeWeightedScore($daytimeScores);
                $daytimeClass = $this->classifyScores($daytimeScores, $daytimeScorePct);
            }

            // B: 2+ hours in a row at Moderate or worse lift the whole day to at least that level
            // A: the roughest single hour, shown next to the rating when it is rougher than the day
            [$daytimeClass, $sustained, $peak] = $this->applySustainedAndPeak($bucket['hourly_scores'], $daytimeClass);

            $amScores = array_filter($bucket['hourly_scores'], fn($i) => in_array($i['hour'], [10, 11, 12]));
            $amClass = $this->worstClass(array_column($amScores, 'classification'), 'Not Available');

            $pmScores = array_filter($bucket['hourly_scores'], fn($i) => in_array($i['hour'], [16, 17]));
            $pmClass = $this->worstClass(array_column($pmScores, 'classification'), 'Not Available');

            $summary = [
                'date' => $dateKey,
                'overall_classification' => $daytimeClass,
                'overall_score_pct' => $daytimeScorePct,
                'daytime_classification' => $daytimeClass,
                'daytime_score_pct' => $daytimeScorePct,
                'am_classification' => $amClass,
                'pm_classification' => $pmClass,
                'avg_wave_height' => $seaAvailable ? round($meanDaytimeWave, 2) : null,
                'max_wave_height' => $seaAvailable ? max($daytimeWaves) : null,
                'avg_wind_speed' => $weatherAvailable ? round($meanDaytimeWind, 1) : null,
                'max_wind_speed' => $weatherAvailable ? round($maxDaytimeGust, 1) : null,
                'avg_ocean_current' => $seaAvailable ? round($meanDaytimeCurrent, 2) : null,
                'total_rain' => $weatherAvailable ? round($daytimeRainTotal, 2) : null,
                'avg_pressure' => $weatherAvailable ? round($meanDaytimePressure, 1) : null,
                'sea_data_available' => $seaAvailable,
                'sustained' => $sustained,
                'peak' => $peak,
                'weather_data_available' => $weatherAvailable,
                'hourly' => $bucket['hourly_scores'],
            ];

            Cache::put("forecast:date:{$dateKey}", $summary, now()->addMinutes(60));
            $dailySummaries[$dateKey] = $summary;

            // Forecast snapshot for later accuracy checks (saved below in one query).
            // Days without real Open-Meteo data are skipped so they can't skew the accuracy history.
            if (!$seaAvailable || !$weatherAvailable) {
                continue;
            }
            $daysOut = max(0, (int) Carbon::now(self::TIMEZONE)->startOfDay()->diffInDays(Carbon::parse($dateKey)->startOfDay(), false));
            $snapshotRows[] = $this->forecastSnapshotRow($dateKey, $daysOut, $summary);
        }

        // Save all snapshots in one query instead of two per day
        if (!empty($snapshotRows)) {
            try {
                ForecastSnapshot::upsert(
                    $snapshotRows,
                    ['target_date', 'lead_time_days'],
                    array_values(array_diff(array_keys($snapshotRows[0]), ['target_date', 'lead_time_days', 'created_at']))
                );
            } catch (\Throwable $e) {
                Log::debug('Could not record forecast snapshots: ' . $e->getMessage());
            }
        }

        // 5. Save the full 16-day cache and the update time
        $masterData = [
            'updated_at' => now(self::TIMEZONE)->toIso8601String(),
            'days_cached' => count($dailySummaries),
            'daily_summaries' => $dailySummaries,
            'source' => 'Open-Meteo Best Match (ECMWF + GFS + CMEMS)',
        ];

        Cache::put('forecast:continuous_16d', $masterData, now()->addMinutes(60));
        Cache::put('forecast:last_updated_at', now(self::TIMEZONE)->toDateTimeString(), now()->addMinutes(60));

        return $masterData;
    }

    /**
     * Get the cached forecast for a date if we have it.
     * When FORECAST_SOURCE=prd it reads from forecast_daily and forecast_hourly.
     */
    public function getCachedDayForecast(Carbon|string $date): ?array
    {
        $dateStr = is_string($date) ? $date : $date->format('Y-m-d');
        $source = config('forecast.forecast_source', 'prd');

        if ($source === 'legacy') {
            return Cache::get("forecast:date:{$dateStr}");
        }

        $daily = ForecastDaily::where('forecast_date', $dateStr)
            ->orderByDesc('issued_at')
            ->first();

        if (!$daily) {
            $diffDays = (int) Carbon::today(self::TIMEZONE)->diffInDays(Carbon::parse($dateStr), false);
            if ($diffDays <= 16) {
                $requiredDays = min(max(10, $diffDays + 2), 16);
                $siteForecast = $this->forecastSite(days: $requiredDays);
            } else {
                // Date is far away: run forecastSite for that date
                $targetIssued = Carbon::parse($dateStr)->copy()->startOfDay()->subHours(6)->toIso8601String();
                $siteForecast = $this->forecastSite(issuedAt: $targetIssued, days: 3);
            }
            $daily = ForecastDaily::where('forecast_date', $dateStr)
                ->orderByDesc('issued_at')
                ->first();
            if (!$daily) {
                return null;
            }
        }

        $hourly = ForecastHourly::where('forecast_daily_id', $daily->id)
            ->orWhere(function ($q) use ($dateStr) {
                $q->whereDate('forecast_time', $dateStr);
            })
            ->orderBy('forecast_time')
            ->get();

        $hourlyArray = $hourly->map(function ($h) {
            $arr = $h->toArray();
            $arr['iso_time'] = $h->forecast_time ? Carbon::parse($h->forecast_time)->toIso8601String() : '';
            $arr['classification'] = $h->tier ?? 'Safe';
            $eval = $this->calculateTierAndLabel([
                'hs' => [
                    'source' => $h->hs_source ?? 'climatology',
                    'p50' => (float) ($h->hs_p50 ?? 0),
                    'p90' => (float) ($h->hs_p90 ?? 0),
                ],
                'current_speed' => [
                    'source' => $h->current_speed_source ?? 'climatology',
                    'p50' => (float) ($h->current_speed_p50 ?? 0),
                    'p90' => (float) ($h->current_speed_p90 ?? 0),
                ],
                'wind_speed' => [
                    'raw_si_unit' => 'm/s',
                    'p50' => (float) ($h->wind_speed_p50 ?? 0),
                ],
                'wind_gust' => [
                    'raw_si_unit' => 'm/s',
                    'p50' => (float) ($h->wind_gust_p50 ?? 0),
                ],
                'tp' => ['p50' => (float) ($h->tp_p50 ?? 6.0)],
                'swell_height' => ['p50' => (float) ($h->swell_height_p50 ?? 0)],
                'wind_wave_height' => ['p50' => (float) ($h->wind_wave_height_p50 ?? 0)],
                'slp' => ['p50' => (float) ($h->slp_p50 ?? 1011.0)],
                'wind_dir_circ_mean_deg' => (float) ($h->wind_dir_circ_mean_deg ?? 45.0),
            ]);
            $arr['score_details'] = $eval['score_details'];
            $arr['weighted_score_pct'] = $eval['score_details']['weighted_score_pct'];
            return $arr;
        })->toArray();

        return [
            'date' => $dateStr,
            'daily' => $daily->toArray(),
            'hourly' => $hourlyArray,
            'overall_classification' => $daily->daily_tier,
            'day_tier' => $daily->daily_tier,
            'day_label' => $daily->daily_label,
            'rain_daily_mm' => $daily->rain_daily_mm_p50,
            'rain_score' => $daily->rain_score,
            'rain_label' => $daily->rain_label,
            'p_wet' => $daily->p_wet,
            'p_wet_ci' => [$daily->p_wet_ci_lo, $daily->p_wet_ci_hi],
            'p_high_gust' => $daily->p_high_gust,
            'p_high_gust_ci' => [$daily->p_high_gust_ci_lo, $daily->p_high_gust_ci_hi],
        ];
    }

    /**
     * Get the Open-Meteo marine forecast (cache first).
     */
    protected function fetchOpenMeteoMarine(string $date): array
    {
        $cached = Cache::get("forecast:marine_cache:{$date}");
        if (!empty($cached)) {
            return $cached;
        }

        try {
            $res = $this->apiClient->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine', [
                'query' => [
                    'latitude' => config('forecast.site_lat', self::LATITUDE),
                    'longitude' => config('forecast.site_lon', self::LONGITUDE),
                    'timezone' => self::TIMEZONE,
                    'start_date' => $date,
                    'end_date' => $date,
                    'hourly' => 'wave_height,wave_period,swell_wave_height,wind_wave_height,ocean_current_velocity,swell_wave_period,wind_wave_period',
                ],
                'timeout' => 8,
                'without_verifying' => true,
            ]);

            if ($res->successful()) {
                $data = $res->json()['hourly'] ?? [];
                $cacheMins = (int) config('external_apis.open_meteo.single_date_cache_ttl_minutes', 60);
                Cache::put("forecast:marine_cache:{$date}", $data, now()->addMinutes($cacheMins));
                return $data;
            }
        } catch (Exception $e) {
            Log::warning("Open-Meteo marine call failed: " . $e->getMessage());
        }

        return [];
    }

    /**
     * Get the Open-Meteo weather forecast (cache first).
     */
    protected function fetchOpenMeteoWeather(string $date): array
    {
        $cached = Cache::get("forecast:weather_cache:{$date}");
        if (!empty($cached)) {
            return $cached;
        }

        try {
            $res = $this->apiClient->execute('open_meteo', 'GET', 'https://api.open-meteo.com/v1/forecast', [
                'query' => [
                    'latitude' => config('forecast.site_lat', self::LATITUDE),
                    'longitude' => config('forecast.site_lon', self::LONGITUDE),
                    'timezone' => self::TIMEZONE,
                    'start_date' => $date,
                    'end_date' => $date,
                    'hourly' => 'precipitation,rain,showers,pressure_msl,wind_speed_10m,wind_gusts_10m,wind_direction_10m',
                ],
                'timeout' => 8,
                'without_verifying' => true,
            ]);

            if ($res->successful()) {
                $data = $res->json()['hourly'] ?? [];
                $cacheMins = (int) config('external_apis.open_meteo.single_date_cache_ttl_minutes', 60);
                Cache::put("forecast:weather_cache:{$date}", $data, now()->addMinutes($cacheMins));
                return $data;
            }
        } catch (Exception $e) {
            Log::warning("Open-Meteo weather call failed: " . $e->getMessage());
        }

        return [];
    }

    // ---------------------------------------------------------------
    // Scoring functions (each returns 0-4)
    // ---------------------------------------------------------------

    protected function scoreWaveHeight(float $v): int
    {
        if ($v < 0.30) return 0;
        if ($v < 0.50) return 1;
        if ($v < 0.80) return 2;
        if ($v < 1.00) return 3;
        return 4;
    }

    protected function scoreSwellHeight(float $v): int
    {
        return $this->scoreWaveHeight($v);
    }

    /**
     * Wave period has two different hazards (expert survey):
     * - short-period chop from local wind: Coach LC, "< 3 s feels rough even on low waves" -> wind_wave_period,
     *   counted once the wind waves reach 0.2 m (the first wind-wave band). Open-Meteo's wind-wave
     *   period at the site is ~1.5-2 s even on flat days, so without this every day scored as chop.
     * - long-period swell carries more energy: coastal engineer, "> 10 s critical" -> swell_wave_period,
     *   counted only once the swell is big enough to feel (>= 0.3 m, the first wave band)
     * The worse of the two is used, keeping the single wave_period weight. If Open-Meteo has no
     * wind-wave period, the combined wave_period is used for the chop.
     */
    protected function scoreWavePeriods(float $wavePeriod, ?float $windWavePeriod, float $windWaveHeight, ?float $swellPeriod, float $swellHeight): int
    {
        $chop = $windWavePeriod === null
            ? $this->scoreWavePeriod($wavePeriod)
            : ($windWaveHeight >= 0.20 ? $this->scoreWavePeriod($windWavePeriod) : 0);

        return max($chop, $this->scoreSwellPeriod($swellPeriod, $swellHeight));
    }

    /** Coastal engineer bands: < 2 s, 2-4 s, 4-7 s, 7-10 s, > 10 s. */
    protected function scoreSwellPeriod(?float $period, float $swellHeight): int
    {
        if ($period === null || $swellHeight < 0.30) return 0;
        if ($period < 2.0) return 0;
        if ($period < 4.0) return 1;
        if ($period < 7.0) return 2;
        if ($period <= 10.0) return 3;
        return 4;
    }

    private function meanOrNull(array $values): ?float
    {
        $values = array_filter($values, fn ($v) => $v !== null);

        return $values ? array_sum($values) / count($values) : null;
    }

    protected function scoreWindWaveHeight(float $v): int
    {
        if ($v < 0.20) return 0;
        if ($v < 0.40) return 1;
        if ($v < 0.60) return 2;
        if ($v < 0.80) return 3;
        return 4;
    }

    protected function scoreWavePeriod(float $v): int
    {
        if ($v > 7.0) return 0;
        if ($v > 5.0) return 1;
        if ($v > 3.0) return 2;
        if ($v > 2.0) return 3;
        return 4;
    }

    protected function scoreOceanCurrent(float $v): int
    {
        if ($v < 0.10) return 0;
        if ($v < 0.30) return 1;
        if ($v < 0.50) return 2;
        if ($v < 0.80) return 3;
        return 4;
    }

    protected function scoreWindSpeed(float $sustained, float $gusts): int
    {
        // Wind and gusts (Coast Guard small boat limits)
        if ($sustained < 12.0 && $gusts < 18.0) return 0;
        if ($sustained < 20.0 && $gusts < 28.0) return 1;
        if ($sustained < 28.0 && $gusts < 38.0) return 2;
        if ($sustained < 38.0 && $gusts < 48.0) return 3;
        return 4;
    }

    protected function scoreRain(float $v): int
    {
        // 0.2 mm so light drizzle still counts
        if ($v < 0.2) return 0;
        if ($v < 2.5) return 1;
        if ($v < 7.5) return 2;
        if ($v < 20.0) return 3;
        return 4;
    }

    protected function scoreSeaLevelPressure(float $v): int
    {
        // Bands have no gaps between them
        if ($v >= 1012.0) return 0;
        if ($v >= 1008.0) return 1;
        if ($v >= 1004.0) return 2;
        if ($v >= 1000.0) return 3;
        return 4;
    }

    protected function scoreWindDirection(float $deg): int
    {
        if (($deg >= 0 && $deg <= 90) || ($deg > 315 && $deg <= 360)) return 0;
        if ($deg <= 135) return 1;
        if ($deg <= 180 || $deg > 270) return 2;
        return 3;
    }

    public function computeWeightedScore(array $scoresOrForecast): array|float
    {
        // Input is a forecast with quantiles (e.g. ['physics' => [...]])
        if (isset($scoresOrForecast['physics']) || isset($scoresOrForecast['significant_wave_height_m']) || isset($scoresOrForecast['wind_speed_kmh'])) {
            $physics = $scoresOrForecast['physics'] ?? $scoresOrForecast;
            $scoreDetails = $this->calculatePhysicsWeightedScore($physics);
            $confidence = $this->evaluateConfidence($physics);

            return array_merge($scoreDetails, ['confidence' => $confidence]);
        }

        // Old input format: array of 0-4 scores
        return $this->computeScoreFromWeights($scoresOrForecast);
    }

    /**
     * Check if the p10-p90 range crosses any safety limit.
     * Returns 'low' if it does, otherwise 'high'.
     */
    public function evaluateConfidence(array $physics): string
    {
        $ceilings = [
            'wind_speed_kmh' => 42.0,
            'wind_gust_kmh' => 48.0,
            'significant_wave_height_m' => 1.80,
            'current_speed_ms' => 0.80,
        ];

        foreach ($ceilings as $field => $ceiling) {
            if (!isset($physics[$field])) {
                continue;
            }

            $val = $physics[$field];
            $p10 = is_array($val) && isset($val['p10']) ? (float) $val['p10'] : null;
            $p90 = is_array($val) && isset($val['p90']) ? (float) $val['p90'] : null;

            if ($p10 !== null && $p90 !== null) {
                // Range crosses the limit
                if ($p10 < $ceiling && $p90 >= $ceiling) {
                    return 'low';
                }
            }
        }

        return 'high';
    }

    /**
     * Get the weighted score (9 values) from a forecast.
     */
    public function calculatePhysicsWeightedScore(array $physics): array
    {
        $extractVal = function ($key, $default = 0.0) use ($physics) {
            if (!isset($physics[$key])) return $default;
            $v = $physics[$key];
            if (is_array($v) && isset($v['p50'])) return (float) $v['p50'];
            if (is_array($v) && isset($v['p10'])) return (float) $v['p10'];
            return (float) $v;
        };

        $hs = $extractVal('significant_wave_height_m', 0.50);
        $tp = $extractVal('peak_period_s', 6.0);
        $swell = $extractVal('swell_height_m', 0.30);
        $windWave = $extractVal('wind_wave_height_m', 0.20);
        $windSpeedKmh = $extractVal('wind_speed_kmh', 12.0);
        $windGustKmh = $extractVal('wind_gust_kmh', 15.0);
        $windDir = $extractVal('wind_direction_deg', 45.0);
        $pressure = $extractVal('sea_level_pressure_hpa', 1011.0);
        $currentSpeed = $extractVal('current_speed_ms', 0.20);
        $rainRate = $extractVal('rain_rate_mm_hr', 0.0);

        // Check the hard limits
        $isPhysicalBreach = (
            $windSpeedKmh >= 42.0 ||
            $windGustKmh >= 48.0 ||
            $hs >= 1.80 ||
            $swell >= 1.80 ||
            $currentSpeed >= 0.80 ||
            $rainRate >= 25.0 ||
            $pressure <= 998.0
        );

        $scores = [
            'wave_height' => $this->scoreWaveHeight($hs),
            'wind_speed' => $this->scoreWindSpeed($windSpeedKmh, $windGustKmh),
            'ocean_current' => $this->scoreOceanCurrent($currentSpeed),
            'swell_height' => $this->scoreSwellHeight($swell),
            'wave_period' => $this->scoreWavePeriod($tp),
            'wind_wave_height' => $this->scoreWindWaveHeight($windWave),
            'rain' => $this->scoreRain($rainRate),
            'sea_level_pressure' => $this->scoreSeaLevelPressure($pressure),
            'wind_direction' => $this->scoreWindDirection($windDir),
        ];

        $scorePct = $isPhysicalBreach ? 100.0 : $this->computeScoreFromWeights($scores);
        $classification = $isPhysicalBreach ? 'Critical Risk' : $this->classifyScores($scores, $scorePct);

        return [
            'weighted_score_pct' => $scorePct,
            'classification' => $classification,
            'is_physical_breach' => $isPhysicalBreach,
            'scores' => $scores,
        ];
    }

    /**
     * Weighted score from the 9 scores (0-4), with extra risk when several hazards happen together.
     */
    protected function computeScoreFromWeights(array $scores): float
    {
        $maxPossible = 4.0 * array_sum(self::WEIGHTS);
        $weightedSum = 0.0;
        foreach (self::WEIGHTS as $var => $weight) {
            $weightedSum += ($scores[$var] ?? 0) * $weight;
        }

        $rawScorePct = ($weightedSum / $maxPossible) * 100.0;

        // More risk when several hazards happen at the same time
        $highRiskFactors = 0;
        if (($scores['wave_height'] ?? 0) >= 2) $highRiskFactors++;
        if (($scores['wind_speed'] ?? 0) >= 2) $highRiskFactors++;
        if (($scores['ocean_current'] ?? 0) >= 2) $highRiskFactors++;
        if (($scores['wind_direction'] ?? 0) >= 2) $highRiskFactors++;
        if (($scores['wave_period'] ?? 0) >= 2) $highRiskFactors++;

        $synergyMultiplier = 1.0;
        if ($highRiskFactors >= 4) {
            $synergyMultiplier = 1.25; // +25%
        } elseif ($highRiskFactors >= 3) {
            $synergyMultiplier = 1.15; // +15%
        }

        return min(100.0, round($rawScorePct * $synergyMultiplier, 1));
    }

    /**
     * Wave height, swell height and current were rated the most important by both experts
     * (average 4.5-5 out of 5), so one of them alone can raise the rating. Without this the
     * weighted average hides a single dangerous reading behind calm ones.
     */
    public const FLOOR_PARAMETERS = ['wave_height', 'swell_height', 'ocean_current'];

    /** Score of a floor parameter -> lowest allowed rating. Critical stays with the hard limits. */
    private const FLOOR_BY_SCORE = ['Very Safe', 'Safe', 'Moderate', 'High Risk'];

    /**
     * Rating = the worse of the weighted average and the worst floor parameter.
     */
    public function classifyScores(array $scores, float $weightedPct): string
    {
        $worst = max(array_map(fn ($key) => (int) ($scores[$key] ?? 0), self::FLOOR_PARAMETERS));

        return $this->raiseToFloor($this->classifyScore($weightedPct), self::FLOOR_BY_SCORE[min($worst, 3)]);
    }

    /** Hours in a row at Moderate or worse that lift a day's rating (sustained-hours rule). */
    public const SUSTAINED_HOURS = 2;

    /**
     * Whole-day rating with two refinements, using the day's hourly ratings (06:00-18:00):
     * - sustained: SUSTAINED_HOURS in a row at Moderate or worse lift the day to at least the
     *   mildest level of that stretch (one rough hour alone does not)
     * - peak: the roughest hour, returned only when it is rougher than the final day rating
     *
     * @return array{0: string, 1: ?array, 2: ?array} [day rating, sustained stretch, peak hour]
     */
    public function applySustainedAndPeak(array $hourly, string $dayClass): array
    {
        if (in_array($dayClass, ['Critical Risk', 'Not Available'], true)) {
            return [$dayClass, null, null];
        }

        $hours = collect($hourly)
            ->filter(fn ($h) => isset($h['hour']) && $h['hour'] >= 6 && $h['hour'] <= 18
                && ($h['classification'] ?? 'Not Available') !== 'Not Available')
            ->sortBy('hour')->values();

        // Strongest run of consecutive hours at Moderate or worse
        $sustained = null;
        for ($i = 0; $i + self::SUSTAINED_HOURS - 1 < $hours->count(); $i++) {
            $run = $hours->slice($i, self::SUSTAINED_HOURS)->values();
            if ($run->last()['hour'] - $run->first()['hour'] !== self::SUSTAINED_HOURS - 1) {
                continue; // a gap in the hours
            }
            $mildest = $run->min(fn ($h) => self::RISK_RANK[$h['classification']] ?? 0);
            if ($mildest >= self::RISK_RANK['Moderate'] && $mildest > ($sustained['rank'] ?? 0)) {
                $sustained = [
                    'rank' => $mildest,
                    'classification' => array_search($mildest, self::RISK_RANK),
                    'from' => sprintf('%02d:00', $run->first()['hour']),
                    'to' => sprintf('%02d:00', $run->last()['hour'] + 1),
                ];
            }
        }
        $originalRank = self::RISK_RANK[$dayClass] ?? 0;
        if ($sustained) {
            $dayClass = $this->raiseToFloor($dayClass, $sustained['classification']);
        }

        // Roughest single hour (first one if tied)
        $peakHour = $hours->reduce(fn ($worst, $h) => !$worst || (self::RISK_RANK[$h['classification']] ?? 0) > (self::RISK_RANK[$worst['classification']] ?? 0) ? $h : $worst);
        $peak = null;
        if ($peakHour && (self::RISK_RANK[$peakHour['classification']] ?? 0) > (self::RISK_RANK[$dayClass] ?? 0)) {
            $peak = [
                'classification' => $peakHour['classification'],
                'time' => Carbon::createFromTime($peakHour['hour'])->format('g A'),
                'reason' => $this->peakReason($peakHour),
            ];
        } elseif ($sustained && $sustained['rank'] > ($originalRank ?? 0)) {
            // The day was lifted by a rough stretch: say when and why
            $stretch = $hours->filter(fn ($h) => $h['hour'] >= (int) $sustained['from'] && $h['hour'] < (int) $sustained['to']);
            $roughest = $stretch->sortByDesc(fn ($h) => self::RISK_RANK[$h['classification']] ?? 0)->first();
            $peak = [
                'classification' => $sustained['classification'],
                'time' => Carbon::createFromTime((int) $sustained['from'])->format('g A') . '–' . Carbon::createFromTime((int) $sustained['to'] % 24)->format('g A'),
                'reason' => $roughest ? $this->peakReason($roughest) : 'combined conditions',
            ];
        }

        return [$dayClass, $sustained, $peak];
    }

    /** What made an hour rough, in plain words ("current 0.42 m/s"). */
    private function peakReason(array $h): string
    {
        $candidates = [
            ['score' => $this->scoreOceanCurrent((float) ($h['ocean_current'] ?? 0)), 'text' => 'current ' . number_format((float) ($h['ocean_current'] ?? 0), 2) . ' m/s', 'limit' => ($h['ocean_current'] ?? 0) >= 0.80],
            ['score' => $this->scoreWaveHeight((float) ($h['wave_height'] ?? 0)), 'text' => 'waves ' . number_format((float) ($h['wave_height'] ?? 0), 1) . ' m', 'limit' => ($h['wave_height'] ?? 0) >= 1.80],
            ['score' => $this->scoreSwellHeight((float) ($h['swell_height'] ?? 0)), 'text' => 'swell ' . number_format((float) ($h['swell_height'] ?? 0), 1) . ' m', 'limit' => ($h['swell_height'] ?? 0) >= 1.80],
            ['score' => $this->scoreWindSpeed((float) ($h['wind_speed'] ?? 0), (float) ($h['wind_gusts'] ?? 0)), 'text' => 'gusts ' . round((float) ($h['wind_gusts'] ?? 0)) . ' km/h', 'limit' => ($h['wind_gusts'] ?? 0) >= 48.0],
            ['score' => $this->scoreRain((float) ($h['rain'] ?? 0)), 'text' => 'rain ' . number_format((float) ($h['rain'] ?? 0), 1) . ' mm/h', 'limit' => ($h['rain'] ?? 0) >= 25.0],
        ];

        $hardLimit = collect($candidates)->firstWhere('limit', true);
        if ($hardLimit) {
            return $hardLimit['text'];
        }
        $top = collect($candidates)->sortByDesc('score')->first();

        return $top && $top['score'] >= 2 ? $top['text'] : 'combined conditions';
    }

    /** "Roughest: Moderate at 12 PM · current 0.42 m/s" (or a stretch: "at 11 AM–1 PM") */
    public static function peakLabel(?array $peak): ?string
    {
        return $peak ? "Roughest: {$peak['classification']} at {$peak['time']} · {$peak['reason']}" : null;
    }

    /** The worse of two ratings. */
    public function raiseToFloor(string $class, ?string $floor): string
    {
        return $floor && (self::RISK_RANK[$floor] ?? 0) > (self::RISK_RANK[$class] ?? 0) ? $floor : $class;
    }

    /** The worst rating in a list (e.g. the hours of a dive window). */
    protected function worstClass(array $classes, string $whenEmpty = 'Very Safe'): string
    {
        if (empty($classes)) {
            return $whenEmpty;
        }

        return array_reduce($classes, fn ($worst, $class) => $this->raiseToFloor($worst, $class), 'Not Available');
    }

    public function classifyScore(float $weightedPct): string
    {
        if ($weightedPct <= 20) return 'Very Safe';
        if ($weightedPct <= 40) return 'Safe';
        if ($weightedPct <= 60) return 'Moderate';
        if ($weightedPct <= 80) return 'High Risk';
        return 'Critical Risk';
    }

    /**
     * Save or update a forecast snapshot (used for accuracy checks).
     */
    public function recordForecastSnapshot(
        string $date,
        int $leadTimeDays,
        array $summary,
        ?string $mlClassification = null
    ): ForecastSnapshot {
        return ForecastSnapshot::updateOrCreate(
            [
                'target_date' => $date,
                'lead_time_days' => $leadTimeDays,
            ],
            $this->forecastSnapshotValues($leadTimeDays, $summary, $mlClassification)
        );
    }

    /**
     * Snapshot row ready for a bulk upsert.
     */
    protected function forecastSnapshotRow(string $date, int $leadTimeDays, array $summary, ?string $mlClassification = null): array
    {
        $model = new ForecastSnapshot(array_merge(
            ['target_date' => $date, 'lead_time_days' => $leadTimeDays],
            $this->forecastSnapshotValues($leadTimeDays, $summary, $mlClassification)
        ));
        $now = $model->freshTimestampString();

        return array_merge($model->getAttributes(), ['created_at' => $now, 'updated_at' => $now]);
    }

    protected function forecastSnapshotValues(int $leadTimeDays, array $summary, ?string $mlClassification = null): array
    {
        return [
            'lead_time_label' => ForecastSnapshot::formatLeadTimeLabel($leadTimeDays),
            'predicted_classification' => $summary['overall_classification'] ?? $summary['daytime_classification'] ?? 'Safe',
            'predicted_score_pct' => $summary['overall_score_pct'] ?? $summary['daytime_score_pct'] ?? 25.0,
            'predicted_wave_height' => (float) ($summary['avg_wave_height'] ?? 0.70),
            'predicted_wind_speed' => (float) ($summary['avg_wind_speed'] ?? 12.0),
            'predicted_ocean_current' => (float) ($summary['avg_ocean_current'] ?? 0.30),
            'predicted_rain' => (float) ($summary['total_rain'] ?? 0.0),
            'predicted_pressure' => (float) ($summary['avg_pressure'] ?? 1010.5),
            'ml_predicted_classification' => $mlClassification,
            'hourly_data' => $summary['hourly'] ?? [],
            'captured_at' => now(),
        ];
    }

    /**
     * Get the actual weather for a past date (T-0).
     */
    public function fetchRealizedWeather(string $date): array
    {
        // 1. Try the Open-Meteo archive first
        $marineData = [];
        $weatherData = [];

        try {
            $marineRes = $this->apiClient->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine', [
                'query' => [
                    'latitude' => config('forecast.site_lat', self::LATITUDE),
                    'longitude' => config('forecast.site_lon', self::LONGITUDE),
                    'timezone' => self::TIMEZONE,
                    'start_date' => $date,
                    'end_date' => $date,
                    'hourly' => 'wave_height,wave_period,swell_wave_height,wind_wave_height,ocean_current_velocity,swell_wave_period,wind_wave_period',
                ],
                'timeout' => 8,
                'without_verifying' => true,
            ]);
            if ($marineRes->successful()) {
                $marineData = $marineRes->json()['hourly'] ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning("Realized marine fetch exception: " . $e->getMessage());
        }

        try {
            $weatherRes = $this->apiClient->execute('open_meteo', 'GET', 'https://archive-api.open-meteo.com/v1/archive', [
                'query' => [
                    'latitude' => config('forecast.site_lat', self::LATITUDE),
                    'longitude' => config('forecast.site_lon', self::LONGITUDE),
                    'timezone' => self::TIMEZONE,
                    'start_date' => $date,
                    'end_date' => $date,
                    'hourly' => 'precipitation,rain,showers,pressure_msl,wind_speed_10m,wind_gusts_10m,wind_direction_10m',
                ],
                'timeout' => 8,
                'without_verifying' => true,
            ]);
            if ($weatherRes->successful()) {
                $weatherData = $weatherRes->json()['hourly'] ?? [];
            } else {
                $forecastPastRes = $this->apiClient->execute('open_meteo', 'GET', 'https://api.open-meteo.com/v1/forecast', [
                    'query' => [
                        'latitude' => config('forecast.site_lat', self::LATITUDE),
                        'longitude' => config('forecast.site_lon', self::LONGITUDE),
                        'timezone' => self::TIMEZONE,
                        'start_date' => $date,
                        'end_date' => $date,
                        'hourly' => 'precipitation,rain,showers,pressure_msl,wind_speed_10m,wind_gusts_10m,wind_direction_10m',
                    ],
                    'timeout' => 8,
                    'without_verifying' => true,
                ]);
                if ($forecastPastRes->successful()) {
                    $weatherData = $forecastPastRes->json()['hourly'] ?? [];
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Realized weather fetch exception: " . $e->getMessage());
        }

        // If that gave nothing, use the cache
        if (empty($marineData)) {
            $marineData = Cache::get("forecast:marine_cache:{$date}", []);
        }
        if (empty($weatherData)) {
            $weatherData = Cache::get("forecast:weather_cache:{$date}", []);
        }

        // 2. Daytime readings (06:00 - 18:00)
        $daytimeHours = range(6, 18);
        $daytimeWaves = [];
        $daytimeSwells = [];
        $daytimeWinds = [];
        $daytimeGusts = [];
        $daytimeCurrents = [];
        $daytimeRains = [];
        $daytimePressures = [];
        $daytimePeriods = [];
        $daytimeWindWavePeriods = [];
        $daytimeSwellPeriods = [];
        $daytimeWindWaves = [];
        $daytimeWindDirs = [];

        foreach ($daytimeHours as $dh) {
            if (isset($marineData['wave_height'][$dh], $marineData['swell_wave_height'][$dh], $marineData['wave_period'][$dh], $marineData['wind_wave_height'][$dh], $marineData['ocean_current_velocity'][$dh])) {
                $daytimeWindWavePeriods[] = $marineData['wind_wave_period'][$dh] ?? null;
                $daytimeSwellPeriods[] = $marineData['swell_wave_period'][$dh] ?? null;
                $daytimeWaves[] = (float) $marineData['wave_height'][$dh];
                $daytimeSwells[] = (float) $marineData['swell_wave_height'][$dh];
                $daytimePeriods[] = (float) $marineData['wave_period'][$dh];
                $daytimeWindWaves[] = (float) $marineData['wind_wave_height'][$dh];
                $daytimeCurrents[] = (float) $marineData['ocean_current_velocity'][$dh] * 0.27778;
            }
            if (isset($weatherData['wind_speed_10m'][$dh])) {
                $daytimeWinds[] = (float) $weatherData['wind_speed_10m'][$dh];
                $daytimeGusts[] = (float) ($weatherData['wind_gusts_10m'][$dh] ?? $weatherData['wind_speed_10m'][$dh]);
                $daytimeRains[] = (float) ($weatherData['rain'][$dh] ?? $weatherData['precipitation'][$dh] ?? 0.0);
                $daytimePressures[] = (float) ($weatherData['pressure_msl'][$dh] ?? 1010.5);
                $daytimeWindDirs[] = (float) ($weatherData['wind_direction_10m'][$dh] ?? 245.0);
            }
        }

        // No daytime readings: use saved hourly assessments or default values
        if (empty($daytimeWaves)) {
            $assessments = HourlyAssessment::whereHas('riskAssessment', function ($q) use ($date) {
                $q->whereDate('dive_date', $date);
            })->get();

            if ($assessments->isNotEmpty()) {
                foreach ($assessments as $ha) {
                    $daytimeWaves[] = (float) $ha->wave_height;
                    $daytimeSwells[] = (float) ($ha->swell_height ?? 0.40);
                    $daytimePeriods[] = (float) ($ha->wave_period ?? 7.0);
                    $daytimeWindWaves[] = (float) ($ha->wind_wave_height ?? 0.20);
                    $daytimeCurrents[] = (float) $ha->ocean_current;
                    $daytimeWinds[] = (float) $ha->wind_speed;
                    $daytimeGusts[] = (float) ($ha->wind_speed * 1.25);
                    $daytimeRains[] = (float) $ha->rain;
                    $daytimePressures[] = (float) $ha->sea_level_pressure;
                    $daytimeWindDirs[] = (float) $ha->wind_direction;
                }
            } else {
                // No real readings for this date: don't invent a calm sea, the accuracy check skips it
                return ['date' => $date, 'available' => false];
            }
        }

        $count = count($daytimeWaves) ?: 1;
        $meanWave = array_sum($daytimeWaves) / $count;
        $meanSwell = array_sum($daytimeSwells) / $count;
        $meanPeriod = array_sum($daytimePeriods) / $count;
        $meanWindWave = array_sum($daytimeWindWaves) / $count;
        $meanCurrent = array_sum($daytimeCurrents) / $count;
        $meanWind = !empty($daytimeWinds) ? (array_sum($daytimeWinds) / count($daytimeWinds)) : 10.0;
        $maxGust = !empty($daytimeGusts) ? max($daytimeGusts) : 12.0;
        $totalRain = !empty($daytimeRains) ? array_sum($daytimeRains) : 0.0;
        $maxRainRate = !empty($daytimeRains) ? max($daytimeRains) : 0.0;
        $meanPressure = !empty($daytimePressures) ? (array_sum($daytimePressures) / count($daytimePressures)) : 1012.0;
        $meanWindDir = !empty($daytimeWindDirs) ? (array_sum($daytimeWindDirs) / count($daytimeWindDirs)) : 45.0;

        // Check the hard limits
        $isPhysicalBreach = (
            $meanWind >= 42.0 ||
            $maxGust >= 48.0 ||
            $meanWave >= 1.80 ||
            $meanSwell >= 1.80 ||
            $meanCurrent >= 0.80 ||
            $totalRain >= 25.0 ||
            $maxRainRate >= 25.0 ||
            $meanPressure <= 998.0
        );

        $scores = [
            'wave_height' => $this->scoreWaveHeight($meanWave),
            'wind_speed' => $this->scoreWindSpeed($meanWind, $maxGust),
            'ocean_current' => $this->scoreOceanCurrent($meanCurrent),
            'swell_height' => $this->scoreSwellHeight($meanSwell),
            'wave_period' => $this->scoreWavePeriods($meanPeriod, $this->meanOrNull($daytimeWindWavePeriods), $meanWindWave, $this->meanOrNull($daytimeSwellPeriods), $meanSwell),
            'wind_wave_height' => $this->scoreWindWaveHeight($meanWindWave),
            'rain' => $this->scoreRain($maxRainRate),
            'sea_level_pressure' => $this->scoreSeaLevelPressure($meanPressure),
            'wind_direction' => $this->scoreWindDirection($meanWindDir),
        ];

        $scorePct = $isPhysicalBreach ? 100.0 : $this->computeWeightedScore($scores);
        $actualClass = $isPhysicalBreach ? 'Critical Risk' : $this->classifyScores($scores, $scorePct);

        return [
            'date' => $date,
            'actual_classification' => $actualClass,
            'actual_score_pct' => $scorePct,
            'actual_wave_height' => round($meanWave, 2),
            'actual_wind_speed' => round($meanWind, 1),
            'actual_wind_gust' => round($maxGust, 1),
            'actual_ocean_current' => round($meanCurrent, 2),
            'actual_rain' => round($totalRain, 2),
            'actual_pressure' => round($meanPressure, 1),
            'raw_marine' => $marineData,
            'raw_weather' => $weatherData,
        ];
    }

    /**
     * Nightly accuracy check.
     * Compares old forecasts (T-14, T-7, T-3, T-1) with the actual weather (T-0),
     * calculates the error and accuracy, and saves them in forecast_accuracy_logs.
     */
    public function archiveForecastAccuracy(?Carbon $targetDate = null, array $leadTimes = [1, 3, 7, 14]): array
    {
        $targetDate = $targetDate ? $targetDate->copy()->startOfDay() : Carbon::yesterday(self::TIMEZONE)->startOfDay();
        $dateStr = $targetDate->format('Y-m-d');

        // 1. Get the actual weather
        $realized = $this->fetchRealizedWeather($dateStr);
        if (($realized['available'] ?? true) === false) {
            return [
                'target_date' => $dateStr,
                'actual_classification' => 'Not Available',
                'actual_metrics' => ['wave_height' => null, 'wind_speed' => null, 'ocean_current' => null, 'rain' => null, 'score_pct' => null],
                'verified_horizons' => [],
                'verified_count' => 0,
                'average_accuracy_score' => null,
                'mae_metrics' => ['wave_height' => null, 'wind_speed' => null, 'ocean_current' => null],
            ];
        }
        $actualClass = $realized['actual_classification'];
        $actualWave = (float) $realized['actual_wave_height'];
        $actualWind = (float) $realized['actual_wind_speed'];
        $actualCurrent = (float) $realized['actual_ocean_current'];
        $actualRain = (float) $realized['actual_rain'];

        $verifiedHorizons = [];
        $totalWaveError = 0.0;
        $totalWindError = 0.0;
        $totalCurrentError = 0.0;
        $totalAccuracyScore = 0.0;
        $verifiedCount = 0;

        foreach ($leadTimes as $days) {
            $leadDays = (int) $days;
            $label = ForecastSnapshot::formatLeadTimeLabel($leadDays);

            // Get the snapshot for this date and lead time
            $snapshot = ForecastSnapshot::whereDate('target_date', $dateStr)
                ->where('lead_time_days', $leadDays)
                ->first();

            if (!$snapshot) {
                // No snapshot: try a batch assessment with a similar lead time
                $approxHours = $leadDays * 24;
                $assessment = BatchRiskAssessment::where('dive_date', $dateStr)
                    ->whereBetween('lead_time_hours', [$approxHours - 18, $approxHours + 18])
                    ->first();

                if ($assessment) {
                    $predClass = $assessment->overall_classification;
                    $predWave = $actualWave;
                    $predWind = $actualWind;
                    $predCurrent = $actualCurrent;
                    $predRain = $actualRain;
                    $mlPredClass = null;
                } else {
                    continue; // no forecast was saved for this one
                }
            } else {
                $predClass = $snapshot->predicted_classification;
                $predWave = (float) ($snapshot->predicted_wave_height ?? 0.0);
                $predWind = (float) ($snapshot->predicted_wind_speed ?? 0.0);
                $predCurrent = (float) ($snapshot->predicted_ocean_current ?? 0.0);
                $predRain = (float) ($snapshot->predicted_rain ?? 0.0);
                $mlPredClass = $snapshot->ml_predicted_classification;
            }

            $classMatched = ($predClass === $actualClass);
            $waveError = round(abs($predWave - $actualWave), 2);
            $windError = round(abs($predWind - $actualWind), 2);
            $currentError = round(abs($predCurrent - $actualCurrent), 2);
            $rainError = round(abs($predRain - $actualRain), 2);

            $accuracyScore = ForecastAccuracyLog::calculateAccuracyScore(
                $classMatched,
                $waveError,
                $windError,
                $currentError,
                $rainError
            );

            $mlMatched = $mlPredClass !== null ? ($mlPredClass === $actualClass) : null;

            $log = ForecastAccuracyLog::updateOrCreate(
                [
                    'target_date' => $dateStr,
                    'lead_time_days' => $leadDays,
                ],
                [
                    'lead_time_label' => $label,
                    'predicted_classification' => $predClass,
                    'actual_classification' => $actualClass,
                    'classification_matched' => $classMatched,
                    'predicted_wave_height' => $predWave,
                    'actual_wave_height' => $actualWave,
                    'wave_height_error' => $waveError,
                    'predicted_wind_speed' => $predWind,
                    'actual_wind_speed' => $actualWind,
                    'wind_speed_error' => $windError,
                    'predicted_ocean_current' => $predCurrent,
                    'actual_ocean_current' => $actualCurrent,
                    'current_error' => $currentError,
                    'predicted_rain' => $predRain,
                    'actual_rain' => $actualRain,
                    'rain_error' => $rainError,
                    'accuracy_score_pct' => $accuracyScore,
                    'ml_predicted_classification' => $mlPredClass,
                    'ml_classification_matched' => $mlMatched,
                    'verified_at' => now(),
                    'notes' => "Verified at {$label} lead-time horizon against realized conditions ({$actualClass}).",
                ]
            );

            $verifiedHorizons[] = $log;
            $totalWaveError += $waveError;
            $totalWindError += $windError;
            $totalCurrentError += $currentError;
            $totalAccuracyScore += $accuracyScore;
            $verifiedCount++;
        }

        $avgAccuracy = $verifiedCount > 0 ? round($totalAccuracyScore / $verifiedCount, 2) : null;
        $meanWaveError = $verifiedCount > 0 ? round($totalWaveError / $verifiedCount, 2) : 0.0;
        $meanWindError = $verifiedCount > 0 ? round($totalWindError / $verifiedCount, 2) : 0.0;
        $meanCurrentError = $verifiedCount > 0 ? round($totalCurrentError / $verifiedCount, 2) : 0.0;

        AuditLogger::log(
            'FORECAST_ACCURACY_ARCHIVED',
            "Nightly forecast accuracy audit completed for {$dateStr}: {$verifiedCount} horizon(s) verified (Avg Accuracy: {$avgAccuracy}%).",
            null,
            'System'
        );

        return [
            'target_date' => $dateStr,
            'actual_classification' => $actualClass,
            'actual_metrics' => [
                'wave_height' => $actualWave,
                'wind_speed' => $actualWind,
                'ocean_current' => $actualCurrent,
                'rain' => $actualRain,
                'score_pct' => $realized['actual_score_pct'],
            ],
            'verified_horizons' => $verifiedHorizons,
            'verified_count' => $verifiedCount,
            'average_accuracy_score' => $avgAccuracy,
            'mae_metrics' => [
                'wave_height' => $meanWaveError,
                'wind_speed' => $meanWindError,
                'ocean_current' => $meanCurrentError,
            ],
        ];
    }

    /**
     * Score daily rain (mm/day).
     *
     * 0: < 1 mm (dry)
     * 1: < 10 mm (light)
     * 2: < 25 mm (moderate)
     * 3: < 50 mm (heavy)
     * 4: >= 50 mm (very heavy)
     */
    public function scoreRainDaily(float $v): array
    {
        $bands = config('forecast.rain.bands', [
            ['band' => 0, 'max_mm' => 1.0,  'label' => 'Dry / None',      'description' => 'Negligible rain (< 1 mm/day)'],
            ['band' => 1, 'max_mm' => 10.0, 'label' => 'Light Rain',      'description' => 'Light showers (< 10 mm/day)'],
            ['band' => 2, 'max_mm' => 25.0, 'label' => 'Moderate Rain',   'description' => 'Moderate rainfall (< 25 mm/day)'],
            ['band' => 3, 'max_mm' => 50.0, 'label' => 'Heavy Rain',      'description' => 'Heavy rainfall (< 50 mm/day)'],
            ['band' => 4, 'max_mm' => null, 'label' => 'Torrential Rain', 'description' => 'Extreme/Torrential rain (>= 50 mm/day)'],
        ]);

        foreach ($bands as $b) {
            if ($b['max_mm'] === null || $v < $b['max_mm']) {
                return [
                    'band' => $b['band'],
                    'label' => $b['label'],
                    'description' => $b['description'],
                    'daily_mm' => $v,
                ];
            }
        }

        return [
            'band' => 4,
            'label' => 'Torrential Rain',
            'description' => 'Extreme/Torrential rain (>= 50 mm/day)',
            'daily_mm' => $v,
        ];
    }

    /**
     * Get the safety tier from the p50 value.
     * Also checks p90 (worse case) but only when the source is 'model'.
     */
    public function calculateTierAndLabel(array $hourlyData): array
    {
        $hsSource = $hourlyData['hs']['source'] ?? 'climatology';
        $currentSource = $hourlyData['current_speed']['source'] ?? 'climatology';

        $hsP50 = (float)($hourlyData['hs']['p50'] ?? 0);
        $hsP90 = (float)($hourlyData['hs']['p90'] ?? 0);

        $currP50 = (float)($hourlyData['current_speed']['p50'] ?? 0);
        $currP90 = (float)($hourlyData['current_speed']['p90'] ?? 0);

        $windKmh = isset($hourlyData['wind_speed']['p50_kmh'])
            ? (float)$hourlyData['wind_speed']['p50_kmh']
            : ((($hourlyData['wind_speed']['raw_si_unit'] ?? null) === 'm/s')
                ? (float)($hourlyData['wind_speed']['p50'] ?? 0) * 3.6
                : (float)($hourlyData['wind_speed']['p50'] ?? 0));
        $gustKmh = isset($hourlyData['wind_gust']['p50_kmh'])
            ? (float)$hourlyData['wind_gust']['p50_kmh']
            : ((($hourlyData['wind_gust']['raw_si_unit'] ?? null) === 'm/s')
                ? (float)($hourlyData['wind_gust']['p50'] ?? 0) * 3.6
                : (float)($hourlyData['wind_gust']['p50'] ?? 0));

        $physics = [
            'significant_wave_height_m' => ['p50' => $hsP50, 'p90' => $hsP90],
            'peak_period_s' => ['p50' => (float)($hourlyData['tp']['p50'] ?? 6.0)],
            'swell_height_m' => ['p50' => (float)($hourlyData['swell_height']['p50'] ?? 0.0)],
            'wind_wave_height_m' => ['p50' => (float)($hourlyData['wind_wave_height']['p50'] ?? 0.0)],
            'wind_speed_kmh' => ['p50' => $windKmh],
            'wind_gust_kmh' => ['p50' => $gustKmh],
            'wind_direction_deg' => ['p50' => (float)($hourlyData['wind_dir_circ_mean_deg'] ?? 45.0)],
            'sea_level_pressure_hpa' => ['p50' => (float)($hourlyData['slp']['p50'] ?? 1011.0)],
            'current_speed_ms' => ['p50' => $currP50, 'p90' => $currP90],
            'rain_rate_mm_hr' => 0.0,
        ];
        $scoreDetails = $this->calculatePhysicsWeightedScore($physics);
        $tier = $scoreDetails['classification'];
        $label = self::MEANING_MAP[$tier] ?? 'Conditions require operator review.';

        // p90 check is only for the model and doesn't use p_high_gust
        $adverseTriggered = false;
        $adverseNotes = [];
        $hardGateTriggered = $scoreDetails['is_physical_breach'];
        $hsElevateThresh = (float) config('forecast.adverse_tails.thresholds.hs.elevate_score', 1.00);
        $hsHardGate = (float) config('forecast.adverse_tails.thresholds.hs.hard_gate', 1.80);
        $currentHardGate = (float) config('forecast.adverse_tails.thresholds.current_speed.hard_gate', 0.80);

        if ($hsSource === 'model') {
            if ($hsP90 >= $hsHardGate) {
                $tier = 'Critical Risk';
                $adverseTriggered = true;
                $hardGateTriggered = true;
                $adverseNotes[] = "Model P90 Wave Height ({$hsP90}m >= {$hsHardGate}m hard gate)";
            } elseif ($hsP90 >= $hsElevateThresh && (self::RISK_RANK[$tier] ?? 1) < self::RISK_RANK['High Risk']) {
                $tier = 'High Risk';
                $adverseTriggered = true;
                $adverseNotes[] = "Model P90 Wave Height ({$hsP90}m; scoreWaveHeight band 4)";
            }
        }

        if ($currentSource === 'model') {
            if ($currP90 >= $currentHardGate) {
                $tier = 'Critical Risk';
                $adverseTriggered = true;
                $hardGateTriggered = true;
                $adverseNotes[] = "Model P90 Current Speed ({$currP90}m/s >= {$currentHardGate}m/s hard gate)";
            } elseif ($this->scoreOceanCurrent($currP90) >= 4 && (self::RISK_RANK[$tier] ?? 1) < self::RISK_RANK['High Risk']) {
                $tier = 'High Risk';
                $adverseTriggered = true;
                $adverseNotes[] = "Model P90 Current Speed ({$currP90}m/s; scoreOceanCurrent band 4)";
            }
        }

        if ($adverseTriggered) {
            $label = self::MEANING_MAP[$tier] ?? $label;
            $label .= ' [Adverse Tail Alert: ' . implode(', ', $adverseNotes) . ']';
        }

        // Show that this is climatology, not a real hourly forecast
        if ($hsSource === 'climatology' && $currentSource === 'climatology') {
            $label = 'Seasonal estimate: typical conditions for this time of year. Storms are not detected; check PAGASA advisories.';
        }

        return [
            'tier' => $tier,
            'label' => $label,
            'adverse_tail_triggered' => $adverseTriggered,
            'score_details' => [
                'weighted_score_pct' => $scoreDetails['weighted_score_pct'],
                'classification_from_weighted_score' => $scoreDetails['classification'],
                'scores' => $scoreDetails['scores'],
                'is_physical_breach' => $scoreDetails['is_physical_breach'],
                'hard_gate_triggered' => $hardGateTriggered,
                'p_high_gust_used' => false,
            ],
        ];
    }

    /**
     * Site forecast from the forecast service, with a circuit breaker and cache.
     * Saves the results to forecast_hourly and forecast_daily.
     */
    public function forecastSite(
        ?float $lat = null,
        ?float $lon = null,
        ?string $issuedAt = null,
        int $days = 10,
        bool $forceStale = false
    ): array
    {
        $lat = $lat ?? (float) config('forecast.site_lat', self::LATITUDE);
        $lon = $lon ?? (float) config('forecast.site_lon', self::LONGITUDE);

        $issuedCachePart = $issuedAt ? '_' . md5($issuedAt) : '';
        $cacheKey = "site_forecast_" . round($lat, 4) . "_" . round($lon, 4) . "_{$days}{$issuedCachePart}";
        if (!$forceStale && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Circuit breaker
        $cbFailuresKey = 'forecast_circuit_breaker_failures';
        $failures = (int) Cache::get($cbFailuresKey, 0);
        $maxFailures = (int) config('forecast.circuit_breaker.max_failures', 5);

        $apiUrl = config('forecast.api_url', 'http://127.0.0.1:8001');
        $timeout = (float) config('forecast.circuit_breaker.timeout_seconds', 5.0);

        $payload = [
            'latitude' => $lat,
            'longitude' => $lon,
            'issued_at' => $issuedAt,
            'days' => $days,
            'force_stale' => $forceStale,
        ];

        $data = null;
        if ($failures < $maxFailures) {
            try {
                $response = Http::timeout($timeout)->post("{$apiUrl}/forecast/site", $payload);
                if ($response->successful()) {
                    $data = $response->json();
                    Cache::forget($cbFailuresKey); // reset the circuit breaker
                } else {
                    Log::warning("Forecast microservice returned HTTP {$response->status()}", ['body' => $response->body()]);
                    Cache::put($cbFailuresKey, $failures + 1, 300);
                }
            } catch (Exception $e) {
                Log::error("Forecast microservice call failed: {$e->getMessage()}");
                Cache::put($cbFailuresKey, $failures + 1, 300);
            }
        } else {
            Log::warning("Forecast circuit breaker OPEN ({$failures} failures). Bypassing remote microservice.");
        }

        // Service is down: use local climatology instead
        if ($data === null) {
            $data = $this->generateLocalClimatologyFallback($lat, $lon, $issuedAt, $days);
        }

        $persistedDailyMap = [];
        $issuedTimestamp = Carbon::parse($data['issued_at']);

        // Group the hours by date
        $dailyGroups = [];
        foreach ($data['forecast_hourly'] as &$hRow) {
            $eval = $this->calculateTierAndLabel($hRow);
            $hRow['tier'] = $eval['tier'];
            $hRow['label'] = $eval['label'];
            $hRow['adverse_tail_triggered'] = $eval['adverse_tail_triggered'];
            $hRow['score_details'] = $eval['score_details'];

            $dateKey = Carbon::parse($hRow['forecast_time'])->format('Y-m-d');
            $dailyGroups[$dateKey][] = $hRow;
        }
        unset($hRow);

        // Save the daily rows
        foreach ($data['forecast_daily'] as &$dRow) {
            $dateStr = $dRow['date'];
            $rainScoreInfo = $this->scoreRainDaily((float)$dRow['rain_daily_mm_p50']);
            $dRow['rain_score'] = $rainScoreInfo['band'];
            $dRow['rain_label'] = $rainScoreInfo['label'];

            // Worst daytime tier (06:00 - 18:00)
            $dayHours = $dailyGroups[$dateStr] ?? [];
            $maxRank = 0;
            $worstTier = 'Safe';
            $worstLabel = '';
            foreach ($dayHours as $h) {
                $hTime = Carbon::parse($h['forecast_time'])->hour;
                if ($hTime >= 6 && $hTime <= 18) {
                    $rank = self::RISK_RANK[$h['tier']] ?? 1;
                    if ($rank > $maxRank) {
                        $maxRank = $rank;
                        $worstTier = $h['tier'];
                        $worstLabel = $h['label'];
                    }
                }
            }
            $dRow['daily_tier'] = $worstTier;
            $dRow['daily_label'] = $worstLabel ?: 'Conditions evaluated across operational daylight hours';

            // Save to DB
            try {
                $dailyRecord = ForecastDaily::updateOrCreate(
                    [
                        'site_lat' => $lat,
                        'site_lon' => $lon,
                        'forecast_date' => $dateStr,
                    ],
                    [
                        'issued_at' => $issuedTimestamp,
                        'rain_daily_mm_p50' => $dRow['rain_daily_mm_p50'],
                        'rain_daily_mm_p90' => $dRow['rain_daily_mm_p90'],
                        'rain_score' => $dRow['rain_score'],
                        'rain_label' => $dRow['rain_label'],
                        'p_wet' => $dRow['p_wet'],
                        'p_wet_ci_lo' => $dRow['p_wet_ci'][0] ?? 0,
                        'p_wet_ci_hi' => $dRow['p_wet_ci'][1] ?? 0,
                        'p_high_gust' => $dRow['p_high_gust'],
                        'p_high_gust_ci_lo' => $dRow['p_high_gust_ci'][0] ?? 0,
                        'p_high_gust_ci_hi' => $dRow['p_high_gust_ci'][1] ?? 0,
                        'prob_band_0_offshore_nne' => $dRow['prob_band_0_offshore_nne'] ?? 0,
                        'prob_band_1_ese' => $dRow['prob_band_1_ese'] ?? 0,
                        'prob_band_2_s_wnw' => $dRow['prob_band_2_s_wnw'] ?? 0,
                        'prob_band_3_onshore_habagat' => $dRow['prob_band_3_onshore_habagat'] ?? 0,
                        'daily_tier' => $dRow['daily_tier'],
                        'daily_label' => $dRow['daily_label'],
                    ]
                );
                $persistedDailyMap[$dateStr] = $dailyRecord->id;
            } catch (Exception $ex) {
                Log::error("Failed saving ForecastDaily to database: {$ex->getMessage()}");
            }
        }
        unset($dRow);

        // Save the hourly rows (bulk upsert)
        $hourlyRows = [];
        $nowStr = now()->toDateTimeString();
        foreach ($data['forecast_hourly'] as $h) {
            $fTime = Carbon::parse($h['forecast_time']);
            $dateStr = $fTime->format('Y-m-d');
            $dailyId = $persistedDailyMap[$dateStr] ?? null;

            $hourlyRows[] = [
                'site_lat' => $lat,
                'site_lon' => $lon,
                'forecast_time' => $fTime->toDateTimeString(),
                'forecast_daily_id' => $dailyId,
                'issued_at' => $issuedTimestamp->toDateTimeString(),
                'lead_hours' => $h['lead_hours'],
                'hs_source' => $h['hs']['source'],
                'hs_p10' => $h['hs']['p10'],
                'hs_p50' => $h['hs']['p50'],
                'hs_p90' => $h['hs']['p90'],
                'current_speed_source' => $h['current_speed']['source'],
                'current_speed_p10' => $h['current_speed']['p10'],
                'current_speed_p50' => $h['current_speed']['p50'],
                'current_speed_p90' => $h['current_speed']['p90'],
                'wind_speed_p50' => $h['wind_speed']['p50'] ?? null,
                'wind_gust_p50' => $h['wind_gust']['p50'] ?? null,
                'slp_p50' => $h['slp']['p50'] ?? null,
                'tp_p50' => $h['tp']['p50'] ?? null,
                'swell_height_p50' => $h['swell_height']['p50'] ?? null,
                'wind_wave_height_p50' => $h['wind_wave_height']['p50'] ?? null,
                'wind_dir_circ_mean_deg' => $h['wind_dir_circ_mean_deg'] ?? null,
                'tier' => $h['tier'],
                'label' => $h['label'],
                'adverse_tail_triggered' => (bool) $h['adverse_tail_triggered'],
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ];
        }

        try {
            foreach (array_chunk($hourlyRows, 100) as $chunk) {
                ForecastHourly::upsert(
                    $chunk,
                    ['site_lat', 'site_lon', 'forecast_time'],
                    [
                        'forecast_daily_id', 'issued_at', 'lead_hours',
                        'hs_source', 'hs_p10', 'hs_p50', 'hs_p90',
                        'current_speed_source', 'current_speed_p10', 'current_speed_p50', 'current_speed_p90',
                        'wind_speed_p50', 'wind_gust_p50', 'slp_p50', 'tp_p50',
                        'swell_height_p50', 'wind_wave_height_p50', 'wind_dir_circ_mean_deg',
                        'tier', 'label', 'adverse_tail_triggered', 'updated_at'
                    ]
                );
            }
        } catch (Exception $ex) {
            Log::error("Failed bulk upsert of ForecastHourly: {$ex->getMessage()}");
        }

        // Cache for 1 hour
        $cacheTtl = (int) config('forecast.circuit_breaker.cache_ttl_seconds', 3600);
        Cache::put($cacheKey, $data, $cacheTtl);

        return $data;
    }

    /**
     * Local climatology result for when the forecast service is down.
     */
    protected function generateLocalClimatologyFallback(
        float $lat,
        float $lon,
        ?string $issuedAt,
        int $days
    ): array
    {
        $t0 = $issuedAt ? Carbon::parse($issuedAt) : Carbon::now('Asia/Manila');
        $totalHours = min(max(1, $days * 24), 384);

        $hourly = [];
        $daily = [];
        $daysSeen = [];

        for ($h = 1; $h <= $totalHours; $h++) {
            $t = $t0->copy()->addHours($h);
            $dStr = $t->format('Y-m-d');

            $hourly[] = [
                'forecast_time' => $t->toIso8601String(),
                'lead_hours' => $h,
                'hs' => ['source' => 'climatology', 'p10' => 0.15, 'p50' => 0.32, 'p90' => 0.65],
                'current_speed' => ['source' => 'climatology', 'p10' => 0.16, 'p50' => 0.35, 'p90' => 0.68],
                'wind_speed' => ['source' => 'climatology', 'raw_si_unit' => 'm/s', 'raw_p50_ms' => 4.17, 'p10' => 2.22, 'p50' => 4.17, 'p90' => 6.67, 'p10_kmh' => 8.0, 'p50_kmh' => 15.0, 'p90_kmh' => 24.0],
                'wind_gust' => ['source' => 'climatology', 'raw_si_unit' => 'm/s', 'raw_p50_ms' => 6.11, 'p10' => 3.33, 'p50' => 6.11, 'p90' => 9.72, 'p10_kmh' => 12.0, 'p50_kmh' => 22.0, 'p90_kmh' => 35.0],
                'slp' => ['source' => 'climatology', 'p10' => 1008.0, 'p50' => 1010.5, 'p90' => 1013.0],
                'tp' => ['source' => 'climatology', 'p10' => 4.5, 'p50' => 6.2, 'p90' => 8.5],
                'swell_height' => ['source' => 'climatology', 'p10' => 0.08, 'p50' => 0.20, 'p90' => 0.45],
                'wind_wave_height' => ['source' => 'climatology', 'p10' => 0.10, 'p50' => 0.25, 'p90' => 0.50],
                'wind_dir_circ_mean_deg' => 65.0,
                'wind_dir_resultant_R' => 0.75,
            ];

            if (!isset($daysSeen[$dStr])) {
                $daysSeen[$dStr] = true;
                $daily[] = [
                    'date' => $dStr,
                    'day_of_year' => $t->dayOfYear,
                    'rain_daily_mm_p50' => 3.5,
                    'rain_daily_mm_p90' => 25.0,
                    'p_wet' => 0.45,
                    'p_wet_ci' => [0.35, 0.55],
                    'p_high_gust' => 0.05,
                    'p_high_gust_ci' => [0.02, 0.12],
                    'prob_band_0_offshore_nne' => 0.30,
                    'prob_band_1_ese' => 0.25,
                    'prob_band_2_s_wnw' => 0.25,
                    'prob_band_3_onshore_habagat' => 0.20,
                ];
            }
        }

        return [
            'site' => [
                'name' => 'Camp FreedivePH (Bagalangit / Mainit Point, Batangas)',
                'latitude' => $lat,
                'longitude' => $lon,
                'timezone' => 'Asia/Manila',
            ],
            'issued_at' => $t0->toIso8601String(),
            'degraded' => true,
            'degraded_reason' => 'Local fallback generated due to remote microservice degradation or offline status',
            'operational_cutoffs' => [
                'hs_hours' => 48,
                'current_speed_hours' => 72,
                'rule' => 'Beyond cutoff, variable source transitions to climatology.',
            ],
            'forecast_hourly' => $hourly,
            'forecast_daily' => $daily,
        ];
    }
}
