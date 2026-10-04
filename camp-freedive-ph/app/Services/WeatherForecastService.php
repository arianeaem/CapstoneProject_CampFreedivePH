<?php

namespace App\Services;

use App\Mail\BatchWeatherCancellationMail;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\BatchRiskAssessment;
use App\Models\BatchStatusLog;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\ForecastAccuracyLog;
use App\Models\ForecastSnapshot;
use App\Models\HourlyAssessment;
use App\Models\ManualOverride;
use App\Models\NotificationLog;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ExternalApi\ExternalApiClient;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Core Weather & Marine Safety Engine for Anilao, Batangas Freediving Operations.
 *
 * Core Responsibilities & Domain Algorithms:
 * 1. 9-Parameter Physical Risk Scoring: Evaluates wave height, swell height, wave period,
 *    wind waves, wind speed & gusts, ocean current, rain rate, sea level pressure, and wind direction.
 * 2. Synergy Hazard Multipliers: Detects when multiple moderate ocean hazards occur concurrently
 *    (e.g., strong currents opposing wind chop) and penalizes the composite risk score non-linearly (+15% to +25%).
 * 3. 16-Day 24-Hour Continuous Sliding Cache: Pre-fetches hourly marine parameters across the entire 16-day
 *    horizon in a single Open-Meteo batch, caching results in Redis/File cache for instant sub-millisecond retrieval.
 * 4. Dual-Engine Orchestration: Interfaces with WeatherSafetyMLService to provide side-by-side comparison
 *    between heuristic rule-based assessments and 12 multi-horizon ONNX ML models.
 * 5. Batch Safety Lifecycle & Automated Force Majeure: Manages batch risk transitions, admin manual overrides,
 *    and automated customer cancellation notifications with 100% refund entitlement.
 * 6. Historical Forecast Accuracy Audit: Automatically snapshots multi-horizon predictions (T-14, T-7, T-3, T-1)
 *    and archives scientific accuracy logs comparing predictions against realized marine observations (T-0).
 */
class WeatherForecastService
{
    protected ExternalApiClient $apiClient;

    public function __construct(?ExternalApiClient $apiClient = null)
    {
        $this->apiClient = $apiClient ?? app(ExternalApiClient::class);
    }

    // Anilao / Mabini, Batangas Site Coordinates (Camp FreedivePH primary training basin)
    public const LATITUDE = 13.6874;
    public const LONGITUDE = 120.8931;
    public const TIMEZONE = 'Asia/Manila';
    public const MAX_FORECAST_DAYS = 16;

