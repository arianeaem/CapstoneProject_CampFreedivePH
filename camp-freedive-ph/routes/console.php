<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * 24-Hour Continuous Weather & Marine Forecast Cache Sync
 * Runs every 15 minutes (96 times a day) to maintain continuous whole-day predictions in local cache
 * providing sub-millisecond (<1ms) response times for all users, booking requests, and batch risk engines.
 */
Schedule::command('forecast:update --assess-batches')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/forecast_cron.log'));

/**
 * Live Open-Meteo Weather & Marine Risk Assessment
 * Runs hourly for ongoing and upcoming batches within 16 days.
 */
Schedule::command('weather:assess-batches')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/weather_schedule.log'));

Schedule::command('coaches:notify-matching')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/coach_matching_alerts.log'));

/**
 * Automated Nightly Forecast Accuracy Archive & Verification
 * Runs daily at 00:05 to compare multi-horizon predictions against realized ocean observations.
 */
Schedule::command('forecast:archive-accuracy')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/forecast_accuracy.log'));

/**
 * Nightly demand retraining (demand:retrain) - DISABLED for Phase 1.
 *
 * The old nightly flow could overwrite the new pipeline's results. Until the new
 * 553-record pipeline is signed off, retraining is run MANUALLY only:
 *     php artisan demand:retrain        (or: python retrain_pipeline.py in demand-forecast/)
 * Re-enable by restoring:
 *     Schedule::command('demand:retrain')->dailyAt('02:00')->withoutOverlapping()->runInBackground()
 *         ->appendOutputTo(storage_path('logs/ml_demand_retrain.log'));
 */

/**
 * Automated Quarterly ML Multi-Horizon Model Re-benchmarking
 * Runs quarterly (Jan 1, Apr 1, Jul 1, Oct 1 at 00:00) to evaluate seasonal shifts (Dry vs. Wet season).
 */
Schedule::command('ml:rebenchmark')
    ->quarterly()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/ml_rebenchmark.log'));



