<?php

namespace App\Services\Checkout;

use App\Jobs\SendCheckoutNotification;
use App\Mail\AdminOperationalNotificationMail;
use App\Mail\BookingConfirmedMail;
use App\Mail\CheckoutPaymentReceivedMail;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\CheckoutNotification;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Booking\CapacityCoordinator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Messages that follow a committed checkout outcome, through a durable outbox (checkout_notifications):
 *  - finalized:        customer booking confirmation (booking number, PIN, manage link) + one alert per owner/admin
 *  - paid_unallocated: customer "payment received, booking under review" (no booking number or PIN exists)
 *                      + one critical alert per owner/admin
 * Nothing is recorded for unpaid checkouts or payment review (no payment or booking is claimed).
 *
 * Delivery (at-least-once, deduplicated per checkout + message + recipient):
 *   pending -> sending (atomic claim with a token; attempts + 1) -> sent        mailer returned without error
 *                                                                -> pending     error: retried after backoff
 *                                                                -> failed      error on the last attempt: kept for staff
 *   A "sending" claim older than stale_claim_minutes is a crashed worker: claimable again (or failed if exhausted).
 * A message is marked sent only after the mailer returns, so a crash never loses one; it can repeat one if the worker
 * dies after the mail server accepted it and before the row was updated. Mail sending is never atomic with the database.
 *
 * Legacy bookings (no checkout intent) confirmed by the PayMongo webhook or return page get their customer confirmation
 * through the same outbox (recordForBooking: rows keyed by booking_id instead of checkout_intent_id).
 * A booking confirmation is only ever delivered (or re-sent) while the booking still holds a place.
 *
 * The caller's booking and payment are already committed; nothing here can undo them, and nothing here throws.
 * Logs carry ids, message kind and exception class only: never addresses, PINs or message content.
 */
class CheckoutNotifier
{
    /** After a finalized / paid_unallocated outcome (return page, webhook, reconciler, staff re-check). */
    public function afterOutcome(CheckoutIntent $intent): void
    {
        try {
            $intent = $intent->fresh();
            if ($intent && $this->record($intent)) {
                $this->dispatchDue(CheckoutNotification::where('checkout_intent_id', $intent->id));
            }
        } catch (Throwable $e) {
            Log::error('Checkout notifications could not be recorded.', ['checkout_intent_id' => $intent?->id, 'exception' => class_basename($e)]);
        }
    }

    /**
     * Write this outcome's messages to the outbox, once per checkout. Returns whether anything may need sending.
     * Concurrent callers are safe: rows are unique per checkout + message + recipient (insertOrIgnore).
     */
    public function record(CheckoutIntent $intent): bool
    {
        if (! in_array($intent->status, [CheckoutIntent::STATUS_FINALIZED, CheckoutIntent::STATUS_PAID_UNALLOCATED], true)) {
            return false;
        }
        if ($intent->notifications_recorded_at !== null) {
            return true;
        }

        $finalized = $intent->status === CheckoutIntent::STATUS_FINALIZED;
        $customer = $finalized ? $intent->booking?->contact_email : ($intent->payload['contact']['email'] ?? null);
        $messages = [];
        if (is_string($customer) && filter_var($customer, FILTER_VALIDATE_EMAIL)) {
            $messages[] = [$finalized ? CheckoutNotification::CUSTOMER_BOOKING_CONFIRMED : CheckoutNotification::CUSTOMER_PAYMENT_RECEIVED, $customer];
        }
        // Owners and admins only (never coaches), one row each so every recipient's delivery is tracked on its own
        foreach (User::whereIn('role', ['admin', 'owner'])->where('status', 'active')->whereNotNull('email')->pluck('email')->unique() as $staff) {
            $messages[] = [$finalized ? CheckoutNotification::STAFF_NEW_BOOKING : CheckoutNotification::STAFF_PAID_UNALLOCATED, $staff];
        }

        DB::transaction(function () use ($intent, $messages) {
            $now = now();
            CheckoutNotification::query()->insertOrIgnore(array_map(fn (array $m) => [
                'checkout_intent_id' => $intent->id,
                'kind' => $m[0],
                'recipient' => Crypt::encryptString($m[1]),
                'recipient_hash' => hash('sha256', strtolower(trim($m[1]))),
                'status' => CheckoutNotification::PENDING,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ], $messages));
            // Recorded even when there is nobody to write to, so it is never selected again
            CheckoutIntent::whereKey($intent->id)->whereNull('notifications_recorded_at')->update(['notifications_recorded_at' => $now]);
        });

        return $messages !== [];
    }

