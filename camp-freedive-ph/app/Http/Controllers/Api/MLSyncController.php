<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Models\BatchDemandForecast;
use App\Models\DemandForecast;
use App\Support\DemandRules;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MLSyncController extends Controller
{
    /**
     * Send the completed batches to the ML training script.
     * GET /api/v1/ml/training-data
     */
    public function exportTrainingData(Request $request): JsonResponse
    {
        $statusFilter = $request->query('status'); // e.g. 'completed' or null for all historical
        $includeAll = $request->boolean('include_all', false);

        $query = Batch::with(['bookings.participants', 'bookings.payments', 'coachAssignments'])
            ->orderBy('start_date', 'asc');

        if ($statusFilter) {
            $query->where('status', $statusFilter);
        } elseif (!$includeAll) {
            // Only completed batches that have a completion date
            $query->whereNotNull('completed_at');
        }

        $batches = $query->get();

        $trainingData = $batches->map(function (Batch $batch) {
            $validBookings = $batch->bookings->whereNotIn('status', [
                'cancelled_by_camp',
                'cancelled_by_guest',
                'cancelled',
                'pending_downpayment',
            ]);

            $totalParticipants = $validBookings->sum(fn($b) => $b->participants->count());
            $grossRevenue = (float) $validBookings->sum(fn($b) => (float) $b->total_amount);
            $lguFees = (float) $validBookings->sum(fn($b) => (float) ($b->lgu_fee ?? 0));
            $envFees = (float) $validBookings->sum(fn($b) => (float) ($b->environmental_fee ?? 0));
            $carpoolFees = (float) $validBookings->sum(fn($b) => (float) ($b->carpool_fee ?? 0));
            $boatDiveFees = (float) $validBookings->sum(fn($b) => (float) ($b->boat_dive_fee ?? 0));

            // Class income only (no carpool, boat dive, LGU or environmental fees)
            $pureClassRevenue = (float) $validBookings->sum(fn($b) => (float) ($b->subtotal ?? 0));
            if ($pureClassRevenue <= 0 && $grossRevenue > 0) {
                $pureClassRevenue = max(0, $grossRevenue - ($lguFees + $envFees + $carpoolFees + $boatDiveFees));
            }

            $totalRevenue = $pureClassRevenue;
            $collectedRevenue = (float) $validBookings->flatMap->payments
                ->where('status', 'verified')
                ->sum(fn($p) => (float) $p->amount);


            $classBreakdown = [];
            foreach ($validBookings as $b) {
                $type = strtolower($b->class_type ?: 'discovery');
                $classBreakdown[$type] = ($classBreakdown[$type] ?? 0) + $b->participants->count();
            }

            $pickupBreakdown = [];
            foreach ($validBookings as $b) {
                $opt = strtolower($b->pickup_option ?: 'own_transpo');
                $pickupBreakdown[$opt] = ($pickupBreakdown[$opt] ?? 0) + $b->participants->count();
            }

            $startDate = $batch->start_date ? Carbon::parse($batch->start_date) : null;
            $endDate = $batch->end_date ? Carbon::parse($batch->end_date) : null;
            $month = $startDate ? $startDate->month : null;

            // Season comes from config/demand.php
            $seasonPeriod = $month ? DemandRules::seasonForMonth((int) $month) : DemandRules::SHOULDER;

            $assignedCoachesCount = $batch->coachAssignments->unique('coach_id')->count();

            return [
                'batch_id' => $batch->id,
                'batch_number' => $batch->batch_number ?: $batch->name,
                'start_date' => $startDate ? $startDate->toDateString() : null,
                'end_date' => $endDate ? $endDate->toDateString() : null,
                'year' => $startDate ? $startDate->year : null,
                'month' => $month,
                'day' => $startDate ? $startDate->day : null,
                'day_of_week' => $startDate ? $startDate->dayOfWeek : null,
                'day_name' => $startDate ? $startDate->format('l') : null,
                'is_weekend' => $startDate ? ($startDate->isWeekend() ? 1 : 0) : 1,
                'season_period' => $seasonPeriod,
                'total_participants' => $totalParticipants,
                'total_revenue_php' => $totalRevenue > 0 ? $totalRevenue : $collectedRevenue,
                'collected_revenue_php' => $collectedRevenue,
                'active_bookings_count' => $validBookings->count(),
                'class_distribution' => $classBreakdown,
                'pickup_distribution' => $pickupBreakdown,
                'assigned_coaches_count' => $assignedCoachesCount,
                'computed_capacity' => $batch->computed_capacity,
                'occupancy_percentage' => $batch->occupancy_percentage,
                'status' => $batch->status,
                'completed_at' => $batch->completed_at ? Carbon::parse($batch->completed_at)->toDateTimeString() : null,
            ];
        });

        return response()->json([
            'status' => 'success',
            'exported_at' => Carbon::now()->toDateTimeString(),
            'total_records' => $trainingData->count(),
            'data' => $trainingData,
        ]);
    }

    /**
     * Upcoming scheduled batches, so the ML script makes a forecast for each one.
     * GET /api/v1/ml/scheduled-batches
     */
    public function scheduledBatches(Request $request): JsonResponse
    {
        $today = Carbon::today();

        $batches = Batch::query()
            ->whereDate('start_date', '>=', $today)
            ->whereNotIn('status', ['completed', 'cancelled', 'cancelled_by_camp', 'cancelled_by_guest'])
            ->orderBy('start_date', 'asc')
            ->get();

        $data = $batches->map(function (Batch $batch) use ($today) {
            $start = Carbon::parse($batch->start_date);

            return [
                'batch_id' => $batch->id,
                'batch_code' => $batch->batch_code ?: $batch->batch_number,
                'start_date' => $start->toDateString(),
                'end_date' => $batch->end_date ? Carbon::parse($batch->end_date)->toDateString() : null,
                'status' => $batch->status,
                'capacity' => (int) $batch->computed_capacity,
                'booked_so_far' => (int) $batch->total_participants_count,
                'days_to_start' => (int) $today->diffInDays($start->copy()->startOfDay(), false),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'total_records' => $data->count(),
            'data' => $data,
        ]);
    }

    /**
     * Save the 90-day demand and income forecast sent by the ML script.
     * POST /api/v1/ml/sync-forecast
     */
    public function importForecast(Request $request): JsonResponse
    {
        // Can be a plain array of forecasts or an object with summaries
        $rawPayload = $request->all();

        $forecastList = [];
        $horizonSummaries = null;
        $metadata = [];

        if (isset($rawPayload['forecasts']) && is_array($rawPayload['forecasts'])) {
            $forecastList = $rawPayload['forecasts'];
            $horizonSummaries = $rawPayload['horizon_summaries'] ?? null;
            $metadata = $rawPayload['metadata'] ?? [];
        } elseif (isset($rawPayload['data']) && is_array($rawPayload['data'])) {
            $forecastList = $rawPayload['data'];
            $horizonSummaries = $rawPayload['horizon_summaries'] ?? null;
        } elseif (is_array($rawPayload) && !empty($rawPayload) && isset($rawPayload[0])) {
            $forecastList = $rawPayload;
        }

        $monthlyClassifications = $rawPayload['monthly_classifications']
            ?? $metadata['monthly_classifications']
            ?? $metadata['statistical_interpretation']['monthly_classifications']
            ?? null;
        if (!empty($monthlyClassifications)) {
            $metadata['monthly_classifications'] = $monthlyClassifications;
        }

        $monthlyForecasts = $rawPayload['monthly_forecasts']
            ?? $metadata['monthly_forecasts']
            ?? null;
        if (!empty($monthlyForecasts)) {
            $metadata['monthly_forecasts'] = $monthlyForecasts;
        }

        // Can also be CSV text
        if (empty($forecastList) && $request->has('csv_data')) {
            $forecastList = $this->parseCsvForecast($request->input('csv_data'));
        } elseif ($request->hasFile('file')) {
            $csvContent = file_get_contents($request->file('file')->getRealPath());
            $forecastList = $this->parseCsvForecast($csvContent);
        }

        if (empty($forecastList)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No forecast records provided in request payload.',
            ], 422);
        }

        $now = Carbon::now();
        $recordsToInsert = [];

        foreach ($forecastList as $item) {
            if (empty($item['forecast_date'])) {
                continue;
            }

            $forecastDate = Carbon::parse($item['forecast_date'])->toDateString();
            $daysAhead = (int) ($item['days_ahead'] ?? Carbon::today()->diffInDays(Carbon::parse($forecastDate)));
            $predictedParticipants = (float) ($item['predicted_participants'] ?? 0);
            $predictedBookings = (float) ($item['predicted_bookings'] ?? 0);
            $predictedRevenue = (float) ($item['predicted_revenue_php'] ?? $item['predicted_revenue'] ?? 0);
            // Use the ML labels. If one is missing or wrong, compute it with the same rules
            $demandLevel = DemandRules::normalizeLevel($item['demand_level'] ?? null)
                ?? DemandRules::demandLevel($predictedParticipants);
            $seasonPeriod = (string) ($item['season_period'] ?? DemandRules::seasonForDate($forecastDate));
            $instructorsNeeded = (int) ($item['instructors_needed'] ?? (int) ceil($predictedParticipants / 4));

            $recordsToInsert[] = [
                'forecast_date' => $forecastDate,
                'days_ahead' => $daysAhead,
                'predicted_participants' => $predictedParticipants,
                'predicted_bookings' => $predictedBookings,
                'predicted_revenue_php' => $predictedRevenue,
                'demand_level' => ucfirst($demandLevel),
                'season_period' => ucfirst($seasonPeriod),
                'instructors_needed' => $instructorsNeeded,
                'horizon_summary' => $horizonSummaries ? json_encode($horizonSummaries) : null,
                'metadata' => !empty($metadata) ? json_encode($metadata) : null,
                'synced_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($recordsToInsert)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to parse any valid forecast rows from payload.',
            ], 422);
        }

        // Batch forecasts (one row per scheduled batch). Only changed if the key is sent.
        $batchRows = null;
        if (array_key_exists('batch_forecasts', $rawPayload) && is_array($rawPayload['batch_forecasts'])) {
            $batchRows = [];
            foreach ($rawPayload['batch_forecasts'] as $b) {
                if (empty($b['batch_date'])) {
                    continue;
                }
                $bDate = Carbon::parse($b['batch_date'])->toDateString();
                $bPax = (float) ($b['predicted_participants'] ?? 0);

                $batchRows[] = [
                    'batch_id' => $b['batch_id'] ?? null,
                    'batch_code' => $b['batch_code'] ?? null,
                    'batch_date' => $bDate,
                    'days_to_start' => (int) ($b['days_to_start'] ?? Carbon::today()->diffInDays(Carbon::parse($bDate), false)),
                    'booked_so_far' => (int) ($b['booked_so_far'] ?? 0),
                    'capacity' => (int) ($b['capacity'] ?? 45),
                    'predicted_participants' => $bPax,
                    'predicted_bookings' => (float) ($b['predicted_bookings'] ?? 0),
                    'predicted_revenue_php' => (float) ($b['predicted_revenue_php'] ?? 0),
                    'lower_bound' => isset($b['lower_bound']) ? (float) $b['lower_bound'] : null,
                    'upper_bound' => isset($b['upper_bound']) ? (float) $b['upper_bound'] : null,
                    'predicted_fill_rate' => isset($b['predicted_fill_rate']) ? (float) $b['predicted_fill_rate'] : null,
                    'demand_level' => DemandRules::normalizeLevel($b['demand_level'] ?? null) ?? DemandRules::demandLevel($bPax),
                    'season_period' => (string) ($b['season_period'] ?? DemandRules::seasonForDate($bDate)),
                    'adjusted_for_booked' => (bool) ($b['adjusted_for_booked'] ?? false),
                    'data_basis' => (string) ($b['data_basis'] ?? 'limited_history'),
                    'model_version' => $b['model_version'] ?? null,
                    'generated_at' => !empty($b['generated_at']) ? Carbon::parse($b['generated_at']) : null,
                    'synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($recordsToInsert, $batchRows) {
            // Replace the old forecast rows with the new ones
            DemandForecast::query()->delete();
            DemandForecast::insert($recordsToInsert);

            if ($batchRows !== null) {
                BatchDemandForecast::query()->delete();
                if (!empty($batchRows)) {
                    BatchDemandForecast::insert($batchRows);
                }
            }
        });

        // Clear the cache so the page shows the new forecast
        \Illuminate\Support\Facades\Cache::forget('ml_demand_forecast');

        Log::info('ML Forecast successfully synced', [
            'count' => count($recordsToInsert),
            'first_date' => $recordsToInsert[0]['forecast_date'] ?? null,
            'last_date' => end($recordsToInsert)['forecast_date'] ?? null,
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Successfully synced ' . count($recordsToInsert) . ' demand forecast records.',
            'synced_count' => count($recordsToInsert),
            'batch_forecasts_synced' => $batchRows === null ? null : count($batchRows),
            'first_forecast_date' => $recordsToInsert[0]['forecast_date'] ?? null,
            'last_forecast_date' => end($recordsToInsert)['forecast_date'] ?? null,
            'horizon_summaries' => $horizonSummaries,
            'monthly_classifications' => $monthlyClassifications,
            'monthly_forecasts' => $monthlyForecasts,
            'synced_at' => $now->toDateTimeString(),
        ]);
    }

    /**
     * Get the latest demand forecast with the monthly levels.
     * GET /api/v1/ml/forecast
     */
    public function getForecast(Request $request): JsonResponse
    {
        $forecastService = app(\App\Services\DemandForecastService::class);
        $data = $forecastService->getForecastData();

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Read the forecast CSV.
     */
    protected function parseCsvForecast(string $csvContent): array
    {
        $lines = explode("\n", trim($csvContent));
        if (count($lines) < 2) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines));
        $headers = array_map('trim', $headers);
        $rows = [];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }
            $cols = str_getcsv($line);
            if (count($cols) >= count($headers)) {
                $row = array_combine($headers, array_slice($cols, 0, count($headers)));
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
