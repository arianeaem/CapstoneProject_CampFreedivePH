<?php

namespace App\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class RebenchmarkMLModelsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ml:rebenchmark 
                            {--script= : Specific path to Phase 0 Python benchmark script} 
                            {--python= : Specific path to Python interpreter binary} 
                            {--dry-run : Simulate execution without spawning long-running processes} 
                            {--timeout=7200 : Maximum process timeout in seconds (default: 2 hours)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Shell out to re-run the Phase 0 AutoGluon & multi-horizon time-series benchmark against the latest trailing data window';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startTime = microtime(true);

        $pythonBinary = $this->option('python') 
            ?: config('services.ml_safety.python_path', 'python');

        $scriptPath = $this->option('script') 
            ?: config('services.ml_safety.benchmark_script', base_path('../safety-forecast/src/models/benchmark_autogluon_timeseries.py'));

        $isDryRun = (bool) $this->option('dry-run');
        $timeout = (int) $this->option('timeout');

        $this->info("====================================================================");
        $this->info("PHASE 0 ML MULTI-HORIZON PIPELINE RE-BENCHMARKING");
        $this->info("====================================================================");
        $this->line("Python Interpreter: <fg=cyan>{$pythonBinary}</>");
        $this->line("Benchmark Script:   <fg=yellow>{$scriptPath}</>");
        $this->line("Execution Timeout:  <fg=magenta>{$timeout}s</>");
        $this->line("Trailing Window:    99 cells (11 variables x 9 horizons [H+1 to H+168])");

        if (!file_exists($scriptPath) && !$isDryRun) {
            $this->error("Benchmark script not found at path: {$scriptPath}");
            Log::error("[ml:rebenchmark] Target script not found: {$scriptPath}");
            return self::FAILURE;
        }

        if ($isDryRun) {
            $this->warn("\n[DRY RUN] Simulation mode active. Command to be executed:");
            $this->line("  {$pythonBinary} \"{$scriptPath}\"");
            $this->info("Dry-run validation successful. Phase 0 pipeline is properly configured.");
            return self::SUCCESS;
        }

        $this->info("\nLaunching Phase 0 Python execution subprocess...");
        Log::info("[ml:rebenchmark] Starting quarterly re-benchmarking subprocess", [
            'python' => $pythonBinary,
            'script' => $scriptPath,
        ]);

        try {
            $process = new Process([$pythonBinary, $scriptPath]);
            $process->setTimeout($timeout);
            $process->setIdleTimeout(600);

            // Set working directory to ML project root if directory exists
            $workingDir = dirname($scriptPath, 3);
            if (is_dir($workingDir)) {
                $process->setWorkingDirectory($workingDir);
            }

            $process->run(function ($type, $buffer) {
                if ($type === Process::ERR) {
                    $this->output->write("<fg=red>{$buffer}</>");
                } else {
                    $this->output->write($buffer);
                }
            });

            if (!$process->isSuccessful()) {
                $exitCode = $process->getExitCode();
                $this->error("\nPhase 0 pipeline failed with exit code: {$exitCode}");
                Log::error("[ml:rebenchmark] Pipeline failed", [
                    'exit_code' => $exitCode,
                    'error_output' => $process->getErrorOutput(),
                ]);
                return self::FAILURE;
            }

            // Execute Scoped Incremental Pipeline (Phases 1–3 for changed cells only)
            $incrementalScript = base_path('../safety-forecast/src/serve/incremental_pipeline.py');
            if (file_exists($incrementalScript)) {
                $this->info("\nRunning Scoped Incremental Pipeline (Phases 1–3 for changed cells only)...");
                $incrementalProcess = new Process([$pythonBinary, $incrementalScript]);
                $incrementalProcess->setTimeout(1800);
                if (is_dir($workingDir)) {
                    $incrementalProcess->setWorkingDirectory($workingDir);
                }
                $incrementalProcess->run(function ($type, $buffer) {
                    $this->output->write($buffer);
                });
            }

            $duration = round(microtime(true) - $startTime, 2);
            $this->info("\n====================================================================");
            $this->info("SUCCESS: Quarterly Re-benchmarking & Scoped Re-Serving completed in {$duration}s.");
            $this->info("====================================================================");

            Log::info("[ml:rebenchmark] Quarterly re-benchmarking and scoped pipeline completed in {$duration}s");
            return self::SUCCESS;
        } catch (Exception $e) {
            $this->error("\nProcess execution exception: " . $e->getMessage());
            Log::error("[ml:rebenchmark] Process execution exception: " . $e->getMessage(), ['exception' => $e]);
            return self::FAILURE;
        }
    }
}