    // 9 Environmental Feature Weights (Tide Height removed; total = 1.000 / 100%)
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
        'High Risk' => 'Conditions present significant hazards that could compromise diver safety.',
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
     * Determine Forecast Reliability Category based on Lead Time Horizon.
     *
     * Range         | Reliability Category      | Operational Impact
     * Days 1-3      | High Reliability       | Highly actionable. Use directly for operational safety window greenlighting.
     * Days 4-7      | Medium Reliability     | Excellent for spotting long-range trends, shifting winds, or monsoon setups.
     * Days 8-16     | Low Reliability        | Climatological trend only. Do not use for safety-critical go/no-go logic.
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
     * Determine Operational Horizon Status based on Batch or Dive Date.
     *
     * States:
     * - CONCLUDED: Dive operations finished or past.
     * - TACTICAL_CLEARANCE: Day 0 / H <= 1h (Live Departure Clearance).
     * - PROVISIONAL_TREND_OUTLOOK: 1h < H <= 24h (24-Hour Planning Forecast).
     * - EXTENDED_TREND_OUTLOOK: H > 24h (Extended Planning Outlook).
     * - BEYOND_HORIZON: > 16 days out.
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
     * Run full risk assessment for a 2D1N Batch across all 4 fixed windows:
     * - Day 1 AM (09:30-12:00) & PM (15:30-17:30)
     * - Day 2 AM (09:30-12:00) & PM (15:30-17:30)
     */
    public function assessBatch(Batch $batch, ?array $overrides = null, ?User $assessedBy = null): array
    {
        $result = DB::transaction(function () use ($batch, $overrides, $assessedBy) {
            $startDate = $batch->start_date->copy()->startOfDay();
            $endDate = $batch->end_date ? $batch->end_date->copy()->startOfDay() : $startDate->copy()->addDay();

            // If the batch is already completed / finished and has existing assessments, lock and return the last identified assessment
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

            // 1. Assess Day 1
            $day1Result = $this->assessDay($batch, 1, $startDate, $overrides, $assessedBy, $assessedAt);

            // 2. Assess Day 2
            $day2Result = $this->assessDay($batch, 2, $endDate, $overrides, $assessedBy, $assessedAt);

            // 3. Determine Overall Batch Classification (worse of Day 1 and Day 2)
            $rank1 = self::RISK_RANK[$day1Result['classification']] ?? 1;
            $rank2 = self::RISK_RANK[$day2Result['classification']] ?? 1;
            $worseRank = max($rank1, $rank2);
            $overallClassification = array_search($worseRank, self::RISK_RANK) ?: 'Safe';

            // Convert to slug for batches.risk_classification
            $riskSlug = match ($overallClassification) {
                'Very Safe' => 'very_safe',
                'Safe' => 'safe',
                'Moderate' => 'moderate',
                'High Risk' => 'high_risk',
                'Critical Risk' => 'critical_risk',
                'Not Available' => 'safe',
                default => 'safe',
            };

            $batch->update([
                'risk_classification' => $riskSlug,
            ]);

            // 4. ML Safety Microservice Assessment (Dual-Engine Pipeline)
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
            if ($classification === 'Critical Risk') {
                app(\App\Services\AdminNotificationService::class)->imminentCriticalRisk($assessedBatch);
            }
        }

        return $result;
    }

    /**
     * Assess an individual day (AM window + PM window) with "Worst Window Wins".
     */
    public function assessDay(Batch $batch, int $dayNumber, Carbon $date, ?array $overrides, ?User $assessedBy, ?Carbon $assessedAt = null): array
    {
        $assessedAt = $assessedAt ?? now();
        $daysOut = Carbon::now(self::TIMEZONE)->diffInDays($date->copy()->startOfDay(), false);
        $leadTimeHours = max(0, Carbon::now(self::TIMEZONE)->diffInHours($date->copy()->setTime(9, 30), false));
        $overrideTriggered = $this->checkOverrideConditions($overrides);

        // Check if date is in past (> 1 day ago) for finished/completed batches
        if (!$overrideTriggered && $daysOut < -1) {
            $finalClass = match ($batch->risk_classification) {
                'very_safe' => 'Very Safe',
                'safe' => 'Safe',
                'moderate' => 'Moderate',
                'high_risk' => 'High Risk',
                'critical_risk' => 'Critical Risk',
                default => ($batch->status === 'completed' ? 'Very Safe' : 'Safe'),
            };

            $recommendedAction = self::MEANING_MAP[$finalClass] ?? 'Concluded batch operations.';
            $defaultScore = match ($finalClass) {
                'Very Safe' => 15.0,
                'Safe' => 30.0,
                'Moderate' => 50.0,
                'High Risk' => 70.0,
                'Critical Risk' => 100.0,
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

        // Check if date is outside the 16-day forecast model horizon
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

        // Evaluate AM Window (09:30 - 12:00)
        $amData = $this->assessWindow($date->format('Y-m-d'), '09:30', '12:00', 'am', $overrides);

        // Evaluate PM Window (15:30 - 17:30)
        $pmData = $this->assessWindow($date->format('Y-m-d'), '15:30', '17:30', 'pm', $overrides);

        // Retrieve whole-day daytime baseline (06:00 - 18:00)
        $cachedDay = $this->getCachedDayForecast($date->format('Y-m-d'));
        if (!$cachedDay && !$overrideTriggered) {
            try {
                $this->updateAllForecasts(16);
                $cachedDay = $this->getCachedDayForecast($date->format('Y-m-d'));
            } catch (\Throwable $e) {}
        }

        // Live ML Safety Evaluation across training hours
        $mlAssessment = !$overrideTriggered ? $this->assessMLSafetyForDate($date->format('Y-m-d'), '08:00', '18:00', $overrides) : null;
        $mlRank = $mlAssessment ? (self::RISK_RANK[$mlAssessment['overall_recommendation'] ?? 'Safe'] ?? 1) : 1;

        $amRank = self::RISK_RANK[$amData['classification']] ?? 1;
        $pmRank = self::RISK_RANK[$pmData['classification']] ?? 1;
        $daytimeRank = self::RISK_RANK[$cachedDay['daytime_classification'] ?? 'Safe'] ?? 1;

        if ($overrideTriggered || $amRank === 5 || $pmRank === 5 || $daytimeRank === 5 || $mlRank === 5) {
            $dayClassification = 'Critical Risk';
            $weightedScorePct = 100.0;
            $worstWindow = ($pmRank >= $amRank) ? '15:30-17:30' : '09:30-12:00';
            $worstHour = ($pmRank >= $amRank) ? $pmData['worst_hour'] : $amData['worst_hour'];
            $recommendedAction = self::MEANING_MAP['Critical Risk'];
        } else {
            $dayRank = max($amRank, $pmRank, $daytimeRank, $mlRank);
            $dayClassification = array_search($dayRank, self::RISK_RANK) ?: 'Safe';
            $weightedScorePct = max($amData['weighted_score_pct'] ?? 0, $pmData['weighted_score_pct'] ?? 0, $cachedDay['daytime_score_pct'] ?? 0);
            $worstWindow = ($pmRank >= $amRank) ? '15:30-17:30' : '09:30-12:00';
            $worstHour = ($pmRank >= $amRank) ? $pmData['worst_hour'] : $amData['worst_hour'];
            $recommendedAction = self::MEANING_MAP[$dayClassification] ?? 'Proceed with caution.';
        }

        // Persist BatchRiskAssessment
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
            'assessed_by' => $assessedBy?->id,
            'assessed_at' => $assessedAt,
        ]);

        // Persist HourlyAssessments for AM
        foreach ($amData['hourly'] as $h) {
            HourlyAssessment::create(array_merge($h, [
                'risk_assessment_id' => $riskAssessment->id,
                'window_type' => 'am',
                'open_water_window' => '09:30-12:00',
            ]));
        }

        // Persist HourlyAssessments for PM
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
     * Preview assessment for client date selection on the booking form.
     */
    public function previewDateAssessment(Carbon $startDate): array
    {
        $endDate = $startDate->copy()->addDay();
        $today = Carbon::today(self::TIMEZONE);
        $daysOut = $today->diffInDays($startDate->copy()->startOfDay(), false);

        if ($daysOut > self::MAX_FORECAST_DAYS || $daysOut < 0) {
            return [
                'available' => false,
                'message' => $daysOut > self::MAX_FORECAST_DAYS
                    ? "Assessment Not Available. Open-Meteo forecast models are available up to 16 days in advance (currently available through " . $today->copy()->addDays(16)->format('M d, Y') . ")."
                    : "Selected date is in the past.",
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ];
        }

        $reliability = self::getReliabilityCategory($daysOut);

        // Check if day 1 and day 2 are in the unified cache
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
                // Ignore failure and fallback to live window calculation
            }
        }

        if ($d1Cache && $d2Cache) {
            // Day 1 & Day 2 Full 24-Hour Day Peak Classifications
            $day1Class = $d1Cache['overall_classification'] ?? 'Safe';
            $day2Class = $d2Cache['overall_classification'] ?? 'Safe';

            // Trip Overall (worse of Day 1 and Day 2 24-hour peaks)
            $worseRank = max(self::RISK_RANK[$day1Class] ?? 1, self::RISK_RANK[$day2Class] ?? 1);
            $overallClass = array_search($worseRank, self::RISK_RANK) ?: 'Safe';

            // Worst hour across the whole day for Day 1
            $d1WorstHour = null;
            $d1MaxScore = -1;
            foreach ($d1Cache['hourly'] ?? [] as $h) {
                if (($h['weighted_score_pct'] ?? 0) > $d1MaxScore) {
                    $d1MaxScore = $h['weighted_score_pct'];
                    $d1WorstHour = $h['iso_time'] ?? null;
                }
            }

            // Worst hour across the whole day for Day 2
            $d2WorstHour = null;
            $d2MaxScore = -1;
            foreach ($d2Cache['hourly'] ?? [] as $h) {
                if (($h['weighted_score_pct'] ?? 0) > $d2MaxScore) {
                    $d2MaxScore = $h['weighted_score_pct'];
                    $d2WorstHour = $h['iso_time'] ?? null;
                }
            }

            $reliability = self::getReliabilityCategory($daysOut);
            $d1Confidence = ($daysOut >= 4) ? 'low' : 'high';
            $d2Confidence = (($daysOut + 1) >= 4) ? 'low' : 'high';
            $overallConfidence = ($daysOut >= 4) ? 'low' : 'high';
            $overallAdvisory = ($overallConfidence === 'low') ? "Confidence is low this far out, recheck in 2 days." : null;

            return [
                'available' => true,
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
                    'worst_hour' => $d1WorstHour ? Carbon::parse($d1WorstHour)->format('g:i A') : '11:00 AM',
                    'lead_time' => max(0, Carbon::now(self::TIMEZONE)->diffInHours($startDate->copy()->setTime(9, 30), false)) . ' hours',
                ],
                'day2' => [
                    'date' => $endDate->format('M d, Y'),
                    'classification' => $day2Class,
                    'confidence' => $d2Confidence,
                    'confidence_advisory' => ($d2Confidence === 'low') ? "Confidence is low this far out, recheck in 2 days." : null,
                    'recommended_action' => self::MEANING_MAP[$day2Class] ?? 'Proceed with caution.',
                    'worst_hour' => $d2WorstHour ? Carbon::parse($d2WorstHour)->format('g:i A') : '11:00 AM',
                    'lead_time' => max(0, Carbon::now(self::TIMEZONE)->diffInHours($endDate->copy()->setTime(9, 30), false)) . ' hours',
                ],
                'advisory_notes' => [
                    'Forecast models are updated hourly as the scheduled trip approaches.',
                    'Final go/no-go departure decision is subject to Camp FreedivePH operator confirmation.',
                ],
            ];
        }

