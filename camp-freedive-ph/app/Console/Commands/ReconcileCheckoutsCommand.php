<?php

namespace App\Console\Commands;

use App\Services\Checkout\CheckoutReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Recover checkout payments whose return page and webhook were both missed, and expire abandoned unpaid checkouts.
 * Does nothing unless CHECKOUT_RECONCILE_ENABLED is on. --dry-run only lists due checkouts (no PayMongo call, no writes).
 */
class ReconcileCheckoutsCommand extends Command
{
    protected $signature = 'checkout:reconcile
        {--limit= : Maximum checkouts to process in this run (default: config checkout.reconcile.batch_size)}
        {--dry-run : List the checkouts that are due, by group, without contacting PayMongo or changing anything}';

    protected $description = 'Recover missed checkout payments and expire abandoned unpaid checkouts';

    public function handle(CheckoutReconciler $reconciler): int
    {
        $limit = max(1, min(500, (int) ($this->option('limit') ?: config('checkout.reconcile.batch_size'))));

        if ($this->option('dry-run')) {
            foreach ($reconciler->candidates($limit) as $group => $intents) { // read-only: cursors are not advanced
                $ids = collect($intents)->pluck('id')->implode(', ');
                $this->line(sprintf('%-8s %d due%s', $group, count($intents), $ids !== '' ? " (intent ids: {$ids})" : ''));
            }
            $this->info('Dry run: nothing was processed.');

            return self::SUCCESS;
        }

        if (! config('checkout.reconcile_enabled')) {
            $this->warn('Checkout reconciliation is turned off (CHECKOUT_RECONCILE_ENABLED). Nothing was processed.');

            return self::SUCCESS;
        }

        // One run at a time across servers (shared cache store). The finalizer's database locks remain the final guard.
        $maxSeconds = (int) config('checkout.reconcile.max_run_seconds');
        $lock = Cache::lock(CheckoutReconciler::RUN_LOCK, $maxSeconds + 60);
        if (! $lock->get()) {
            $this->info('Another checkout reconciliation run is in progress; skipped.');

            return self::SUCCESS;
        }

        try {
            $summary = $reconciler->run($limit, $maxSeconds);
        } finally {
            $lock->release();
        }

        Log::info('Checkout reconciliation finished.', $summary);
        $this->info(collect($summary)->map(fn ($n, $k) => "{$k}={$n}")->implode(' '));

        return self::SUCCESS;
    }
}
