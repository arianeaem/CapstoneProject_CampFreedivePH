<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DemandForecastService;
use App\Support\DemandRules;
use Illuminate\View\View;

/**
 * Demand Forecast module (Phase 1) - a standalone sidebar page.
 *
 * Shows ONLY ML-generated forecast data (clearly labelled as forecast) next to the
 * actual booking history, plus the single set of High/Medium/Low and
 * Peak/Shoulder/Off-Peak rules the whole system uses.
 */
class DemandForecastController extends Controller
{
    public function __construct(protected DemandForecastService $forecastService)
    {
    }

    public function index(): View
    {
        $forecast = $this->forecastService->getForecastData();

        $rules = DemandRules::summary();
        $hasForecast = !empty($forecast['forecasts']);

        $batchForecasts = $this->forecastService->getBatchForecasts();
        $batchMonthly = $this->forecastService->getBatchMonthlyRollup($batchForecasts);
        $modelInfo = $this->forecastService->getModelInfo();

        return view('admin.demand.index', compact(
            'rules', 'hasForecast', 'batchForecasts', 'batchMonthly', 'modelInfo'
        ));
    }
}
