<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class RebenchmarkMLCommandTest extends TestCase
{
    /**
     * Test that ml:rebenchmark command executes successfully in dry-run mode.
     */
    public function test_ml_rebenchmark_command_dry_run(): void
    {
        $this->artisan('ml:rebenchmark', ['--dry-run' => true])
            ->expectsOutputToContain('PHASE 0 ML MULTI-HORIZON PIPELINE RE-BENCHMARKING')
            ->expectsOutputToContain('[DRY RUN] Simulation mode active')
            ->expectsOutputToContain('Dry-run validation successful')
            ->assertExitCode(0);
    }

    /**
     * Test that ml:rebenchmark command is registered in Laravel's quarterly schedule.
     */
    public function test_ml_rebenchmark_is_scheduled_quarterly(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $rebenchmarkEvent = $events->first(function (Event $event) {
            return str_contains($event->command, 'ml:rebenchmark');
        });

        $this->assertNotNull($rebenchmarkEvent, "ml:rebenchmark command is not registered in schedule");
        $this->assertEquals('0 0 1 1-12/3 *', $rebenchmarkEvent->expression, "ml:rebenchmark is not scheduled quarterly");
    }

    /**
     * Test that ml:rebenchmark fails gracefully when given an invalid script path.
     */
    public function test_ml_rebenchmark_handles_invalid_script_path(): void
    {
        $this->artisan('ml:rebenchmark', ['--script' => '/invalid/path/nonexistent.py'])
            ->expectsOutputToContain('Benchmark script not found')
            ->assertExitCode(1);
    }
}
