<?php

namespace App\Services;

use App\Models\Batch;
use App\Models\BatchDemandForecast;
use App\Models\DemandForecast;
use App\Models\Payment;
use App\Support\DemandRules;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DemandForecastService
{
    /**
     * Get demand forecast data cached for 1 hour directly from persisted database records.
     */
    public function getForecastData(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget('ml_demand_forecast');
        }

        return Cache::remember('ml_demand_forecast', 3600, function () {
            return $this->loadFromDatabase();
        });
    }

    /**
     * Get specific forecast prediction for a single dive date.
     */
    public function getForecastForDate(string|Carbon $date): ?array
    {
        $targetDate = $date instanceof Carbon ? $date->toDateString() : Carbon::parse($date)->toDateString();

        // 1. A real scheduled batch on exactly this date has its own per-batch forecast: use it first.
        $batchRow = $this->findBatchForecastForDate($targetDate);
        if ($batchRow !== null) {
            return $batchRow;
        }
        $forecasts = $this->getForecastData()['forecasts'] ?? $this->getForecastData();

        foreach ($forecasts as $item) {
            if (($item['forecast_date'] ?? null) === $targetDate) {
                return $item;
            }
        }

        // Forecast rows are weekly; a camp date is covered by the weekly row within 3 days of it.
        $nearest = null;
        $nearestDiff = 4;
        foreach ($forecasts as $item) {
            if (empty($item['forecast_date'])) {
                continue;
            }
            $diff = abs(Carbon::parse($item['forecast_date'])->diffInDays(Carbon::parse($targetDate), false));
            if ($diff < $nearestDiff) {
                $nearestDiff = $diff;
                $nearest = $item;
            }
        }
        if ($nearest !== null) {
            return $nearest;
        }

        $dbRecord = DemandForecast::whereDate('forecast_date', $targetDate)->first();
        if ($dbRecord) {
            return [
                'forecast_date' => $dbRecord->forecast_date?->toDateString(),
                'days_ahead' => $dbRecord->days_ahead,
                'predicted_participants' => $dbRecord->predicted_participants,
                'predicted_bookings' => $dbRecord->predicted_bookings,
                'predicted_revenue_php' => (float) $dbRecord->predicted_revenue_php,
                'demand_level' => $dbRecord->demand_level,
                'season_period' => $dbRecord->season_period,
                'instructors_needed' => $dbRecord->instructors_needed,
            ];
        }

        return null;
    }

    /**
     * Per-batch forecast row (as a forecast_date-shaped array) for an exact batch date, or null.
     * Fails soft: if the table is missing (migration not run yet) pricing keeps using the weekly forecast.
     */
    protected function findBatchForecastForDate(string $targetDate): ?array
    {
        try {
            $row = BatchDemandForecast::whereDate('batch_date', $targetDate)->orderByDesc('synced_at')->first();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        return [
            'forecast_date' => $row->batch_date->toDateString(),
            'days_ahead' => $row->days_to_start,
            'predicted_participants' => (float) $row->predicted_participants,
            'predicted_bookings' => (float) $row->predicted_bookings,
            'predicted_revenue_php' => (float) $row->predicted_revenue_php,
            'demand_level' => $row->demand_level,
            'season_period' => $row->season_period,
            'instructors_needed' => (int) ceil($row->predicted_participants / 4),
            'source' => 'per_batch',
            'batch_code' => $row->batch_code,
        ];
    }

    /**
     * Upcoming per-batch forecasts from the latest ML sync (one row per real scheduled batch).
     */
    public function getBatchForecasts(): array
    {
        try {
            return BatchDemandForecast::whereDate('batch_date', '>=', Carbon::today())
                ->orderBy('batch_date')
                ->get()
                ->map(fn ($r) => [
                    'batch_id' => $r->batch_id,
                    'batch_code' => $r->batch_code,
                    'batch_date' => $r->batch_date->toDateString(),
                    'days_to_start' => $r->days_to_start,
                    'booked_so_far' => $r->booked_so_far,
                    'capacity' => $r->capacity,
                    'predicted_participants' => $r->predicted_participants,
                    'predicted_bookings' => $r->predicted_bookings,
                    'lower_bound' => $r->lower_bound,
                    'upper_bound' => $r->upper_bound,
                    'predicted_fill_rate' => $r->predicted_fill_rate,
                    'demand_level' => $r->demand_level,
                    'season_period' => $r->season_period,
                    'adjusted_for_booked' => $r->adjusted_for_booked,
                    'data_basis' => $r->data_basis,
                    'model_version' => $r->model_version,
                    'generated_at' => $r->generated_at?->toDateTimeString(),
                ])
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Monthly rollup = the SUM of the per-batch forecasts in that month (not a separate guess).
     */
    public function getBatchMonthlyRollup(array $batchRows): array
    {
        $months = [];
        foreach ($batchRows as $r) {
            $key = Carbon::parse($r['batch_date'])->format('Y-m');
            $months[$key]['rows'][] = $r;
        }
        ksort($months);

        $out = [];
        foreach ($months as $key => $data) {
            $rows = $data['rows'];
            $count = count($rows);
            $pax = array_sum(array_column($rows, 'predicted_participants'));
            $bkg = array_sum(array_column($rows, 'predicted_bookings'));
            $avg = $count ? $pax / $count : 0;
            $month = (int) substr($key, 5, 2);

            $out[] = [
                'month' => $key,
                'month_label' => Carbon::parse($key . '-01')->format('F Y'),
                'batches' => $count,
                'predicted_participants' => round($pax, 1),
                'predicted_bookings' => round($bkg, 1),
                'avg_participants_per_batch' => round($avg, 1),
                'demand_level' => DemandRules::demandLevel($avg),
                'season_period' => DemandRules::seasonForMonth($month),
            ];
        }

        return $out;
    }

    /**
     * Model information stored by the last ML sync (version, data basis, baseline comparison).
     */
    public function getModelInfo(): array
    {
        try {
            $row = DemandForecast::latestSync()->first();
        } catch (\Throwable $e) {
            $row = null;
        }

        $meta = $row ? $row->metadata : null;
        if (is_string($meta)) {
            $meta = json_decode($meta, true);
        }
        $meta = is_array($meta) ? $meta : [];

        return [
            'model_version' => $meta['model_version'] ?? null,
            'generated_at' => $meta['generated_at'] ?? null,
            'data_basis' => $meta['data_basis'] ?? null,
            'baseline_comparison' => $meta['baseline_comparison'] ?? null,
            'training_batches' => $meta['training_batches'] ?? null,
        ];
    }

    /**
     * Load forecasts from the local demand_forecasts table.
     */
    protected function loadFromDatabase(): array
    {
        $records = DemandForecast::latestSync()->get();

        if ($records->isEmpty()) {
            // No ML forecast has been synced yet. Return an honest empty state - never fabricated numbers.
            return $this->emptyForecast();
        }

        $first = $records->first();
        $rawHorizon = $first->horizon_summary;
        if (is_string($rawHorizon)) {
            $rawHorizon = json_decode($rawHorizon, true);
        }

        $rawMetadata = $first->metadata;
        if (is_string($rawMetadata)) {
            $rawMetadata = json_decode($rawMetadata, true);
        }

        $forecastList = $records->map(function ($r) {
            return [
                'forecast_date' => $r->forecast_date?->format('Y-m-d'),
                'days_ahead' => $r->days_ahead,
                'predicted_participants' => (float) $r->predicted_participants,
                'predicted_bookings' => (float) ($r->predicted_bookings ?? 0),
                'predicted_revenue_php' => (float) $r->predicted_revenue_php,
                'demand_level' => $r->demand_level ?: 'Medium',
                'season_period' => $r->season_period ?: 'Off-Peak',
                'instructors_needed' => (int) $r->instructors_needed,
            ];
        })->values()->all();

        $monthlyClassifications = $rawMetadata['monthly_classifications']
            ?? $rawMetadata['statistical_demand_layer']['monthly_classifications']
            ?? $rawMetadata['statistical_interpretation']['monthly_classifications']
            ?? $this->computeMonthlyClassifications($forecastList);

        $monthlyForecasts = $rawMetadata['monthly_forecasts']
            ?? $this->loadMonthlyForecastsFromArtifact();

        $monthlyHorizons = $this->computeMonthlyHorizons($forecastList, $monthlyClassifications, $monthlyForecasts);

        return [
            'synced_at' => $first->synced_at ? $first->synced_at->toDateTimeString() : Carbon::now()->toDateTimeString(),
            'source' => 'database',
            'horizon_summaries' => $rawHorizon ?: $this->computeHorizonSummariesFromList($forecastList),
            'monthly_horizons' => $monthlyHorizons,
            'monthly_classifications' => $monthlyClassifications,
            'monthly_forecasts' => $monthlyForecasts,
            'forecasts' => $forecastList,
        ];
    }

    /**
     * Compute monthly cards grouped by month for 7d, 30d, 60d, and 90d projection periods.
     */
    public function computeMonthlyHorizons(
        array $forecastList, 
        array $monthlyClassifications = [], 
        array $monthlyForecasts = []
    ): array
    {
        $horizons = [7, 30, 60, 90];
        $result = [];

        $activeMonthlyForecasts = !empty($monthlyForecasts)
            ? $monthlyForecasts
            : $this->loadMonthlyForecastsFromArtifact();

        $monthlyForecastMap = [];
        foreach ($activeMonthlyForecasts as $mf) {
            $mKey = $mf['month'] ?? null;
            if ($mKey) {
                $monthlyForecastMap[$mKey] = $mf;
            }
        }

        foreach ($horizons as $h) {
            $filtered = array_values(array_filter($forecastList, fn($f) => ($f['days_ahead'] ?? 0) <= $h));
            if (empty($filtered) && !empty($forecastList)) {
                $filtered = array_slice($forecastList, 0, 1);
            }

            // Group by Month (Y-m)
            $monthGroups = [];
            foreach ($filtered as $item) {
                $date = Carbon::parse($item['forecast_date'] ?? now());
                $monthKey = $date->format('Y-m');

                if (!isset($monthGroups[$monthKey])) {
                    $monthGroups[$monthKey] = [
                        'month_key' => $monthKey,
                        'month_name' => $date->format('F Y'),
                        'short_name' => $date->format('M Y'),
                        'diver_volume' => 0,
                        'booking_volume' => 0.0,
                        'projected_revenue' => 0.0,
                        'batches_count' => 0,
                        'peak_coaches' => 0,
                        'demand_levels' => [],
                        'season_periods' => [],
                    ];
                }

                $pax = (float) ($item['predicted_participants'] ?? 0);
                $bkg = isset($item['predicted_bookings']) ? (float) $item['predicted_bookings'] : null;
                $rev = (float) ($item['predicted_revenue_php'] ?? 0);
                $inst = (int) ($item['instructors_needed'] ?? ceil($pax / 4));

                $monthGroups[$monthKey]['diver_volume'] += $pax;
                if ($bkg !== null && $bkg > 0) {
                    $monthGroups[$monthKey]['booking_volume'] += $bkg;
                }
                $monthGroups[$monthKey]['projected_revenue'] += $rev;
                $monthGroups[$monthKey]['batches_count'] += 1;
                if ($inst > $monthGroups[$monthKey]['peak_coaches']) {
                    $monthGroups[$monthKey]['peak_coaches'] = $inst;
                }
                if (!empty($item['demand_level'])) {
                    $monthGroups[$monthKey]['demand_levels'][] = $item['demand_level'];
                }
                if (!empty($item['season_period'])) {
                    $monthGroups[$monthKey]['season_periods'][] = $item['season_period'];
                }
            }

            $cards = [];
            $classificationMap = [];
            $activeClassifications = !empty($monthlyClassifications)
                ? $monthlyClassifications
                : $this->computeMonthlyClassifications($forecastList);

            foreach ($activeClassifications as $cItem) {
                if (isset($cItem['month'])) {
                    $classificationMap[$cItem['month']] = $cItem;
                }
            }

            foreach ($monthGroups as $mKey => $m) {
                $dominantDemand = 'Medium';
                if (!empty($m['demand_levels'])) {
                    $counts = array_count_values($m['demand_levels']);
                    arsort($counts);
                    $dominantDemand = array_key_first($counts);
                }

                $dominantSeason = 'Off-Peak';
                if (!empty($m['season_periods'])) {
                    $counts = array_count_values($m['season_periods']);
                    arsort($counts);
                    $dominantSeason = array_key_first($counts);
                }

                // If at full 90-day horizon, use Python pre-aggregated monthly figures directly
                $preAgg = ($h === 90 && isset($monthlyForecastMap[$mKey])) ? $monthlyForecastMap[$mKey] : null;

                $paxTotal = $preAgg !== null && isset($preAgg['predicted_participants'])
                    ? (int) round((float) $preAgg['predicted_participants'])
                    : (int) round($m['diver_volume']);

                $estBookings = $preAgg !== null && isset($preAgg['predicted_bookings'])
                    ? (int) round((float) $preAgg['predicted_bookings'])
                    : ($m['booking_volume'] > 0
                        ? (int) round($m['booking_volume'])
                        : (int) max(1, round($paxTotal / 2.2)));

                $projRevenue = $preAgg !== null && isset($preAgg['predicted_revenue_php'])
                    ? (float) round((float) $preAgg['predicted_revenue_php'], 2)
                    : (float) round($m['projected_revenue'], 2);

                $batchCount = $preAgg !== null && isset($preAgg['batches_in_month'])
                    ? (int) $preAgg['batches_in_month']
                    : $m['batches_count'];

                $seasonPeriod = $preAgg !== null && !empty($preAgg['season_period'])
                    ? $preAgg['season_period']
                    : $dominantSeason;

                $matchingClass = $classificationMap[$m['month_name']] ?? null;

                $cards[] = [
                    'month_key' => $m['month_key'],
                    'month_name' => $m['month_name'],
                    'short_name' => $m['short_name'],
                    'diver_volume' => $paxTotal,
                    'estimated_bookings' => $estBookings,
                    'batches_count' => $batchCount,
                    'projected_revenue' => $projRevenue,
                    'coaches_needed' => max(1, $m['peak_coaches']),
                    'demand_classification' => $dominantDemand,
                    'peak_classification' => $seasonPeriod . (str_contains(strtolower($seasonPeriod), 'season') || str_contains(strtolower($seasonPeriod), 'peak') ? '' : ' Season'),
                    'monthly_average' => $matchingClass['monthly_average'] ?? null,
                    'overall_mean' => $matchingClass['overall_mean'] ?? null,
                    'standard_deviation' => $matchingClass['standard_deviation'] ?? null,
                    'upper_threshold' => $matchingClass['upper_threshold'] ?? null,
                    'lower_threshold' => $matchingClass['lower_threshold'] ?? null,
                    'classification' => $matchingClass['classification'] ?? $seasonPeriod,
                ];
            }

            $result["{$h}_day"] = $cards;
            $result[(string)$h] = $cards; // numeric key alias for easy Alpine.js binding
        }

        return $result;
    }

    /**
     * Compute dynamic 7d, 30d, 60d, 90d horizon summaries.
     */
    public function computeHorizonSummariesFromList(array $forecastList): array
    {
        $horizons = [7, 30, 60, 90];
        $summaries = [];

        foreach ($horizons as $h) {
            $key = "{$h}_day";
            $filtered = array_filter($forecastList, fn($f) => ($f['days_ahead'] ?? 0) <= $h);

            $pax = 0;
            $bkg = 0;
            $rev = 0;
            $peakInst = 0;
            $demandLevels = [];

            foreach ($filtered as $item) {
                $pax += (float) ($item['predicted_participants'] ?? 0);
                $bkg += (float) ($item['predicted_bookings'] ?? 0);
                $rev += (float) ($item['predicted_revenue_php'] ?? 0);
                $inst = (int) ($item['instructors_needed'] ?? ceil(($item['predicted_participants'] ?? 0) / 4));
                if ($inst > $peakInst) {
                    $peakInst = $inst;
                }
                if (!empty($item['demand_level'])) {
                    $demandLevels[] = $item['demand_level'];
                }
            }

            $dominantDemand = 'Medium';
            if (!empty($demandLevels)) {
                $counts = array_count_values($demandLevels);
                arsort($counts);
                $dominantDemand = array_key_first($counts);
            }

            $summaries[$key] = [
                'batches' => count($filtered),
                'participants' => (int) round($pax),
                'bookings' => (int) round($bkg),
                'revenue' => (float) round($rev, 2),
                'peak_instructors' => max(1, $peakInst),
                'demand_level' => $dominantDemand,
            ];
        }

        return $summaries;
    }

    /**
     * Empty state used until the ML pipeline has synced a real forecast.
     * Intentionally contains NO invented forecast rows.
     */
    protected function emptyForecast(): array
    {
        return [
            'synced_at' => null,
            'source' => 'no_forecast_yet',
            'horizon_summaries' => $this->computeHorizonSummariesFromList([]),
            'monthly_horizons' => [],
            'monthly_classifications' => [],
            'monthly_forecasts' => [],
            'forecasts' => [],
        ];
    }

    /**
     * Retrieve statistical demand classifications from ML model output.
     * The Python ML pipeline (retrain_pipeline.py) is the single source of truth for
     * statistical demand formulas (Mean ± 1 SD thresholds and Peak/Shoulder/Off-Peak logic).
     */
    public function computeMonthlyClassifications(array $forecastList = []): array
    {
        // 1. Primary: Load directly from the Python ML pipeline's generated output artifact
        $fromArtifact = $this->loadClassificationsFromArtifact();
        if (!empty($fromArtifact)) {
            return $fromArtifact;
        }

        if (empty($forecastList)) {
            return [];
        }

        // 2. Fallback: Map classifications directly from forecast records without duplicating ML statistics
        $monthGroups = [];
        foreach ($forecastList as $item) {
            $date = Carbon::parse($item['forecast_date'] ?? now());
            $monthName = $date->format('F Y');
            $pax = (float) ($item['predicted_participants'] ?? 0);
            $season = $item['season_period'] ?? 'Shoulder';
            $monthGroups[$monthName]['pax'][] = $pax;
            $monthGroups[$monthName]['season'] = $season;
        }

        $result = [];
        foreach ($monthGroups as $monthName => $data) {
            $avg = !empty($data['pax']) ? round(array_sum($data['pax']) / count($data['pax']), 1) : 0.0;
            $result[] = [
                'month' => $monthName,
                'monthly_average' => $avg,
                'overall_mean' => $avg,
                'standard_deviation' => 0.0,
                'upper_threshold' => $avg,
                'lower_threshold' => $avg,
                'classification' => $data['season'],
            ];
        }

        return $result;
    }

    /**
     * Load dynamic statistical demand classifications from the ML output artifact.
     */
    public function loadClassificationsFromArtifact(): array
    {
        $artifactPath = base_path('../demand-forecast/outputs/monthly_demand_classifications.json');
        if (file_exists($artifactPath)) {
            $content = file_get_contents($artifactPath);
            $decoded = json_decode($content, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    /**
     * Load monthly forecast aggregations from the ML output artifact (outputs/forecast_monthly.csv).
     */
    public function loadMonthlyForecastsFromArtifact(): array
    {
        $csvPath = base_path('../demand-forecast/outputs/forecast_monthly.csv');
        if (!file_exists($csvPath)) {
            return [];
        }

        $lines = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (count($lines) <= 1) {
            return [];
        }

        $header = str_getcsv(array_shift($lines));
        $rows = [];
        foreach ($lines as $line) {
            $row = str_getcsv($line);
            if (count($row) === count($header)) {
                $rows[] = array_combine($header, $row);
            }
        }

        return $rows;
    }

    /**
     * Get historical actuals combined with model predictions for monthly trend charts.
     */
    public function getHistoricalVsForecastTrend(): array
    {
        // 1. Fetch historical batches from past months grouped by month
        $historicalBatches = Batch::where(function ($q) {
                $q->where('status', 'completed')
                  ->orWhere(function ($sq) {
                      $sq->where('status', 'confirmed')->where('start_date', '<=', Carbon::today());
                  });
            })
            ->with(['bookings.participants', 'bookings.payments', 'activeParticipantAssignments.coach'])
            ->orderBy('start_date', 'asc')
            ->get();

        $historyByMonth = [];
        foreach ($historicalBatches as $batch) {
            if (!$batch->start_date) continue;
            $mKey = $batch->start_date->format('Y-m');

            if (!isset($historyByMonth[$mKey])) {
                $historyByMonth[$mKey] = [
                    'month_key' => $mKey,
                    'label' => $batch->start_date->format('M Y'),
                    'type' => 'actual',
                    'participants' => 0,
                    'bookings' => 0,
                    'revenue_php' => 0.0,
                    'coaches' => 0,
                    'batches' => 0,
                    'coach_ids' => [],
                    'days_ahead_horizon' => 0,
                ];
            }

            $validBookings = $batch->bookings->whereNotIn('status', ['cancelled_by_camp', 'cancelled', 'cancelled_by_guest', 'pending_downpayment']);
            $pax = $validBookings->sum(fn($b) => $b->participants->count());
            $rev = (float) $validBookings->sum(fn($b) => (float) $b->total_amount);
            $bCount = $validBookings->count();

            $historyByMonth[$mKey]['participants'] += $pax;
            $historyByMonth[$mKey]['bookings'] += $bCount;
            $historyByMonth[$mKey]['revenue_php'] += $rev;
            $historyByMonth[$mKey]['batches'] += 1;

            foreach ($batch->activeParticipantAssignments as $assign) {
                if ($assign->coach_id) {
                    $historyByMonth[$mKey]['coach_ids'][$assign->coach_id] = true;
                }
            }
        }

        $trend = [];
        // Take up to last 4 historical months
        $recentHistory = array_slice($historyByMonth, -4, 4, true);
        foreach ($recentHistory as $mKey => $item) {
            $coachCount = count($item['coach_ids']);
            if ($coachCount === 0 && $item['participants'] > 0) {
                $coachCount = (int) max(1, ceil($item['participants'] / 4));
            }

            $trend[] = [
                'month_key' => $item['month_key'],
                'type' => 'actual',
                'label' => $item['label'],
                'participants' => (int) $item['participants'],
                'bookings' => (int) $item['bookings'],
                'revenue_php' => (float) round($item['revenue_php'], 2),
                'coaches' => max(1, $coachCount),
                'batches_count' => $item['batches'],
                'days_ahead_horizon' => 0,
                'demand_level' => DemandRules::demandLevel($item['participants'] / max(1, $item['batches'])),
            ];
        }

        // 2. Append upcoming model forecasts grouped by month
        $forecastData = $this->getForecastData();
        $forecasts = $forecastData['forecasts'] ?? [];

        $forecastByMonth = [];
        foreach ($forecasts as $f) {
            $fDate = Carbon::parse($f['forecast_date']);
            $mKey = $fDate->format('Y-m');

            if (!isset($forecastByMonth[$mKey])) {
                $forecastByMonth[$mKey] = [
                    'month_key' => $mKey,
                    'label' => $fDate->format('M Y') . ' (Est)',
                    'type' => 'forecast',
                    'participants' => 0,
                    'bookings' => 0,
                    'revenue_php' => 0.0,
                    'coaches' => 0,
                    'batches' => 0,
                    'min_days_ahead' => $f['days_ahead'] ?? 999,
                    'demand_levels' => [],
                ];
            }

            $pax = (float) ($f['predicted_participants'] ?? 0);
            $rev = (float) ($f['predicted_revenue_php'] ?? 0);
            $inst = (int) ($f['instructors_needed'] ?? ceil($pax / 4));

            $forecastByMonth[$mKey]['participants'] += $pax;
            $forecastByMonth[$mKey]['revenue_php'] += $rev;
            $forecastByMonth[$mKey]['batches'] += 1;
            if ($inst > $forecastByMonth[$mKey]['coaches']) {
                $forecastByMonth[$mKey]['coaches'] = $inst;
            }
            if (($f['days_ahead'] ?? 999) < $forecastByMonth[$mKey]['min_days_ahead']) {
                $forecastByMonth[$mKey]['min_days_ahead'] = $f['days_ahead'] ?? 999;
            }
            if (!empty($f['demand_level'])) {
                $forecastByMonth[$mKey]['demand_levels'][] = $f['demand_level'];
            }
        }

        // Take next 4 forecasted months
        $upcomingForecastMonths = array_slice($forecastByMonth, 0, 4, true);
        foreach ($upcomingForecastMonths as $mKey => $item) {
            $paxTotal = (int) round($item['participants']);
            $estBookings = (int) max(1, round($paxTotal / 2.2));

            $dominantDemand = 'Medium';
            if (!empty($item['demand_levels'])) {
                $counts = array_count_values($item['demand_levels']);
                arsort($counts);
                $dominantDemand = array_key_first($counts);
            }

            $trend[] = [
                'month_key' => $item['month_key'],
                'type' => 'forecast',
                'label' => $item['label'],
                'participants' => $paxTotal,
                'bookings' => $estBookings,
                'revenue_php' => (float) round($item['revenue_php'], 2),
                'coaches' => max(1, $item['coaches']),
                'batches_count' => $item['batches'],
                'days_ahead_horizon' => $item['min_days_ahead'],
                'demand_level' => $dominantDemand,
            ];
        }

        return $trend;
    }

    /**
     * Get staffing recommendation pill for a specific date (used in Batch Create and Coach Matching).
     */
    public function getStaffingRecommendationForDate($date): ?array
    {
        $targetDate = $date instanceof Carbon ? $date : Carbon::parse($date);
        $targetDateStr = $targetDate->format('Y-m-d');

        $data = $this->getForecastData();
        $forecasts = $data['forecasts'] ?? [];

        $match = null;
        $closestDiff = 999;

        foreach ($forecasts as $f) {
            $fDate = Carbon::parse($f['forecast_date']);
            $diff = abs($targetDate->diffInDays($fDate));

            // Exact date match
            if ($fDate->toDateString() === $targetDateStr) {
                $match = $f;
                break;
            }

            // Closest weekend match within 4 days
            if ($diff < $closestDiff && $diff <= 4) {
                $closestDiff = $diff;
                $match = $f;
            }
        }

        if ($match) {
            $instructors = (int) ($match['instructors_needed'] ?? max(1, ceil(($match['predicted_participants'] ?? 12) / 4)));
            $demand = $match['demand_level'] ?? 'Medium';
            $season = $match['season_period'] ?? 'Off-Peak';
            $pax = (int) round($match['predicted_participants'] ?? 12);

            return [
                'instructors_needed' => $instructors,
                'demand_level' => $demand,
                'season_period' => $season,
                'predicted_participants' => $pax,
                'pill_text' => "⚡ Model Suggestion: {$instructors} " . ($instructors === 1 ? 'Coach' : 'Coaches') . " ({$demand} Demand · ~{$pax} Divers)",
                'badge_short' => "⚡ Suggestion: {$instructors} " . ($instructors === 1 ? 'Coach' : 'Coaches'),
            ];
        }

        // No ML forecast covers this date. Do not invent a staffing suggestion.
        return null;
    }
}
