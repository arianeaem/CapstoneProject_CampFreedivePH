<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class RetrainDemandCommandTest extends TestCase
{
    /**
     * Test that demand:retrain command executes successfully in dry-run mode.
     */
    public function test_demand_retrain_command_dry_run(): void
    {
        $this->artisan('demand:retrain', ['--dry-run' => true])
            ->expectsOutputToContain('CAMP FREEDIVEPH: AI DEMAND & REVENUE RETRAINING PIPELINE')
            ->expectsOutputToContain('DRY RUN: Retraining execution simulated successfully.')
            ->assertExitCode(0);
    }

    /**
     * Test that ml:retrain-demand alias also executes successfully in dry-run mode.
     */
    public function test_demand_retrain_alias_command_dry_run(): void
    {
        $this->artisan('ml:retrain-demand', ['--dry-run' => true])
            ->expectsOutputToContain('CAMP FREEDIVEPH: AI DEMAND & REVENUE RETRAINING PIPELINE')
            ->expectsOutputToContain('DRY RUN: Retraining execution simulated successfully.')
            ->assertExitCode(0);
    }

    /**
     * The nightly demand:retrain schedule is DISABLED so the old flow cannot
     * overwrite the new 553-record pipeline. The command itself stays available for manual runs.
     */
    public function test_demand_retrain_is_not_scheduled_nightly(): void
    {
        $schedule = app(Schedule::class);
        $events = collect($schedule->events());

        $retrainEvent = $events->first(function (Event $event) {
            return str_contains($event->command, 'demand:retrain');
        });

        $this->assertNull($retrainEvent, "demand:retrain must not be scheduled nightly");
    }

    /**
     * demand:retrain should fail cleanly when the script path is wrong.
     */
    public function test_demand_retrain_handles_invalid_script_path(): void
    {
        $this->artisan('demand:retrain', ['--script' => '/invalid/path/nonexistent.py'])
            ->expectsOutputToContain('Demand model retraining failed: Script not found')
            ->assertExitCode(1);
    }
}
