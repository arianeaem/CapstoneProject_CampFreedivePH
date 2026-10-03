<?php

namespace App\Console\Commands;

use App\Models\ForecastAccuracyLog;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ArchiveForecastAccuracyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'forecast:archive-accuracy 
                            {--date= : Specific target date to audit (YYYY-MM-DD, default: yesterday)} 
                            {--lead-times=1,3,7,14 : Comma-separated lead time horizons in days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive historical forecast accuracy by comparing multi-horizon predictions with realized on-the-water conditions';

    /**
     * Execute the console command.
     */
    public function handle(WeatherForecastService $weatherService): int
    {
        $startTime = microtime(true);
        $dateOption = $this->option('date');
        $leadTimesOption = $this->option('lead-times');

        $targetDate = $dateOption 
            ? Carbon::parse($dateOption, WeatherForecastService::TIMEZONE) 
            : Carbon::yesterday(WeatherForecastService::TIMEZONE);

        $leadTimesStr = (!is_null($leadTimesOption) && $leadTimesOption !== '') ? $leadTimesOption : '1,3,7,14';
        $leadTimes = array_map('intval', explode(',', $leadTimesStr));

        $this->info("=== Camp FreedivePH: Automated Forecast Accuracy Audit & Verification ===");
        $this->line("Target Audit Date (T-0): <fg=cyan>{$targetDate->format('Y-m-d (D)')}</>");
        $this->line("Evaluation Horizons: <fg=yellow>" . implode('d, ', $leadTimes) . "d</>");
        $this->line("Location: Anilao / Mabini, Batangas Primary Training Basin");

        try {
            $result = $weatherService->archiveForecastAccuracy($targetDate, $leadTimes);

            $this->info("\nRealized On-The-Water Conditions:");
            $this->line("  - Realized Classification: <fg=green>{$result['actual_classification']}</> (Score: {$result['actual_metrics']['score_pct']}%)");
            $this->line("  - Realized Wave Height (Hs): {$result['actual_metrics']['wave_height']} m");
            $this->line("  - Realized Wind Speed: {$result['actual_metrics']['wind_speed']} km/h");
            $this->line("  - Realized Ocean Current: {$result['actual_metrics']['ocean_current']} m/s");
            $this->line("  - Realized Rainfall: {$result['actual_metrics']['rain']} mm");

            if (empty($result['verified_horizons'])) {
                $this->warn("\nNo historical forecast snapshots were found for {$result['target_date']} at the requested horizons (" . implode(', ', $leadTimes) . " days).");
                $this->line("Tip: Run `php artisan forecast:update` daily to automatically accumulate multi-horizon snapshots.");
                return self::SUCCESS;
            }

            $headers = ['Horizon', 'Predicted Class', 'Realized Class', 'Match', 'Δ Wave (m)', 'Δ Wind (km/h)', 'Δ Current (m/s)', 'Accuracy %'];
            $rows = [];

            foreach ($result['verified_horizons'] as $log) {
                /** @var ForecastAccuracyLog $log */
                $matchSymbol = $log->classification_matched ? '<fg=green>MATCH</>' : '<fg=red>MISMATCH</>';
                $rows[] = [
                    $log->lead_time_label,
                    $log->predicted_classification,
                    $log->actual_classification,
                    $matchSymbol,
                    $log->wave_height_error . ' m',
                    $log->wind_speed_error . ' km/h',
                    $log->current_error . ' m/s',
                    $log->accuracy_score_pct . '%',
                ];
            }

            $this->table($headers, $rows);

            $this->info("Audit Summary Statistics:");
            $this->line("  - Average Accuracy Score: <fg=green>{$result['average_accuracy_score']}%</>");
            $this->line("  - Wave Height MAE: {$result['mae_metrics']['wave_height']} m");
            $this->line("  - Wind Speed MAE: {$result['mae_metrics']['wind_speed']} km/h");
            $this->line("  - Ocean Current MAE: {$result['mae_metrics']['ocean_current']} m/s");

            $elapsed = round((microtime(true) - $startTime) * 1000, 2);
            $this->info("\nSuccessfully persisted {$result['verified_count']} forecast accuracy log(s) into database in {$elapsed}ms.");

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Failed to execute forecast accuracy audit: " . $e->getMessage());
            Log::error("Forecast accuracy audit command failed: " . $e->getMessage(), ['exception' => $e]);
            return self::FAILURE;
        }
    }
}