    /** A legacy booking was just confirmed (a real state change): its customer confirmation, once per booking. */
    public function recordForBooking(Booking $booking): void
    {
        try {
            $email = $booking->contact_email;
            if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $now = now();
            CheckoutNotification::query()->insertOrIgnore([[
                'checkout_intent_id' => null,
                'booking_id' => $booking->id,
                'kind' => CheckoutNotification::CUSTOMER_BOOKING_CONFIRMED,
                'recipient' => Crypt::encryptString($email),
                'recipient_hash' => hash('sha256', strtolower(trim($email))),
                'status' => CheckoutNotification::PENDING,
                'attempts' => 0,
                'available_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]]);
            $this->dispatchDue(CheckoutNotification::where('booking_id', $booking->id));
        } catch (Throwable $e) {
            Log::error('Booking confirmation could not be recorded.', ['booking_id' => $booking->id, 'exception' => class_basename($e)]);
        }
    }

    /**
     * Queue due pending messages from $query. Each is leased for dispatch_lease_minutes first (conditional update), so
     * repeated callers never queue it twice meanwhile; if queuing fails, the lease runs out and the sweep tries again.
     */
    public function dispatchDue(Builder $query, ?int $limit = null): int
    {
        $lease = now()->addMinutes((int) config('checkout.notifications.dispatch_lease_minutes'));
        $ids = (clone $query)->where('status', CheckoutNotification::PENDING)->where('available_at', '<=', now())
            ->orderBy('id')->limit($limit ?? (int) config('checkout.notifications.batch_size'))->pluck('id');

        $queued = 0;
        foreach ($ids as $id) {
            $leased = CheckoutNotification::whereKey($id)->where('status', CheckoutNotification::PENDING)
                ->where('available_at', '<=', now())->update(['available_at' => $lease, 'updated_at' => now()]);
            if ($leased !== 1) {
                continue;
            }
            try {
                SendCheckoutNotification::dispatch($id)->afterCommit();
                $queued++;
            } catch (Throwable $e) {
                Log::warning('Checkout notification could not be queued; the sweep will retry.', ['checkout_notification_id' => $id, 'exception' => class_basename($e)]);
            }
        }

        return $queued;
    }

    /** "sending" claims older than stale_claim_minutes: the worker died. Make them claimable again, or failed if exhausted. */
    public function recoverStale(): int
    {
        $stale = now()->subMinutes((int) config('checkout.notifications.stale_claim_minutes'));
        $max = (int) config('checkout.notifications.max_attempts');
        $base = fn () => CheckoutNotification::where('status', CheckoutNotification::SENDING)->where('claimed_at', '<=', $stale);

        $failed = $base()->where('attempts', '>=', $max)->get();
        foreach ($failed as $n) {
            if (CheckoutNotification::whereKey($n->id)->where('claim_token', $n->claim_token)
                ->update(['status' => CheckoutNotification::FAILED, 'claim_token' => null, 'last_error' => 'WorkerInterrupted', 'updated_at' => now()]) === 1) {
                $this->auditExhausted($n);
            }
        }

        return $failed->count() + $base()->where('attempts', '<', $max)
            ->update(['status' => CheckoutNotification::PENDING, 'claim_token' => null, 'available_at' => now(), 'last_error' => 'WorkerInterrupted', 'updated_at' => now()]);
    }

    /** Deliver one message (queued job). Safe to call any number of times for the same id. */
    public function deliver(int $id): void
    {
        $token = (string) Str::uuid();
        $max = (int) config('checkout.notifications.max_attempts');
        $claimed = CheckoutNotification::whereKey($id)
            ->where(fn (Builder $q) => $q->where('status', CheckoutNotification::PENDING)
                ->orWhere(fn (Builder $s) => $s->where('status', CheckoutNotification::SENDING)
                    ->where('claimed_at', '<=', now()->subMinutes((int) config('checkout.notifications.stale_claim_minutes')))))
            ->where('attempts', '<', $max)
            ->update(['status' => CheckoutNotification::SENDING, 'claim_token' => $token, 'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return; // sent, failed, or another worker holds it
        }

        $n = CheckoutNotification::with(['checkoutIntent.booking', 'booking'])->findOrFail($id);
        $booking = $n->checkoutIntent?->booking ?? $n->booking;
        if ($n->kind === CheckoutNotification::CUSTOMER_BOOKING_CONFIRMED && ($booking === null || ! CapacityCoordinator::takesPlace($booking->status))) {
            // Cancelled, unpaid or unplaced since the message was recorded: never tell the guest it is confirmed
            CheckoutNotification::whereKey($id)->where('claim_token', $token)->update([
                'status' => CheckoutNotification::FAILED, 'claim_token' => null, 'last_error' => 'BookingNotConfirmed', 'updated_at' => now(),
            ]);
            AuditLogger::log('CHECKOUT_NOTIFICATION_WITHHELD', 'Booking confirmation for ' . ($booking?->booking_number ?? 'a removed booking')
                . ' not sent: the booking is ' . ($booking?->status ?? 'missing') . '.', null, 'Checkout notifications');

            return;
        }
        try {
            Mail::to($n->recipient)->send($this->mailable($n));
        } catch (Throwable $e) {
            $this->failedAttempt($n, $token, $e, $max);

            return;
        }

        $sent = CheckoutNotification::whereKey($id)->where('claim_token', $token)
            ->update(['status' => CheckoutNotification::SENT, 'sent_at' => now(), 'claim_token' => null, 'last_error' => null, 'updated_at' => now()]);
        if ($sent === 1) {
            // First delivered message of each audience, for quick reading on the checkout row
            CheckoutIntent::whereKey($n->checkout_intent_id)->whereNull($column = $n->isForCustomer() ? 'customer_notified_at' : 'staff_notified_at')
                ->update([$column => now()]);
        }
    }

    /** Staff action: a legacy booking's failed confirmation back in the queue (still only sent while it holds a place). */
    public function retryFailedForBooking(Booking $booking): int
    {
        $reset = CheckoutNotification::where('booking_id', $booking->id)->where('status', CheckoutNotification::FAILED)
            ->update(['status' => CheckoutNotification::PENDING, 'attempts' => 0, 'available_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        $this->dispatchDue(CheckoutNotification::where('booking_id', $booking->id));

        return $reset;
    }

    /** Staff action: put this checkout's failed messages back in the queue with a fresh attempt budget. */
    public function retryFailed(CheckoutIntent $intent): int
    {
        $reset = $intent->notifications()->where('status', CheckoutNotification::FAILED)
            ->update(['status' => CheckoutNotification::PENDING, 'attempts' => 0, 'available_at' => now(), 'last_error' => null, 'updated_at' => now()]);
        $this->dispatchDue(CheckoutNotification::where('checkout_intent_id', $intent->id));

        return $reset;
    }

    private function failedAttempt(CheckoutNotification $n, string $token, Throwable $e, int $max): void
    {
        $exhausted = $n->attempts >= $max;
        $steps = (array) config('checkout.notifications.backoff_minutes');
        $wait = (int) ($steps[min($n->attempts, count($steps)) - 1] ?? end($steps));

        $updated = CheckoutNotification::whereKey($n->id)->where('claim_token', $token)->update([
            'status' => $exhausted ? CheckoutNotification::FAILED : CheckoutNotification::PENDING,
            'available_at' => now()->addMinutes($wait),
            'claim_token' => null,
            'last_error' => Str::limit(class_basename($e), 120, ''),
            'updated_at' => now(),
        ]);
        Log::warning('Checkout notification not delivered.', ['checkout_notification_id' => $n->id, 'kind' => $n->kind,
            'attempt' => $n->attempts, 'final' => $exhausted, 'exception' => class_basename($e)]);
        if ($exhausted && $updated === 1) {
            $this->auditExhausted($n);
        }
    }

    private function auditExhausted(CheckoutNotification $n): void
    {
        $subject = $n->checkoutIntent ? "Checkout {$n->checkoutIntent->reference}" : "Booking {$n->booking?->booking_number}";
        AuditLogger::log('CHECKOUT_NOTIFICATION_FAILED', "{$subject}: message {$n->kind} could not be delivered after {$n->attempts} attempts; see Checkout Review.", null, 'Checkout notifications');
    }

    private function mailable(CheckoutNotification $n): Mailable
    {
        $intent = $n->checkoutIntent;
        $booking = $intent?->booking ?? $n->booking;
        $dated = $intent ?? $booking;
        $dates = "{$dated?->start_date?->format('M d, Y')} - {$dated?->end_date?->format('M d, Y')}";

        return match ($n->kind) {
            CheckoutNotification::CUSTOMER_BOOKING_CONFIRMED => new BookingConfirmedMail(($booking ?? throw new LogicException('No booking for a confirmation.'))->load('participants', 'payments')),
            CheckoutNotification::CUSTOMER_PAYMENT_RECEIVED => new CheckoutPaymentReceivedMail($intent),
            // Same content as AdminNotificationService::newBooking; never the PIN
            CheckoutNotification::STAFF_NEW_BOOKING => new AdminOperationalNotificationMail(
                'New booking received',
                "Booking {$booking?->booking_number} was created for {$booking?->contact_name}.",
                ['Booking' => (string) $booking?->booking_number, 'Contact' => (string) $booking?->contact_name, 'Schedule' => $dates, 'Status' => (string) $booking?->status],
            ),
            CheckoutNotification::STAFF_PAID_UNALLOCATED => new AdminOperationalNotificationMail(
                'Paid checkout needs a booking decision',
                "Checkout {$intent->reference} was paid, but " . ($intent->unallocated_reason === 'date_not_operating' ? 'the camp is not running on that date' : 'the date was full')
                    . ', so no booking was created. Review it in Payments > Checkout review.',
                ['Checkout' => $intent->reference, 'Dates' => $dates, 'Participants' => (string) $intent->pax_count],
                'critical',
            ),
            default => throw new LogicException("Unknown checkout message {$n->kind}."),
        };
    }
}
