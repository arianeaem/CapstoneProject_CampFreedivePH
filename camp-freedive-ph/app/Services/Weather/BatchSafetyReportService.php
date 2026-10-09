<?php

namespace App\Services\Weather;

use App\Models\Batch;
use App\Models\User;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Gets all the data for the Safety Monitoring batch page: Day 1 / Day 2 results
 * (runs them again if the forecast is newer), the 24-hour data and the history.
 */
class BatchSafetyReportService
{
    public function __construct(
        protected WeatherForecastService $forecastService
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
        $overallClassification = 'Not Available';
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

        return compact(
            'batch',
            'day1Assessment',
            'day2Assessment',
            'latestOverride',
            'overallClassification',
            'assessmentRuns',
            'day1Continuous24h',
            'day2Continuous24h',
            'isConcluded'
        );
    }
}
