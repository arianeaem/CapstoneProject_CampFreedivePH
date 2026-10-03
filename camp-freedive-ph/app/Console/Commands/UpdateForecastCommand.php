<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateForecastCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forecast:update 
                            {--days=16 : Number of forecast days to pull (default: 16)} 
                            {--assess-batches : Also evaluate and update all active batches}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch and cache 24-hour continuous weather and marine forecasts every 15 minutes for zero-latency lookups';

    /**
     * Execute the console command.
     */
    public function handle(WeatherForecastService $weatherService): int
    {
        $startTime = microtime(true);
        $days = (int) $this->option('days');
        $assessBatches = $this->option('assess-batches');

        $this->info("=== Camp FreedivePH: Updating 24-Hour Continuous Weather & Marine Cache ===");
        $this->line("Target Location: Anilao / Mabini, Batangas (13.7481° N, 120.9408° E)");
        $this->line("Forecast Horizon: Next {$days} Days (24 Hours/Day = " . ($days * 24) . " Hourly Intervals)");

        try {
            // 1. Fetch and Cache Continuous 16-Day Forecasts
            $result = $weatherService->updateAllForecasts($days);
            $cacheDuration = round((microtime(true) - $startTime) * 1000, 2);

            $this->info("Successfully cached {$result['days_cached']} days of 24h continuous forecast data in {$cacheDuration}ms.");
            $this->line("  Source Marine Endpoint: https://marine-api.open-meteo.com/v1/marine");
            $this->line("  Source Weather Endpoint: https://api.open-meteo.com/v1/forecast");
            $this->line("  Last Updated: " . $result['updated_at']);

            // Render 7-day preview table
            $headers = ['Date', '24h Overall Risk', 'Daytime (06-18h)', 'AM Window', 'PM Window', 'Wave (Hs)', 'Wind Speed'];
            $rows = [];

            foreach (array_slice($result['daily_summaries'], 0, 7) as $date => $sum) {
                $rows[] = [
                    $date,
                    $sum['overall_classification'],
                    $sum['daytime_classification'],
                    $sum['am_classification'],
                    $sum['pm_classification'],
                    $sum['avg_wave_height'] . ' m (max: ' . $sum['max_wave_height'] . 'm)',
                    $sum['avg_wind_speed'] . ' km/h (max: ' . $sum['max_wind_speed'] . 'km/h)',
                ];
            }

            $this->table($headers, $rows);

            // 2. Assess Active Batches if requested
            if ($assessBatches) {
                $this->info("\nAssessing active batches within forecast window...");
                $today = Carbon::today(WeatherForecastService::TIMEZONE);
                $maxDate = $today->copy()->addDays($days);

                $batches = Batch::whereNotIn('status', ['cancelled'])
                    ->where('end_date', '>=', $today->toDateString())
                    ->where('start_date', '<=', $maxDate->toDateString())
                    ->orderBy('start_date', 'asc')
                    ->get();

                if ($batches->isNotEmpty()) {
                    foreach ($batches as $batch) {
                        $assessResult = $weatherService->assessBatch($batch, null, null);
                        $this->line("  - Batch <fg=yellow>{$batch->batch_code}</>: Overall Risk = <fg=green>{$assessResult['overall_classification']}</>");
                    }
                    $this->info("Successfully evaluated {$batches->count()} active batch(es).");
                } else {
                    $this->line("  No active batches found in the {$days}-day window.");
                }
            }

            $totalElapsed = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("\nFinished forecast update pipeline in {$totalElapsed}ms. All requests will now be served from local cache with <1ms latency.");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to update forecast cache: " . $e->getMessage());
            Log::error("Forecast cache update cron failed: " . $e->getMessage(), ['exception' => $e]);
            return self::FAILURE;
        }
    }
}
