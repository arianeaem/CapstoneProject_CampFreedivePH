<?php

namespace App\Console\Commands;

use App\Models\CheckoutIntent;
use App\Models\CheckoutNotification;
use App\Services\Checkout\CheckoutNotifier;
use Illuminate\Console\Command;

/**
 * Keeps checkout messages moving (scheduled every minute): records the messages of recent outcomes that were never
 * recorded (the request died right after committing the booking), recovers claims of crashed workers, and queues due
 * messages. No PayMongo call, no payment or booking change. Independent of CHECKOUT_RECONCILE_ENABLED.
 */
class SendCheckoutNotificationsCommand extends Command
{
    protected $signature = 'checkout:notifications';

    protected $description = 'Record and queue checkout confirmation and staff messages, and recover interrupted deliveries';

    public function handle(CheckoutNotifier $notifier): int
    {
        $limit = (int) config('checkout.notifications.batch_size');
        $unrecorded = CheckoutIntent::whereIn('status', [CheckoutIntent::STATUS_FINALIZED, CheckoutIntent::STATUS_PAID_UNALLOCATED])
            ->whereNull('notifications_recorded_at')
            ->where('updated_at', '<=', now()->subMinute()) // not the request finishing it now
            ->where('updated_at', '>=', now()->subDays((int) config('checkout.notifications.record_window_days')))
            ->orderBy('id')->limit($limit)->get();
        $unrecorded->each(fn (CheckoutIntent $intent) => $notifier->record($intent));

        $recovered = $notifier->recoverStale();
        $queued = $notifier->dispatchDue(CheckoutNotification::query(), $limit);

        $this->info("recorded={$unrecorded->count()} recovered={$recovered} queued={$queued}");

        return self::SUCCESS;
    }
}
