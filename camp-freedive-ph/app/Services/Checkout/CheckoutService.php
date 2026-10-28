<?php

namespace App\Services\Checkout;

use App\Models\CheckoutIntent;
use App\Services\AuditLogger;
use App\Services\BatchManagementService;
use App\Services\Booking\OperatingDate;
use App\Services\Gateways\PayMongoGateway;
use App\Services\SlotReservationService;
use Carbon\Carbon;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Starts a checkout without creating an official booking.
 *
 *  1. Under the date capacity lock, in one short transaction with the PostgreSQL advisory date lock (the same
 *     protocol as BookingFinalizer): check capacity and save a pending intent with a 15-minute hold
 *     (the hold is the intent row itself, so check + hold commit together).
 *  2. After commit and lock release, outside any transaction: ask PayMongo for a checkout session.
 *  3. Save the session id, or mark the intent failed so it holds nothing.
 *
 * No booking, participant, batch, payment or price-adjustment row is written here, and no
 * notification is sent; that only happens after the payment is verified (Batch 2).
 */
class CheckoutService
{
    public function __construct(
        protected SlotReservationService $slots,
        protected PayMongoGateway $gateway,
        protected BatchManagementService $batches,
    ) {
    }

    /**
     * @param array $intent plain columns: start_date, end_date, pax_count, class_type, pickup_option, total_amount, downpayment_amount
     * @param array $payload encrypted details: booking, contact, guardian, participants, pricing
     * @return array{ok: bool, status: int, message?: string, intent?: CheckoutIntent, checkout_url?: string}
     */
    public function start(array $intent, array $payload): array
    {
        $busy = ['ok' => false, 'status' => 429, 'message' => 'The booking system is currently processing high-volume simultaneous checkouts. Please retry in a few moments.'];
        $date = Carbon::parse($intent['start_date'])->format('Y-m-d');

        // Same capacity protocol as BookingFinalizer: date lock, then one short transaction holding the
        // PostgreSQL advisory date lock for the capacity check and the hold insert (committed together)
        try {
            $created = $this->slots->withCapacityLock($date, function (Lock $lock) use ($intent, $payload, $date) {
                return DB::transaction(function () use ($intent, $payload, $date, $lock) {
                    BookingFinalizer::lockCapacityInTransaction($this->slots, $date);

                    // G1: no hold or payment for a date the camp is not running (checked under the date lock)
                    $operating = $this->batches->operatingDate($date);
                    if (! $operating->isOperating()) {
                        return $operating;
                    }

                    $available = $this->slots->getAvailableSlots($date);
                    if ($intent['pax_count'] > $available) {
                        return $available;
                    }

                    $created = $this->createIntent($intent, $payload);
                    $this->slots->assertLockStillHeld($lock); // lease lost: roll the hold back instead of committing it

                    return $created;
                });
            }, BookingFinalizer::LOCK_LEASE_SECONDS, BookingFinalizer::LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $busy;
        } catch (Throwable $e) {
            if (BookingFinalizer::isCapacityLockFailure($e)) {
                return $busy;
            }
            throw $e;
        }

        if ($created instanceof OperatingDate) {
            return ['ok' => false, 'status' => 422, 'message' => "The camp is not running on {$intent['start_date']}. Please select another date."];
        }
        if (! $created instanceof CheckoutIntent) {
            return ['ok' => false, 'status' => 422, 'message' => "Capacity limit reached for {$intent['start_date']}. Only {$created} slot(s) remaining for this weekend trip. Please select another date or reduce group size."];
        }

        // External call: no lock or transaction is held here
        try {
            $session = $this->gateway->createCheckoutSessionForIntent($created, $payload['contact']);
        } catch (Throwable $e) {
            $session = ['success' => false, 'error' => class_basename($e)];
        }

        if (empty($session['success']) || empty($session['checkout_id']) || empty($session['checkout_url'])) {
            $this->markFailed($created, 'checkout_session_failed');
            Log::warning('Checkout session could not be created.', ['checkout_reference' => $created->reference]);

            return ['ok' => false, 'status' => 502, 'message' => 'We could not start the secure PayMongo payment page. Nothing was charged. Please try again in a few minutes.'];
        }

        try {
            $created->update(['paymongo_checkout_session_id' => $session['checkout_id']]);
        } catch (Throwable $e) {
            // PayMongo has a session, but we could not record it. The customer is not sent to it, so it
            // cannot be paid through our flow, and the intent's hold lapses on its own after 15 minutes.
            // If a payment still arrives, its metadata (checkout_intent_id, checkout_reference) identifies
            // this intent for reconciliation. Logged as critical so staff can check PayMongo.
            Log::critical('Checkout session created at PayMongo but not saved locally.', [
                'checkout_reference' => $created->reference,
                'checkout_session_id' => $session['checkout_id'],
                'exception' => class_basename($e),
            ]);

            return ['ok' => false, 'status' => 500, 'message' => 'We could not start your checkout. Nothing was charged. Please try again in a few minutes.'];
        }

        AuditLogger::log(
            'CHECKOUT_STARTED',
            "Checkout {$created->reference} started ({$created->pax_count} pax, {$created->start_date->format('Y-m-d')}). Awaiting payment; no booking created.",
            null,
            'Customer checkout'
        );

        return ['ok' => true, 'status' => 200, 'intent' => $created, 'checkout_url' => $session['checkout_url']];
    }

    private function createIntent(array $intent, array $payload): CheckoutIntent
    {
        $attributes = $intent + [
            'status' => CheckoutIntent::STATUS_PENDING,
            'currency' => 'PHP',
            'payload' => $payload,
            'contact_email_hash' => CheckoutIntent::emailHash($payload['contact']['email'] ?? null),
            'hold_expires_at' => now()->addMinutes(CheckoutIntent::HOLD_MINUTES),
        ];

        // A collision of 12 random characters is very unlikely; the unique index decides, retry a few times.
        // Each attempt is a savepoint, so a collision does not abort the surrounding PostgreSQL transaction.
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => CheckoutIntent::create($attributes + ['reference' => CheckoutIntent::newReference()]));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** A failed intent is kept for diagnostics but never holds capacity. */
    private function markFailed(CheckoutIntent $intent, string $reason): void
    {
        try {
            $intent->update([
                'status' => CheckoutIntent::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $reason,
                'hold_expires_at' => now(),
            ]);
        } catch (Throwable $e) {
            // If even this fails, the pending hold still lapses by itself at hold_expires_at
            Log::error('Failed checkout intent could not be marked failed.', ['checkout_reference' => $intent->reference, 'exception' => class_basename($e)]);
        }
    }
}
