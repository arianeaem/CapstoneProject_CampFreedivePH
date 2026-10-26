<?php

namespace App\Console\Commands;

use App\Services\Payment\ReceiptBackfillPlanner;
use Illuminate\Console\Command;

/**
 * DRY RUN ONLY (Batch 3-II B1): prints what a later, separately approved receipt backfill would have to do (counts,
 * and with --json every item) and the plan hash. Writes nothing (read-only transaction, always rolled back), contacts
 * no provider, and is not scheduled. There is no execute option: running a backfill needs its own approved release.
 */
class PlanReceiptBackfillCommand extends Command
{
    protected $signature = 'payments:plan-receipt-backfill {--json : Print the full plan as JSON}';

    protected $description = 'Dry run only: report historical receipts and exceptions a future approved backfill would need (writes nothing)';

    public function handle(ReceiptBackfillPlanner $planner): int
    {
        $plan = $planner->plan();
        if ($this->option('json')) {
            $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('Dry run only: nothing was written.');
        $this->table(['Action', 'Items'], collect($plan['counts'])->map(fn ($n, $action) => [$action, $n])->values()->all());
        $this->line("Plan hash: {$plan['hash']}");

        return self::SUCCESS;
    }
}
