<?php

namespace App\Console\Commands;

use App\Models\Batch;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AssessWeatherBatchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'weather:assess-batches 
                            {--batch= : Optional specific batch ID to assess} 
                            {--days=16 : Forecast window in days from today (default: 16)} 
                            {--force : Force assessment ignoring date filter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch live Open-Meteo marine and weather forecasts for active batches (today and up to 16 days ahead)';

    /**
     * Execute the console command.
     */
    public function handle(WeatherForecastService $weatherService): int
    {
        $timezone = WeatherForecastService::TIMEZONE;
        $today = Carbon::today($timezone);
        $daysAhead = (int) $this->option('days');
        $maxDate = $today->copy()->addDays($daysAhead);
        $specificBatchId = $this->option('batch');
        $force = $this->option('force');

        $this->info("=== Camp FreedivePH: Live Weather & Marine Assessment ===");
        $this->line("Timezone: {$timezone} | Current Date: {$today->toDateString()}");
        $this->line("Target Window: Today ({$today->toDateString()}) to {$maxDate->toDateString()} ({$daysAhead} days horizon)");

        // Get the batches to check
        $query = Batch::query();

        if ($specificBatchId) {
            $query->where('id', $specificBatchId);
        } elseif (!$force) {
            // Batches still going today (end_date >= today) and batches starting within 16 days
            $query->whereNotIn('status', ['cancelled'])
                  ->where('end_date', '>=', $today->toDateString())
                  ->where('start_date', '<=', $maxDate->toDateString());
        }

        $batches = $query->orderBy('start_date', 'asc')->get();

        if ($batches->isEmpty()) {
            $this->warn("No active batches found within the {$daysAhead}-day forecast window.");
            return self::SUCCESS;
        }

        $this->info("Found {$batches->count()} batch(es) eligible for live forecast assessment:\n");

        $successCount = 0;
        $failCount = 0;

        foreach ($batches as $batch) {
            $batchCode = $batch->batch_code;
            $startDateStr = $batch->start_date->format('M d, Y');
            $endDateStr = $batch->end_date ? $batch->end_date->format('M d, Y') : $startDateStr;
            $daysDiff = $today->diffInDays($batch->start_date->copy()->startOfDay(), false);

            $relativeTag = match (true) {
                $daysDiff < 0 => '[Ongoing Today]',
                $daysDiff === 0 => '[Starts Today]',
                $daysDiff === 1 => '[Starts Tomorrow]',
                default => "[In {$daysDiff} days]",
            };

            $this->line(" Assessing Batch: <fg=yellow>{$batchCode}</> ({$startDateStr} to {$endDateStr}) {$relativeTag}...");

            try {
                $result = $weatherService->assessBatch($batch, null, null);

                $overallClass = $result['overall_classification'] ?? 'Safe';
                $day1Class = $result['day1']['classification'] ?? 'N/A';
                $day2Class = $result['day2']['classification'] ?? 'N/A';

                $colorTag = match ($overallClass) {
                    'Very Safe', 'Safe' => 'fg=green',
                    'Moderate' => 'fg=yellow',
                    'High Risk' => 'fg=red',
                    'Critical Risk' => 'fg=red;options=bold',
                    default => 'fg=white',
                };

                $this->line("    Done. Day 1: <fg=cyan>{$day1Class}</> | Day 2: <fg=cyan>{$day2Class}</> | Overall: <{$colorTag}>{$overallClass}</>");
                $successCount++;
            } catch (Exception $e) {
                $this->error("    ✗ Failed assessing batch {$batchCode}: " . $e->getMessage());
                Log::error("Scheduled weather assessment failed for batch {$batch->id}: " . $e->getMessage());
                $failCount++;
            }
        }

        $this->newLine();
        $this->info("Assessment run completed: {$successCount} succeeded, {$failCount} failed.");

        return $failCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
