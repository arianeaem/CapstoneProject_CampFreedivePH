<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Refresh the weather forecast cache every 15 minutes,
 * so pages can read the forecast from the cache instead of calling the API.
 */
Schedule::command('forecast:update --assess-batches')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/forecast_cron.log'));

/**
 * Check the weather risk every hour for batches that are ongoing or within 16 days.
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
 * Every night at 00:05, compare the old forecasts with the actual weather.
 */
Schedule::command('forecast:archive-accuracy')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/forecast_accuracy.log'));

/**
 * Nightly demand retraining (demand:retrain) - turned OFF.
 *
 * The old nightly job could overwrite the results of the new pipeline. Until the new
 * 553-record pipeline is approved, run it by hand:
 *     php artisan demand:retrain        (or: python retrain_pipeline.py in demand-forecast/)
 * To turn it back on, add:
 *     Schedule::command('demand:retrain')->dailyAt('02:00')->withoutOverlapping()->runInBackground()
 *         ->appendOutputTo(storage_path('logs/ml_demand_retrain.log'));
 */

/**
 * Checkout payment reconciliation: recovers payments whose return page and webhook were both missed.
 * Registered but inactive until CHECKOUT_RECONCILE_ENABLED is deliberately turned on (the command checks it too).
 * onOneServer() needs a shared cache store (database/redis) when several app instances run the scheduler.
 */
Schedule::command('checkout:reconcile')
    ->everyFiveMinutes()
    ->when(fn () => (bool) config('checkout.reconcile_enabled'))
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/checkout_reconcile.log'));

/**
 * Checkout messages (booking confirmation, payment received, staff alerts): re-queue due and interrupted deliveries
 * from the outbox. Does nothing until checkouts produce messages; needs the queue worker to deliver them.
 */
Schedule::command('checkout:notifications')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

/**
 * Participant data retention, on the 1st of each month: health notes cleared 12 months
 * after the last dive, records anonymised 3 years after it (see the Participants page).
 */
Schedule::command('participants:retention')
    ->monthlyOn(1, '03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/participant_retention.log'));
