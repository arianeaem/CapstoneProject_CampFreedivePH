<?php

namespace App\Jobs;

use App\Services\Checkout\CheckoutNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Deliver one checkout outbox message. Carries only the outbox id (no address, PIN or content). Retries come from
 * the outbox (backoff, max attempts, stale-claim recovery), not from the queue, so a queue retry can never send twice.
 */
class SendCheckoutNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 60; // below the database queue's retry_after (90 s)

    public function __construct(public int $notificationId)
    {
    }

    public function handle(CheckoutNotifier $notifier): void
    {
        $notifier->deliver($this->notificationId);
    }
}
