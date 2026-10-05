<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RetrainDemandForecastCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demand:retrain 
                            {--script= : Specific path to Python retrain pipeline script} 
                            {--python= : Specific path to Python interpreter binary} 
                            {--dry-run : Simulate execution without spawning long-running processes} 
                            {--timeout=1800 : Maximum process timeout in seconds (default: 30 minutes)}';

    /**
     * Alternative aliases for the command.
     *
     * @var array<int, string>
     */
    protected $aliases = ['ml:retrain-demand'];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Trigger the automated machine learning retraining pipeline for demand and revenue forecasting';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        $pythonBinary = $this->option('python') 
            ?: config('services.ml_demand.python_path', 'python');

        $scriptPath = $this->option('script') 
            ?: config('services.ml_demand.retrain_script', base_path('../demand-forecast/retrain_pipeline.py'));

        $workingDir = config('services.ml_demand.working_dir', dirname($scriptPath));

        $isDryRun = (bool) $this->option('dry-run');
        $timeout = (int) $this->option('timeout');

        $this->info("====================================================================");
        $this->info("CAMP FREEDIVEPH: AI DEMAND & REVENUE RETRAINING PIPELINE");
        $this->info("====================================================================");
        $this->line("Python Interpreter: <fg=cyan>{$pythonBinary}</>");
        $this->line("Pipeline Script:    <fg=yellow>{$scriptPath}</>");
        $this->line("Working Directory:  <fg=yellow>{$workingDir}</>");
        $this->line("Execution Timeout:  <fg=magenta>{$timeout}s</>");

        if (!file_exists($scriptPath) && !$isDryRun) {
            $this->error("Demand model retraining failed: Script not found at {$scriptPath}");
            Log::error("Demand model retraining failed: Script not found at {$scriptPath}");
            return self::FAILURE;
        }

        if ($isDryRun) {
            $this->warn("DRY RUN: Retraining execution simulated successfully.");
            return self::SUCCESS;
        }

        Log::info("Demand model retraining started.", [
            'script' => $scriptPath,
            'python' => $pythonBinary,
            'working_dir' => $workingDir,
        ]);

        try {
            $cmd = [$pythonBinary, '-u', $scriptPath];
            $env = array_merge($_SERVER, [
                'PYTHONUNBUFFERED' => '1',
            ]);
            $process = new Process($cmd, $workingDir, $env);
            $process->setTimeout($timeout);
            $process->setIdleTimeout(300);

            $process->run(function ($type, $buffer) {
                if (Process::ERR === $type) {
                    $this->output->write("<fg=red>{$buffer}</>");
                } else {
                    $this->output->write($buffer);
                }
            });

            if (!$process->isSuccessful()) {
                Log::error("Demand model retraining failed.", [
                    'exit_code' => $process->getExitCode(),
                    'error_output' => $process->getErrorOutput(),
                ]);
                $this->error("ML Demand Retrain Pipeline failed with exit code: " . $process->getExitCode());
                return self::FAILURE;
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->info("Demand model retraining completed successfully in {$duration}s");
            Log::info("Demand model retraining completed successfully.", ['duration_seconds' => $duration]);

            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("Demand model retraining failed: " . $e->getMessage());
            Log::error("Demand model retraining failed with exception.", ['message' => $e->getMessage()]);
            return self::FAILURE;
        }
    }
}
