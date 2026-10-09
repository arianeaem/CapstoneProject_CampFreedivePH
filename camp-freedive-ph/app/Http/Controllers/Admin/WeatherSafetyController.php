<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Batch;
use App\Services\Weather\BatchSafetyReportService;
use App\Services\WeatherForecastService;
use App\ViewModels\BatchSafetyViewModel;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use App\Http\Requests\Admin\Weather\ApplyOverrideRequest;
use App\Http\Requests\Admin\Weather\CancelBatchRequest;

/**
 * Admin pages for weather and sea safety.
 *
 * - page 1: list of batches in the next 16 days and their risk
 * - page 2: hourly weather for one batch, rated with our rules
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
        protected WeatherForecastService $forecastService
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

        // Keep the 16-day Open-Meteo cache warm
        if (!Cache::get('forecast:continuous_16d')) {
            try {
                $this->forecastService->updateAllForecasts(WeatherForecastService::MAX_FORECAST_DAYS);
            } catch (\Throwable $e) {
                // Ignore API errors
            }
        }

        return view('admin.weather.index', compact('batches', 'criticalCount'));
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
                $msg .= " Batch cancelled, 100% force majeure refund eligibility triggered, and participant cancellation notifications dispatched.";
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

            return back()->with('success', "Batch {$batch->batch_code} is cancelled. {$sentCount} participant(s) were emailed and their full refunds are waiting in Refunds.");
        } catch (Exception $e) {
            return back()->with('error', "Cancellation failed: " . $e->getMessage());
        }
    }
}
