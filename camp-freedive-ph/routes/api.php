<?php

use App\Http\Controllers\Api\MLSyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Secure API endpoints for Machine Learning pipeline integration,
| continuous retraining, and forecast synchronization.
|
*/

Route::middleware(['auth.ml_token', 'throttle:ml_api'])->prefix('v1/ml')->group(function () {
    Route::get('/training-data', [MLSyncController::class, 'exportTrainingData']);
    Route::get('/export-training-data', [MLSyncController::class, 'exportTrainingData']);
    Route::get('/scheduled-batches', [MLSyncController::class, 'scheduledBatches']);
    Route::get('/forecast', [MLSyncController::class, 'getForecast']);
    Route::post('/sync-forecast', [MLSyncController::class, 'importForecast']);
});

Route::middleware(['auth.ml_token', 'throttle:ml_api'])->prefix('ml')->group(function () {
    Route::get('/training-data', [MLSyncController::class, 'exportTrainingData']);
    Route::get('/export-training-data', [MLSyncController::class, 'exportTrainingData']);
    Route::get('/scheduled-batches', [MLSyncController::class, 'scheduledBatches']);
    Route::get('/forecast', [MLSyncController::class, 'getForecast']);
    Route::post('/sync-forecast', [MLSyncController::class, 'importForecast']);
});