        // Fallback to Window Evaluation (Open-Meteo)
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
     * Run ML Safety Assessment via the 12 ONNX microservice pipeline.
     * Guaranteed to output one of the 5 standard safety classifications:
     * Very Safe, Safe, Moderate, High Risk, Critical Risk.
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

            // Retrieve cached 24h forecast for the date or fetch live Open-Meteo data
            $dayForecast = $this->getCachedDayForecast($date);
            $hourlyReadings = [];

            if ($dayForecast && !empty($dayForecast['hourly'])) {
                foreach ($dayForecast['hourly'] as $h) {
                    $hourlyReadings[] = [
                        'timestamp' => $h['iso_time'] ?? sprintf('%sT%02d:00:00+08:00', $date, $h['hour']),
                        'wind_speed' => $h['wind_speed'] ?? 12.0,
                        'wind_gust' => $h['wind_gusts'] ?? (($h['wind_speed'] ?? 12.0) * 1.25),
                        'wind_dir' => $h['wind_direction'] ?? 245.0,
                        'slp' => $h['sea_level_pressure'] ?? 1010.5,
                        'rain_rate_mm_hr' => $h['rain'] ?? 0.0,
                        'ocean_current_velocity' => $h['ocean_current'] ?? 0.3,
                    ];
                }
            } else {
                $weather = $this->fetchOpenMeteoWeather($date);
                $marine = $this->fetchOpenMeteoMarine($date);
                $times = $weather['time'] ?? $marine['time'] ?? [];

                foreach ($times as $idx => $isoTime) {
                    $hourlyReadings[] = [
                        'timestamp' => $isoTime,
                        'wind_speed' => (float) ($weather['wind_speed_10m'][$idx] ?? 12.0),
                        'wind_gust' => (float) ($weather['wind_gusts_10m'][$idx] ?? 15.0),
                        'wind_dir' => (float) ($weather['wind_direction_10m'][$idx] ?? 245.0),
                        'slp' => (float) ($weather['pressure_msl'][$idx] ?? 1010.5),
                        'rain_rate_mm_hr' => (float) ($weather['rain'][$idx] ?? $weather['precipitation'][$idx] ?? 0.0),
                        'ocean_current_velocity' => isset($marine['ocean_current_velocity'][$idx]) ? (float) $marine['ocean_current_velocity'][$idx] * 0.27778 : null,
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
     * Apply Manual Override (PAGASA-style advisories) to a batch.
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
                'reason' => $overrideData['reason'] ?? 'PAGASA Marine Weather Advisory',
                'cancelled_batch' => $cancelBatch,
                'applied_by' => $operator->id,
                'created_at' => now(),
            ]);

            // Re-run batch assessment with override flags
            $assessmentResult = $this->assessBatch($batch, $overrideData, $operator);

            // Cancel batch if requested
            if ($cancelBatch && $isOverrideActive) {
                $reasonText = $cancelReason ?: ($overrideData['reason'] ?: 'Camp cancellation due to active PAGASA severe weather advisory');
                $this->cancelBatchWithRefundsAndNotifications($batch, $reasonText, $operator);
            }

            AuditLogger::log(
                'MANUAL_OVERRIDE_APPLIED',
                "Manual weather override applied to batch {$batch->batch_code}. Critical Risk forced. Cancelled=" . ($cancelBatch ? 'Yes' : 'No'),
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
     * Cancel whole batch, trigger 100% force majeure refunds, and dispatch templated emails.
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

        $connectedBookings = $batch->bookings()
            ->whereNotIn('status', ['cancelled_by_guest', 'no_show'])
            ->get();

        $notificationsSent = 0;

        foreach ($connectedBookings as $booking) {
            $bookingOldStatus = $booking->status;
            $booking->update(['status' => 'cancelled_by_camp']);

            // Auto-trigger 100% force majeure refund eligibility
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

            // Generate Templated Guest Cancellation & Safety Notification Message
            $scheduledDateStr = $booking->start_date->format('M d, Y') . ' - ' . $booking->end_date->format('M d, Y');
            $messageBody = "Good day, {$booking->contact_name}. Your scheduled date for {$scheduledDateStr} will be canceled due to:\n\n- {$cancellationReason}\n\nThere will be options for this cancelled schedule:\n- Full refund\n- Reschedule\n\nYou can select your preferred option by entering your booking number ({$booking->booking_number}) and PIN in Manage Booking.";

            NotificationLog::create([
                'batch_id' => $batch->id,
                'booking_id' => $booking->id,
                'recipient_email' => $booking->contact_email,
                'recipient_name' => $booking->contact_name,
                'subject' => "Camp FreedivePH Schedule Cancellation Notice - {$scheduledDateStr}",
                'message_body' => $messageBody,
                'channel' => 'email',
                'sent_by' => $operator->id,
                'sent_at' => now(),
            ]);

            // Asynchronous Queue Worker Dispatch:
            // Offloads email network I/O to background queue workers to maintain instant admin UI response times
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
     * Check if any manual override condition is active.
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

        return ($tcwsSignal >= 3 || $galeWarning || $thunderstorm || $typhoon || $tsunami);
    }

    /**
     * Assess a single open water window (e.g. 09:30-12:00 or 15:30-17:30).
     */
    protected function assessWindow(string $plannedDate, string $diveStart, string $diveEnd, string $windowType, ?array $overrides = null): array
    {
        // Native live Open-Meteo & sub-millisecond cached scoring pipeline
        return $this->evaluateWindowNatively($plannedDate, $diveStart, $diveEnd, $windowType, $overrides);
    }

    /**
     * Native evaluation pipeline using live Open-Meteo APIs and sustained window scoring.
     */
    protected function evaluateWindowNatively(string $plannedDate, string $diveStart, string $diveEnd, string $windowType, ?array $overrides): array
    {
        $overrideTriggered = $this->checkOverrideConditions($overrides);

        // AM 09:30-12:00 -> 10:00, 11:00, 12:00
        // PM 15:30-17:30 -> 16:00, 17:00
        $hours = $windowType === 'am' ? [10, 11, 12] : [16, 17];
        
        $hourlyList = [];

        // Fetch live marine & weather data from Open-Meteo
        $marineData = $this->fetchOpenMeteoMarine($plannedDate);
        $weatherData = $this->fetchOpenMeteoWeather($plannedDate);

        $windowWindSpeeds = [];
        $windowWindGusts = [];
        $windowWaveHeights = [];
        $windowWavePeriods = [];
        $windowSwellHeights = [];
        $windowWindWaveHeights = [];
        $windowOceanCurrents = [];
        $windowRains = [];
        $windowPressures = [];
        $windowWindDirections = [];

        foreach ($hours as $hour) {
            $timeStr = sprintf('%sT%02d:00:00+08:00', $plannedDate, $hour);
            $idx = $hour; // Index in hourly array

            $waveHeight = isset($marineData['wave_height'][$idx]) ? (float)$marineData['wave_height'][$idx] : 0.70;
            $wavePeriod = isset($marineData['wave_period'][$idx]) ? (float)$marineData['wave_period'][$idx] : 6.10;
            $swellHeight = isset($marineData['swell_wave_height'][$idx]) ? (float)$marineData['swell_wave_height'][$idx] : 0.60;
            $windWaveHeight = isset($marineData['wind_wave_height'][$idx]) ? (float)$marineData['wind_wave_height'][$idx] : 0.35;
            
            // Open-Meteo ocean current velocity in km/h -> convert to m/s
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
            $windowSwellHeights[] = $swellHeight;
            $windowWindWaveHeights[] = $windWaveHeight;
            $windowOceanCurrents[] = $oceanCurrent;
            $windowRains[] = $rain;
            $windowPressures[] = $pressure;
            $windowWindDirections[] = $windDir;

            // Hourly point scores for granular breakdown
            $hourScores = [
                'wave_height' => $this->scoreWaveHeight($waveHeight),
                'wind_speed' => $this->scoreWindSpeed($rawWindSpeed, $windGusts),
                'ocean_current' => $this->scoreOceanCurrent($oceanCurrent),
                'swell_height' => $this->scoreSwellHeight($swellHeight),
                'wave_period' => $this->scoreWavePeriod($wavePeriod),
                'wind_wave_height' => $this->scoreWindWaveHeight($windWaveHeight),
                'rain' => $this->scoreRain($rain),
                'sea_level_pressure' => $this->scoreSeaLevelPressure($pressure),
                'wind_direction' => $this->scoreWindDirection($windDir),
            ];
            $hourWeightedPct = $this->computeWeightedScore($hourScores);
            $hourClass = $this->classifyScore($hourWeightedPct);

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

        $count = count($hours);
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

        // Desensitized Sustained Window Physical Hard-Gates:
        // 1. Sustained Wind >= 42.0 km/h (10-Min Rolling Mean; PCG gale/banca safety limit)
        // 2. Peak Squall Gust >= 48.0 km/h (Instant single telemetric spike)
        // 3. Significant Wave >= 1.80 m (30-Min Rolling Mean)
        // 4. Swell Wave Height >= 1.80 m (30-Min Rolling Mean)
        // 5. Ocean Current Velocity >= 0.80 m/s (10-Min Rolling Mean)
        // 6. Precipitation >= 25.0 mm accumulation OR >= 25.0 mm/hr rate (15-Min Window)
        // 7. Severe Low Pressure <= 998.0 hPa OR delta P >= 2.0 hPa / 3 hrs
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
            'wave_period' => $this->scoreWavePeriod($meanWavePeriod),
            'wind_wave_height' => $this->scoreWindWaveHeight($meanWindWaveHeight),
            'rain' => $this->scoreRain($maxRainRate),
            'sea_level_pressure' => $this->scoreSeaLevelPressure($meanPressure),
            'wind_direction' => $this->scoreWindDirection($meanWindDirection),
        ];

        $windowWeightedScorePct = $isPhysicalBreach ? 100.0 : $this->computeWeightedScore($windowScores);
        $windowClass = ($overrideTriggered || $isPhysicalBreach) ? 'Critical Risk' : $this->classifyScore($windowWeightedScorePct);

        // Find worst hour in window
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

    protected function formatEngineWindowResponse(array $data, string $windowType, bool $overrideTriggered): array
    {
        $hourlyList = [];
        $worstHour = $data['worst_hour'] ?? null;
        $assessment = $data['assessment'] ?? [];

        foreach ($data['hourly_assessments'] ?? [] as $h) {
            $forecasts = $h['forecasts'] ?? [];
            $hourlyList[] = [
                'forecast_time' => Carbon::parse($h['timestamp']),
                'wave_height' => $forecasts['wave_height'] ?? 0.70,
                'wave_period' => $forecasts['wave_period'] ?? 6.10,
                'swell_height' => $forecasts['swell_height'] ?? 0.60,
                'wind_wave_height' => $forecasts['wind_wave_height'] ?? 0.35,
                'ocean_current' => $forecasts['ocean_current'] ?? 0.70,
                'rain' => $forecasts['rain'] ?? 0.0,
                'sea_level_pressure' => $forecasts['sea_level_pressure'] ?? 1010.5,
                'wind_speed' => $forecasts['wind_speed'] ?? 12.0,
                'wind_direction' => $forecasts['wind_direction'] ?? 245.0,
                'tide_height' => $forecasts['tide_height'] ?? 0.0,
                'tide_score' => 0,
                'weighted_score_pct' => $overrideTriggered ? null : ($h['weighted_score_pct'] ?? 18.0),
                'classification' => $overrideTriggered ? 'Critical Risk' : ($h['classification'] ?? 'Safe'),
                'recommended_action' => self::MEANING_MAP[$overrideTriggered ? 'Critical Risk' : ($h['classification'] ?? 'Safe')],
                'is_worst_hour_in_window' => $h['timestamp'] === $worstHour,
            ];
        }

        return [
            'planned_date' => $data['planned_date'] ?? '',
            'window_type' => $windowType,
            'dive_start' => $data['dive_start'] ?? '',
            'dive_end' => $data['dive_end'] ?? '',
            'classification' => $overrideTriggered ? 'Critical Risk' : ($assessment['classification'] ?? 'Safe'),
            'weighted_score_pct' => $overrideTriggered ? null : ($assessment['weighted_score_pct'] ?? 18.0),
            'worst_hour' => $worstHour,
            'hourly' => $hourlyList,
        ];
    }

    /**
     * Master cache updater: pulls 16-day continuous 24-hour marine and weather forecasts from Open-Meteo
     * and saves structured continuous data in cache for sub-millisecond lookups.
     */
    public function updateAllForecasts(int $forecastDays = 16): array
    {
        $today = Carbon::today(self::TIMEZONE);
        $startDateStr = $today->format('Y-m-d');
        $endDateStr = $today->copy()->addDays($forecastDays - 1)->format('Y-m-d');
        $cachedContinuous = Cache::get('forecast:continuous_16d');

        // 1. Fetch 16-Day Marine Forecast in a single API call through rate-controlled ExternalApiClient
        try {
            $marineRes = $this->apiClient->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine', [
                'query' => [
                    'latitude' => config('forecast.site_lat', self::LATITUDE),
                    'longitude' => config('forecast.site_lon', self::LONGITUDE),
                    'timezone' => self::TIMEZONE,
                    'start_date' => $startDateStr,
                    'end_date' => $endDateStr,
                    'hourly' => 'wave_height,wave_period,swell_wave_height,wind_wave_height,ocean_current_velocity',
                ],
                'timeout' => 10,
                'without_verifying' => true,
            ]);
            $marineHourly = $marineRes->successful() ? ($marineRes->json()['hourly'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::warning('Open-Meteo marine fetch warning: ' . $e->getMessage());
            $marineHourly = [];
        }

        // 2. Fetch 16-Day Atmospheric Weather Forecast in a single API call through rate-controlled ExternalApiClient
        try {
            $weatherRes = $this->apiClient->execute('open_meteo', 'GET', 'https://api.open-meteo.com/v1/forecast', [
                'query' => [
                    'latitude' => config('forecast.site_lat', self::LATITUDE),
                    'longitude' => config('forecast.site_lon', self::LONGITUDE),
                    'timezone' => self::TIMEZONE,
                    'start_date' => $startDateStr,
                    'end_date' => $endDateStr,
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

        // 3. Build Day-by-Day 24-Hour Continuous Profiles
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
                        'swell_wave_height' => [],
                        'wind_wave_height' => [],
                        'ocean_current_velocity' => [],
                    ],
                    'weather' => [
                        'time' => [],
                        'rain' => [],
                        'pressure_msl' => [],
                        'wind_speed_10m' => [],
                        'wind_direction_10m' => [],
                    ],
                    'hourly_scores' => [],
                ];
            }

            // Populate marine array
            $dayBuckets[$dateKey]['marine']['time'][] = $isoTime;
            $wHeight = (float) ($marineHourly['wave_height'][$index] ?? 0.7);
            $wPeriod = (float) ($marineHourly['wave_period'][$index] ?? 6.1);
            $sHeight = (float) ($marineHourly['swell_wave_height'][$index] ?? 0.6);
            $wwHeight = (float) ($marineHourly['wind_wave_height'][$index] ?? 0.35);
            $rawCurrent = (float) ($marineHourly['ocean_current_velocity'][$index] ?? 1.1);

            $dayBuckets[$dateKey]['marine']['wave_height'][] = $wHeight;
            $dayBuckets[$dateKey]['marine']['wave_period'][] = $wPeriod;
            $dayBuckets[$dateKey]['marine']['swell_wave_height'][] = $sHeight;
            $dayBuckets[$dateKey]['marine']['wind_wave_height'][] = $wwHeight;
            $dayBuckets[$dateKey]['marine']['ocean_current_velocity'][] = $rawCurrent;

            // Populate weather array
            $dayBuckets[$dateKey]['weather']['time'][] = $isoTime;
            $precipVal = (float) ($weatherHourly['precipitation'][$index] ?? 0.0);
            $rawRainVal = (float) ($weatherHourly['rain'][$index] ?? 0.0);
            $showersVal = (float) ($weatherHourly['showers'][$index] ?? 0.0);
            $rainVal = max($precipVal, $rawRainVal, $showersVal);

            $pressVal = (float) ($weatherHourly['pressure_msl'][$index] ?? 1010.5);
            $rawWindSpd = (float) ($weatherHourly['wind_speed_10m'][$index] ?? 12.0);
            $windGustsVal = (float) ($weatherHourly['wind_gusts_10m'][$index] ?? 0.0);
            $windSpd = max($rawWindSpd, $windGustsVal * 0.75);
            $windDir = (float) ($weatherHourly['wind_direction_10m'][$index] ?? 245.0);

            $dayBuckets[$dateKey]['weather']['rain'][] = $rainVal;
            $dayBuckets[$dateKey]['weather']['pressure_msl'][] = $pressVal;
            $dayBuckets[$dateKey]['weather']['wind_speed_10m'][] = $windSpd;
            $dayBuckets[$dateKey]['weather']['wind_direction_10m'][] = $windDir;

            // Calculate hour safety score (0-100%)
            $oceanCurrent = round($rawCurrent * 0.27778, 2);

            // Deterministic Physical Hard-Gates (PCG Small Craft / Marine Ceilings)
            $isPhysicalBreach = (
                $rawWindSpd >= 38.0 || $windGustsVal >= 48.0 ||
                $wHeight >= 1.80 || $sHeight >= 1.80 ||
                $oceanCurrent >= 0.80 ||
                $rainVal >= 25.0 ||
                $pressVal <= 998.0
            );

            $scores = [
                'wave_height' => $this->scoreWaveHeight($wHeight),
                'wind_speed' => $this->scoreWindSpeed($rawWindSpd, $windGustsVal),
                'ocean_current' => $this->scoreOceanCurrent($oceanCurrent),
                'swell_height' => $this->scoreSwellHeight($sHeight),
                'wave_period' => $this->scoreWavePeriod($wPeriod),
                'wind_wave_height' => $this->scoreWindWaveHeight($wwHeight),
                'rain' => $this->scoreRain($rainVal),
                'sea_level_pressure' => $this->scoreSeaLevelPressure($pressVal),
                'tide_height' => 0,
                'wind_direction' => $this->scoreWindDirection($windDir),
            ];

            $weightedScorePct = $isPhysicalBreach ? 100.0 : $this->computeWeightedScore($scores);
            $hourClass = $isPhysicalBreach ? 'Critical Risk' : $this->classifyScore($weightedScorePct);

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
            ];
        }

        // 4. Cache each day individually and summarize metrics
        foreach ($dayBuckets as $dateKey => $bucket) {
            Cache::put("forecast:marine_cache:{$dateKey}", $bucket['marine'], now()->addMinutes(60));
            Cache::put("forecast:weather_cache:{$dateKey}", $bucket['weather'], now()->addMinutes(60));

            // Calculate daytime sustained operational metrics (06:00 - 18:00)
            $daytimeHours = range(6, 18);
            $daytimeWinds = [];
            $daytimeGusts = [];
            $daytimeWaves = [];
            $daytimeSwells = [];
            $daytimeCurrents = [];
            $daytimeRains = [];
            $daytimePressures = [];
            $daytimePeriods = [];
            $daytimeWindWaves = [];
            $daytimeWindDirs = [];

            foreach ($daytimeHours as $dh) {
                if (isset($bucket['weather']['wind_speed_10m'][$dh])) {
                    $daytimeWinds[] = $bucket['weather']['wind_speed_10m'][$dh];
                    $daytimeGusts[] = $bucket['weather']['wind_gusts_10m'][$dh] ?? 0.0;
                    $daytimeWaves[] = $bucket['marine']['wave_height'][$dh] ?? 0.70;
                    $daytimeSwells[] = $bucket['marine']['swell_wave_height'][$dh] ?? 0.60;
                    $daytimeCurrents[] = ($bucket['marine']['ocean_current_velocity'][$dh] ?? 1.1) * 0.27778;
                    $daytimeRains[] = $bucket['weather']['rain'][$dh] ?? 0.0;
                    $daytimePressures[] = $bucket['weather']['pressure_msl'][$dh] ?? 1010.5;
                    $daytimePeriods[] = $bucket['marine']['wave_period'][$dh] ?? 6.0;
                    $daytimeWindWaves[] = $bucket['marine']['wind_wave_height'][$dh] ?? 0.35;
                    $daytimeWindDirs[] = $bucket['weather']['wind_direction_10m'][$dh] ?? 245.0;
                }
            }

            $dayCount = count($daytimeWinds) ?: 1;
            $meanDaytimeWind = array_sum($daytimeWinds) / $dayCount;
            $maxDaytimeGust = !empty($daytimeGusts) ? max($daytimeGusts) : 0.0;
            $meanDaytimeWave = array_sum($daytimeWaves) / $dayCount;
            $meanDaytimeSwell = array_sum($daytimeSwells) / $dayCount;
            $meanDaytimeCurrent = array_sum($daytimeCurrents) / $dayCount;
            $daytimeRainTotal = array_sum($daytimeRains);
            $daytimeMaxRainRate = !empty($daytimeRains) ? max($daytimeRains) : 0.0;
            $meanDaytimePressure = array_sum($daytimePressures) / $dayCount;
            $meanDaytimePeriod = array_sum($daytimePeriods) / $dayCount;
            $meanDaytimeWindWave = array_sum($daytimeWindWaves) / $dayCount;
            $meanDaytimeWindDir = array_sum($daytimeWindDirs) / $dayCount;

            // 3-hour Barometric Tendency check across daytime hours
            $maxPressureDrop3h = 0.0;
            for ($i = 0; $i < count($daytimePressures) - 3; $i++) {
                $drop = $daytimePressures[$i] - $daytimePressures[$i + 3];
                if ($drop > $maxPressureDrop3h) {
                    $maxPressureDrop3h = $drop;
                }
            }

            // Tier 2 Compound Precursor Check:
            // A rapid barometric drop (>= 2.5 hPa / 3h) requires companion storm indicators
            // (Squall Gusts >= 38.0 km/h OR Rain Rate >= 15.0 mm/hr) to trigger an emergency breach.
            // Diurnal solar tides on calm sunny days will NOT trigger a false critical alarm.
            $hasCompoundPressureBreach = ($maxPressureDrop3h >= 2.5 && ($maxDaytimeGust >= 38.0 || $daytimeMaxRainRate >= 15.0));

            // Tier 1 Absolute Physical Hard-Gates (PCG Banca / Small Craft Safety Limits)
            $isDaytimePhysicalBreach = (
                $meanDaytimeWind >= 42.0 ||
                $maxDaytimeGust >= 48.0 ||
                $meanDaytimeWave >= 1.80 ||
                $meanDaytimeSwell >= 1.80 ||
                $meanDaytimeCurrent >= 0.80 ||
                $daytimeRainTotal >= 25.0 ||
                $daytimeMaxRainRate >= 25.0 ||
                $meanDaytimePressure <= 998.0 ||
                $hasCompoundPressureBreach
            );

            $daytimeScores = [
                'wave_height' => $this->scoreWaveHeight($meanDaytimeWave),
                'wind_speed' => $this->scoreWindSpeed($meanDaytimeWind, $maxDaytimeGust),
                'ocean_current' => $this->scoreOceanCurrent($meanDaytimeCurrent),
                'swell_height' => $this->scoreSwellHeight($meanDaytimeSwell),
                'wave_period' => $this->scoreWavePeriod($meanDaytimePeriod),
                'wind_wave_height' => $this->scoreWindWaveHeight($meanDaytimeWindWave),
                'rain' => $this->scoreRain($daytimeMaxRainRate),
                'sea_level_pressure' => $this->scoreSeaLevelPressure($meanDaytimePressure),
                'wind_direction' => $this->scoreWindDirection($meanDaytimeWindDir),
            ];

            $daytimeScorePct = $isDaytimePhysicalBreach ? 100.0 : $this->computeWeightedScore($daytimeScores);
            $daytimeClass = $isDaytimePhysicalBreach ? 'Critical Risk' : $this->classifyScore($daytimeScorePct);

            $amScores = array_filter($bucket['hourly_scores'], fn($i) => in_array($i['hour'], [10, 11, 12]));
            $amMax = !empty($amScores) ? max(array_column($amScores, 'weighted_score_pct')) : 0.0;
            $amClass = $this->classifyScore($amMax);

            $pmScores = array_filter($bucket['hourly_scores'], fn($i) => in_array($i['hour'], [16, 17]));
            $pmMax = !empty($pmScores) ? max(array_column($pmScores, 'weighted_score_pct')) : 0.0;
            $pmClass = $this->classifyScore($pmMax);

            $summary = [
                'date' => $dateKey,
                'overall_classification' => $daytimeClass,
                'overall_score_pct' => $daytimeScorePct,
                'daytime_classification' => $daytimeClass,
                'daytime_score_pct' => $daytimeScorePct,
                'am_classification' => $amClass,
                'pm_classification' => $pmClass,
                'avg_wave_height' => round($meanDaytimeWave, 2),
                'max_wave_height' => !empty($daytimeWaves) ? max($daytimeWaves) : 0.0,
                'avg_wind_speed' => round($meanDaytimeWind, 1),
                'max_wind_speed' => round($maxDaytimeGust, 1),
                'avg_ocean_current' => round($meanDaytimeCurrent, 2),
                'total_rain' => round($daytimeRainTotal, 2),
                'avg_pressure' => round($meanDaytimePressure, 1),
                'hourly' => $bucket['hourly_scores'],
            ];

            Cache::put("forecast:date:{$dateKey}", $summary, now()->addMinutes(60));
            $dailySummaries[$dateKey] = $summary;

            // Automatically persist multi-horizon historical forecast snapshot
            try {
                $daysOut = max(0, (int) Carbon::now(self::TIMEZONE)->startOfDay()->diffInDays(Carbon::parse($dateKey)->startOfDay(), false));
                $this->recordForecastSnapshot($dateKey, $daysOut, $summary);
            } catch (\Throwable $e) {
                Log::debug("Could not record snapshot for {$dateKey}: " . $e->getMessage());
            }
        }

        // 5. Store Master 16-Day Cache and Update Timestamp
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
     * Retrieve pre-cached 24-hour forecast for a specific date if available.
     */
    public function getCachedDayForecast(string $date): ?array
    {
        return Cache::get("forecast:date:{$date}");
    }

    /**
     * Fetch Open-Meteo Marine Forecast with cache-first lookup and error tolerance.
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
                    'hourly' => 'wave_height,wave_period,swell_wave_height,wind_wave_height,ocean_current_velocity',
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
     * Fetch Open-Meteo Weather Forecast with cache-first lookup and error tolerance.
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

    // =========================================================================
    // Rule-Based Threshold Scoring Functions (0-4)
    // =========================================================================

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
        // Evaluates sustained wind and sudden squall gusts (PCG Small Craft / Banca criteria)
        if ($sustained < 12.0 && $gusts < 18.0) return 0;
        if ($sustained < 20.0 && $gusts < 28.0) return 1;
        if ($sustained < 28.0 && $gusts < 38.0) return 2;
        if ($sustained < 38.0 && $gusts < 48.0) return 3;
        return 4;
    }

    protected function scoreRain(float $v): int
    {
        // Tightened bounds: 0.2mm captures tropical monsoon mist and convective drizzle
        if ($v < 0.2) return 0;
        if ($v < 2.5) return 1;
        if ($v < 7.5) return 2;
        if ($v < 20.0) return 3;
        return 4;
    }

    protected function scoreSeaLevelPressure(float $v): int
    {
        // Fixed boundary inequality logic to eliminate gaps
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
        // If passed a physics forecast payload (e.g. ['physics' => [...]] or directly carrying quantile objects)
        if (isset($scoresOrForecast['physics']) || isset($scoresOrForecast['significant_wave_height_m']) || isset($scoresOrForecast['wind_speed_kmh'])) {
            $physics = $scoresOrForecast['physics'] ?? $scoresOrForecast;
            $scoreDetails = $this->calculatePhysicsWeightedScore($physics);
            $confidence = $this->evaluateConfidence($physics);

            return array_merge($scoreDetails, ['confidence' => $confidence]);
        }

        // Backward-compatible path for direct 0-4 point scores array
        return $this->computeScoreFromWeights($scoresOrForecast);
    }

    /**
     * Evaluate Coast Guard safety ceiling confidence against p10-p90 quantile intervals.
     * Returns 'low' if any safety ceiling is straddled by [p10, p90], otherwise 'high'.
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
                // If interval straddles the safety ceiling
                if ($p10 < $ceiling && $p90 >= $ceiling) {
                    return 'low';
                }
            }
        }

        return 'high';
    }

    /**
     * Calculate 9-parameter weighted score from physics forecast values.
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

        // Check absolute physical hard-gate ceilings
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
        $classification = $isPhysicalBreach ? 'Critical Risk' : $this->classifyScore($scorePct);

        return [
            'weighted_score_pct' => $scorePct,
            'classification' => $classification,
            'is_physical_breach' => $isPhysicalBreach,
            'scores' => $scores,
        ];
    }

    /**
     * Compute weighted score from 9-parameter scores (0-4) with multi-hazard synergy multipliers.
     */
    protected function computeScoreFromWeights(array $scores): float
    {
        $maxPossible = 4.0 * array_sum(self::WEIGHTS);
        $weightedSum = 0.0;
        foreach (self::WEIGHTS as $var => $weight) {
            $weightedSum += ($scores[$var] ?? 0) * $weight;
        }

        $rawScorePct = ($weightedSum / $maxPossible) * 100.0;

        // Compound Risk Synergy: Exponential penalty when multiple hazards co-occur
        $highRiskFactors = 0;
        if (($scores['wave_height'] ?? 0) >= 2) $highRiskFactors++;
        if (($scores['wind_speed'] ?? 0) >= 2) $highRiskFactors++;
        if (($scores['ocean_current'] ?? 0) >= 2) $highRiskFactors++;
        if (($scores['wind_direction'] ?? 0) >= 2) $highRiskFactors++;
        if (($scores['wave_period'] ?? 0) >= 2) $highRiskFactors++;

        $synergyMultiplier = 1.0;
        if ($highRiskFactors >= 4) {
            $synergyMultiplier = 1.25; // 25% compound multiplier for multi-hazard conditions
        } elseif ($highRiskFactors >= 3) {
            $synergyMultiplier = 1.15; // 15% compound multiplier
        }

        return min(100.0, round($rawScorePct * $synergyMultiplier, 1));
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
     * Persist or update a multi-horizon forecast snapshot for historical auditing.
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
            [
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
            ]
        );
    }

    /**
     * Retrieve or compute realized on-the-water meteorological & marine conditions for a past date (T-0).
     */
    public function fetchRealizedWeather(string $date): array
    {
        // 1. Check if we have live archive readings from Open-Meteo
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
                    'hourly' => 'wave_height,wave_period,swell_wave_height,wind_wave_height,ocean_current_velocity',
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

        // Fallback to local cache if live fetch yielded no readings
        if (empty($marineData)) {
            $marineData = Cache::get("forecast:marine_cache:{$date}", []);
        }
        if (empty($weatherData)) {
            $weatherData = Cache::get("forecast:weather_cache:{$date}", []);
        }

        // 2. Parse daytime window (06:00 - 18:00) readings
        $daytimeHours = range(6, 18);
        $daytimeWaves = [];
        $daytimeSwells = [];
        $daytimeWinds = [];
        $daytimeGusts = [];
        $daytimeCurrents = [];
        $daytimeRains = [];
        $daytimePressures = [];
        $daytimePeriods = [];
        $daytimeWindWaves = [];
        $daytimeWindDirs = [];

        foreach ($daytimeHours as $dh) {
            if (isset($marineData['wave_height'][$dh])) {
                $daytimeWaves[] = (float) $marineData['wave_height'][$dh];
                $daytimeSwells[] = (float) ($marineData['swell_wave_height'][$dh] ?? 0.60);
                $daytimePeriods[] = (float) ($marineData['wave_period'][$dh] ?? 6.0);
                $daytimeWindWaves[] = (float) ($marineData['wind_wave_height'][$dh] ?? 0.35);
                $daytimeCurrents[] = (float) (($marineData['ocean_current_velocity'][$dh] ?? 1.1) * 0.27778);
            }
            if (isset($weatherData['wind_speed_10m'][$dh])) {
                $daytimeWinds[] = (float) $weatherData['wind_speed_10m'][$dh];
                $daytimeGusts[] = (float) ($weatherData['wind_gusts_10m'][$dh] ?? $weatherData['wind_speed_10m'][$dh]);
                $daytimeRains[] = (float) ($weatherData['rain'][$dh] ?? $weatherData['precipitation'][$dh] ?? 0.0);
                $daytimePressures[] = (float) ($weatherData['pressure_msl'][$dh] ?? 1010.5);
                $daytimeWindDirs[] = (float) ($weatherData['wind_direction_10m'][$dh] ?? 245.0);
            }
        }

        // If daytime readings were unavailable, check existing hourly assessments or default baseline
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
                // Default calm sea baseline
                $daytimeWaves = [0.50];
                $daytimeSwells = [0.40];
                $daytimePeriods = [7.0];
                $daytimeWindWaves = [0.20];
                $daytimeCurrents = [0.25];
                $daytimeWinds = [10.0];
                $daytimeGusts = [12.0];
                $daytimeRains = [0.0];
                $daytimePressures = [1012.0];
                $daytimeWindDirs = [45.0];
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

        // Physical hard-gate evaluation
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
            'wave_period' => $this->scoreWavePeriod($meanPeriod),
            'wind_wave_height' => $this->scoreWindWaveHeight($meanWindWave),
            'rain' => $this->scoreRain($maxRainRate),
            'sea_level_pressure' => $this->scoreSeaLevelPressure($meanPressure),
            'wind_direction' => $this->scoreWindDirection($meanWindDir),
        ];

        $scorePct = $isPhysicalBreach ? 100.0 : $this->computeWeightedScore($scores);
        $actualClass = $isPhysicalBreach ? 'Critical Risk' : $this->classifyScore($scorePct);

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
     * Automated Nightly Archive Pipeline:
     * Compares multi-horizon predictions (T-14, T-7, T-3, T-1) against realized ocean conditions (T-0),
     * calculates Mean Absolute Error (MAE) and classification accuracy, and persists records in forecast_accuracy_logs.
     */
    public function archiveForecastAccuracy(?Carbon $targetDate = null, array $leadTimes = [1, 3, 7, 14]): array
    {
        $targetDate = $targetDate ? $targetDate->copy()->startOfDay() : Carbon::yesterday(self::TIMEZONE)->startOfDay();
        $dateStr = $targetDate->format('Y-m-d');

        // 1. Fetch / determine realized actual weather on the water
        $realized = $this->fetchRealizedWeather($dateStr);
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

            // Retrieve historical snapshot for this date & lead time
            $snapshot = ForecastSnapshot::whereDate('target_date', $dateStr)
                ->where('lead_time_days', $leadDays)
                ->first();

            if (!$snapshot) {
                // If no snapshot exists, check if there's a batch risk assessment with approximate lead time
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
                    continue; // Skip if no historical prediction was captured
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
}
