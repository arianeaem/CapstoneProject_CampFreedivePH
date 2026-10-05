<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DemandForecastService;
use App\Support\DemandRules;
use Carbon\Carbon;
use Illuminate\View\View;

/**
 * Demand Forecast page (sidebar).
 *
 * Shows the ML forecast (labelled as forecast) next to the real booking history,
 * plus the High/Medium/Low and Peak/Shoulder/Off-Peak rules used in the whole system.
 * Same layout as the Demand Forecast tab in Reports & Analytics.
 */
class DemandForecastController extends Controller
{
    /** Divers per coach, for "coaches needed" (same as the monthly total). */
    protected const DIVERS_PER_COACH = 4;

    /** Day ranges you can pick on the page. */
    protected const HORIZONS = [7, 30, 60, 90];

    public function __construct(protected DemandForecastService $forecastService)
    {
    }

    public function index(): View
    {
        $forecast = $this->forecastService->getForecastData();

        $rules = DemandRules::summary();
        $hasForecast = !empty($forecast['forecasts']);

        $batchForecasts = $this->forecastService->getBatchForecasts();
        $modelInfo = $this->forecastService->getModelInfo();
        $horizons = $this->horizonViews($batchForecasts);
        $diversPerCoach = self::DIVERS_PER_COACH;

        return view('admin.demand.index', compact(
            'rules', 'hasForecast', 'batchForecasts', 'modelInfo', 'horizons', 'diversPerCoach'
        ));
    }

    /**
     * For each day range: the batches starting in it and the monthly totals
     * (uses the service's monthly total so the numbers are the same everywhere).
     */
    protected function horizonViews(array $batchForecasts): array
    {
        $views = [];
        foreach (self::HORIZONS as $days) {
            $batches = array_values(array_filter($batchForecasts, fn ($b) => (int) $b['days_to_start'] <= $days));

            $views[(string) $days] = [
                'months' => $this->forecastService->getBatchMonthlyRollup($batches),
                'batches' => array_map(fn ($b) => [
                    'key' => $b['batch_id'] ?? $b['batch_code'],
                    'code' => $b['batch_code'] ?? '',
                    'label' => Carbon::parse($b['batch_date'])->format('M d'),
                    'date' => Carbon::parse($b['batch_date'])->format('M d, Y (D)'),
                    'days_to_start' => (int) $b['days_to_start'],
                    'booked' => (int) $b['booked_so_far'],
                    'capacity' => (int) $b['capacity'],
                    'divers' => (float) $b['predicted_participants'],
                    'low' => $b['lower_bound'],
                    'high' => $b['upper_bound'],
                    'bookings' => (float) $b['predicted_bookings'],
                    'revenue' => (float) $b['predicted_revenue_php'],
                    'coaches' => (int) ceil($b['predicted_participants'] / self::DIVERS_PER_COACH),
                    'demand' => $b['demand_level'],
                    'season' => $b['season_period'],
                ], $batches),
            ];
        }

        return $views;
    }
}
