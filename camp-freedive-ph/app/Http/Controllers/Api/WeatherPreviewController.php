<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\JsonResponse;
use App\Http\Requests\Api\WeatherPreviewRequest;

class WeatherPreviewController extends Controller
{
    public function __construct(
        protected WeatherForecastService $forecastService
    ) {}

    /**
     * Weather preview for the date picked on the booking page.
     */
    public function preview(WeatherPreviewRequest $request): JsonResponse
    {
        try {
            $startDate = Carbon::parse($request->input('start_date'));
            $preview = $this->forecastService->previewDateAssessment($startDate);

            return response()->json($preview);
        } catch (Exception $e) {
            return response()->json([
                'available' => false,
                'message' => 'Unable to fetch forecast: ' . $e->getMessage(),
            ], 422);
        }
    }
}
