<?php

namespace App\Services\Checkout;

use App\Models\Batch;
use App\Models\BatchStatusLog;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Services\AuditLogger;
use App\Services\BatchManagementService;
use App\Services\SlotReservationService;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turns a checkout intent with a VERIFIED PayMongo payment into exactly one official booking.
 *
 * The caller runs PaymentVerifier first (network), then calls finalize() outside any transaction.
 * finalize() makes no PayMongo calls. Under the date capacity lock it runs:
 *
 *   Phase A (own transaction): lock the intent row, check its state and payment identity, and save the
 *           verified payment evidence (status `paid`, hold extended by the bounded grace if still live).
 *           Committed on its own, so the payment is never lost if Phase B fails.
 *   Phase B (own transaction): lock the intent row again, re-check payment identity and capacity
 *           (excluding its own hold), then either create batch/booking/participants/adjustments/payment
 *           and mark the intent `finalized`, or mark it `paid_unallocated`. All or nothing.
 *
 * Payment review never shortens or extends a hold: a pending intent under review keeps its original
 * hold (it still counts toward capacity until then), and a paid intent is never moved to review by an
 * inconclusive verification. A later fresh verified payment resumes a review intent through the same
 * identity, amount and capacity checks. Every review event is kept in verification.review_history and the
 * audit log (sanitized: ids, reasons and amounts only).
 *
 * Lock protocol (shared with CheckoutService::start): date capacity lock (bounded wait) -> transaction ->
 * lockCapacityInTransaction() (PostgreSQL advisory date lock, bounded by lock_timeout) -> intent row lock.
 * After commit: batch-allocation lock for renumbering. Weather checks run after all locks are released.
 * No notifications, refunds or customer-facing links here.
 */
class BookingFinalizer
{
    public const LOCK_LEASE_SECONDS = 60;
    public const LOCK_WAIT_SECONDS = 5;
    /** PostgreSQL lock_timeout inside capacity transactions, so a blocked advisory/row lock fails instead of waiting forever. */
    public const DB_LOCK_TIMEOUT = '5s';
    private const BATCH_ALLOCATION_LOCK = 'checkout_batch_allocation';
    private const BOOKING_NUMBER_ATTEMPTS = 5;
    private const REVIEW_HISTORY_LIMIT = 20;
    private const REVIEW_DETAIL_KEYS = [
        'checkout_session_id', 'expected_session_id', 'claimed_session_id', 'returned_session_id', 'payment_id',
        'expected_centavos', 'actual_centavos', 'expected_currency', 'actual_currency', 'paid_count', 'status', 'http_status', 'missing',
    ];
    private const PARTICIPANT_FIELDS = ['name', 'birthdate', 'gender', 'age', 'health_condition', 'swimmer_status', 'price_per_person'];
    private const GUARDIAN_FIELDS = ['contact_birthdate', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_consent_at'];

    public function __construct(
        protected SlotReservationService $slots,
        protected BatchManagementService $batches,
    ) {
    }

    /**
     * Call first inside every transaction that checks or changes capacity (checkout start and finalization),
     * so they serialize per date on PostgreSQL even if a cache lease runs out. The advisory lock is
     * transaction-scoped: held until commit or rollback. SET LOCAL bounds the wait for it and for row locks.
     */
    public static function lockCapacityInTransaction(SlotReservationService $slots, string $date): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '" . self::DB_LOCK_TIMEOUT . "'");
        }
        $slots->lockDateInTransaction($date);
    }

    /** True when the capacity lock lease was lost or a PostgreSQL lock wait timed out (55P03): safe to retry. */
    public static function isCapacityLockFailure(Throwable $e): bool
    {
        if ($e instanceof QueryException) {
            return ($e->errorInfo[0] ?? null) === '55P03';
        }

        return $e instanceof \RuntimeException && $e->getCode() === SlotReservationService::LOCK_LOST;
    }

    /**
     * @param string $source where the verification came from (webhook, return, reconcile); stored with the evidence
     */
    public function finalize(CheckoutIntent $intent, PaymentVerificationResult $verification, string $source): FinalizationResult
    {
        if ($verification->isNotPaid()) {
            return FinalizationResult::notPaid($verification->reason);
        }
        if ($verification->isRetryable()) {
            return FinalizationResult::retryable($verification->reason);
        }
        if ($verification->requiresReview()) {
            return $this->markForReview($intent->id, $verification->reason, $verification->details, $source);
        }

        $payment = $verification->payment;
        if ($payment === null || $payment->checkoutIntentId !== $intent->id) {
            // Not this intent's payment: change nothing on the intent, but leave a trace
            AuditLogger::log('CHECKOUT_PAYMENT_REVIEW', "A verification for checkout intent #" . ($payment?->checkoutIntentId ?? 'none')
                . " (payment " . ($payment?->paymentId ?? 'none') . ") was offered for checkout {$intent->reference}; ignored, nothing changed.", null, 'Checkout finalizer');

            return FinalizationResult::paymentReview('verification_for_another_intent');
        }

        // Defence in depth: the charge must be exactly this intent's server-calculated downpayment
        $expectedCentavos = (int) round((float) $intent->downpayment_amount * 100);
        if ($payment->amountCentavos !== $expectedCentavos) {
            return $this->markForReview($intent->id, 'amount_mismatch', [
                'payment_id' => $payment->paymentId, 'expected_centavos' => $expectedCentavos, 'actual_centavos' => $payment->amountCentavos,
            ], $source);
        }
        if ($payment->currency !== $intent->currency) {
            return $this->markForReview($intent->id, 'currency_mismatch', [
                'payment_id' => $payment->paymentId, 'expected_currency' => $intent->currency, 'actual_currency' => $payment->currency,
            ], $source);
        }

        $date = $intent->start_date->format('Y-m-d');
        $newBatch = null;

        try {
            $result = $this->slots->withCapacityLock($date, function (Lock $lock) use ($intent, $payment, $source, $date, &$newBatch) {
                $recorded = $this->recordVerifiedPayment($intent->id, $payment, $source, $date, $lock);
                if ($recorded !== null) {
                    return $recorded;
                }

                [$result, $newBatch] = $this->createBooking($intent->id, $payment, $source, $date, $lock);

                if ($newBatch !== null) {
                    $this->renumberBatchesAfterCommit();
                }

                return $result;
            }, self::LOCK_LEASE_SECONDS, (int) config('checkout.finalization_lock_wait_seconds', self::LOCK_WAIT_SECONDS));
        } catch (LockTimeoutException) {
            return FinalizationResult::retryable('capacity_lock_unavailable');
        }

        if ($newBatch !== null) {
            $this->batches->assessBatchWeather($newBatch->fresh() ?? $newBatch); // network: only after all locks/transactions
        }

        return $result;
    }

    /** Phase A. Returns a final outcome, or null to continue to Phase B. */
    private function recordVerifiedPayment(int $intentId, VerifiedPayment $payment, string $source, string $date, Lock $lock): ?FinalizationResult
    {
        try {
            return DB::transaction(function () use ($intentId, $payment, $source, $date, $lock) {
                self::lockCapacityInTransaction($this->slots, $date);
                $intent = CheckoutIntent::whereKey($intentId)->lockForUpdate()->firstOrFail();
                $samePayment = $intent->paymongo_payment_id === $payment->paymentId;

                switch ($intent->status) {
                    case CheckoutIntent::STATUS_FINALIZED:
                        return $samePayment
                            ? FinalizationResult::alreadyFinalized($intent->booking)
                            : $this->paymentConflict($intent, 'different_payment_for_finalized_intent', $payment, $source);
                    case CheckoutIntent::STATUS_PAID_UNALLOCATED:
                        return $samePayment
                            ? FinalizationResult::paidUnallocated($intent->unallocated_reason ?? 'capacity_unavailable')
                            : $this->paymentConflict($intent, 'different_payment_for_unallocated_intent', $payment, $source);
                    case CheckoutIntent::STATUS_PAID:
                        // Retry after Phase B did not complete: continue with the same payment only
                        return $samePayment ? null : $this->paymentConflict($intent, 'different_payment_for_paid_intent', $payment, $source);
                }

                // pending, expired, failed, or payment_review (recovery): the same identity checks for all
                $recovering = $intent->status === CheckoutIntent::STATUS_PAYMENT_REVIEW;
                if ($intent->paymongo_payment_id !== null && ! $samePayment) {
                    return $this->paymentConflict($intent, 'different_payment_for_intent', $payment, $source);
                }
                if ($intent->paymongo_checkout_session_id !== null && $intent->paymongo_checkout_session_id !== $payment->checkoutSessionId) {
                    return $this->paymentConflict($intent, 'session_mismatch', $payment, $source);
                }
                if (CheckoutIntent::where('paymongo_payment_id', $payment->paymentId)->whereKeyNot($intent->id)->exists()) {
                    return $this->paymentConflict($intent, 'payment_id_used_by_other_intent', $payment, $source);
                }
                if (Payment::where('paymongo_payment_id', $payment->paymentId)->exists()) {
                    return $this->paymentConflict($intent, 'payment_id_used_by_payment_record', $payment, $source);
                }

                $previousReason = $intent->failure_reason;
                $grace = now()->addMinutes(CheckoutIntent::FINALIZATION_GRACE_MINUTES);
                $history = $intent->verification['review_history'] ?? null;
                $intent->fill([
                    'status' => CheckoutIntent::STATUS_PAID,
                    'paymongo_checkout_session_id' => $payment->checkoutSessionId, // also saves a verified recovered session
                    'paymongo_payment_id' => $payment->paymentId,
                    'paid_amount' => $payment->amount(),
                    'paid_currency' => $payment->currency,
                    'fee_amount' => $payment->feeCentavos !== null ? round($payment->feeCentavos / 100, 2) : null,
                    'payment_method' => $payment->paymentMethod,
                    'paid_at' => $payment->paidAt,
                    'verification' => $payment->evidence($source) + ($history ? ['review_history' => $history] : []),
                    // A live hold is kept a little longer for finalization (bounded); an expired hold stays expired.
                    // Recovery from review keeps the original hold: review never extends it.
                    'hold_expires_at' => ! $recovering && $intent->hasLiveHold() ? $intent->hold_expires_at->max($grace) : $intent->hold_expires_at,
                    'failure_reason' => $recovering ? null : $intent->failure_reason,
                ]);

                $this->slots->assertLockStillHeld($lock);
                $intent->save();

                if ($recovering) {
                    AuditLogger::log('CHECKOUT_REVIEW_RECOVERED', "Checkout {$intent->reference} left payment review ({$previousReason}) after a fresh verified payment {$payment->paymentId}; capacity is checked again before any booking.", null, 'Checkout finalizer');
                }

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            // Another intent saved this payment or session id between our check and our write
            return $this->markForReview($intentId, 'payment_identity_conflict', ['payment_id' => $payment->paymentId], $source);
        } catch (Throwable $e) {
            if (self::isCapacityLockFailure($e)) {
                return FinalizationResult::retryable($e instanceof QueryException ? 'capacity_lock_unavailable' : 'capacity_lock_lost');
            }
            Log::error('Checkout payment could not be recorded.', ['checkout_intent_id' => $intentId, 'exception' => class_basename($e)]);

            return FinalizationResult::retryable('payment_recording_failed');
        }
    }

    /**
     * Phase B. All or nothing; on failure the intent stays `paid` (Phase A) and a retry can finish.
     *
     * @return array{0: FinalizationResult, 1: ?Batch} the result, and the batch if this call created it
     */
    private function createBooking(int $intentId, VerifiedPayment $payment, string $source, string $date, Lock $lock): array
    {
        try {
            return DB::transaction(function () use ($intentId, $payment, $source, $date, $lock) {
                self::lockCapacityInTransaction($this->slots, $date);
                $intent = CheckoutIntent::whereKey($intentId)->lockForUpdate()->firstOrFail();

                if ($intent->status === CheckoutIntent::STATUS_FINALIZED) {
                    return [$intent->paymongo_payment_id === $payment->paymentId
                        ? FinalizationResult::alreadyFinalized($intent->booking)
                        : $this->paymentConflict($intent, 'different_payment_for_finalized_intent', $payment, $source), null];
                }
                if ($intent->status !== CheckoutIntent::STATUS_PAID) {
                    return [match ($intent->status) {
                        CheckoutIntent::STATUS_PAID_UNALLOCATED => FinalizationResult::paidUnallocated($intent->unallocated_reason ?? 'capacity_unavailable'),
                        CheckoutIntent::STATUS_PAYMENT_REVIEW => FinalizationResult::paymentReview($intent->failure_reason ?? 'payment_review'),
                        default => FinalizationResult::retryable('intent_not_paid'),
                    }, null];
                }
                if ($intent->paymongo_payment_id !== $payment->paymentId) {
                    return [$this->paymentConflict($intent, 'different_payment_for_paid_intent', $payment, $source), null];
                }
                if (Payment::where('paymongo_payment_id', $payment->paymentId)->exists()) {
                    // Conclusive conflict (another payment record already has this id): review, evidence and hold kept
                    return [$this->paymentConflict($intent, 'payment_id_used_by_payment_record', $payment, $source), null];
                }

                // Capacity, leaving this intent's own hold out of the count
                if ($this->slots->getAvailableSlots($date, $intent->id) < $intent->pax_count) {
                    $intent->fill([
                        'status' => CheckoutIntent::STATUS_PAID_UNALLOCATED,
                        'unallocated_reason' => 'capacity_unavailable',
                        'hold_expires_at' => now(),
                    ]);
                    $this->slots->assertLockStillHeld($lock);
                    $intent->save();
                    AuditLogger::log('CHECKOUT_PAID_UNALLOCATED', "Checkout {$intent->reference} was paid but no capacity was left for {$date}; no booking created. Needs staff resolution.", null, 'Checkout finalizer');

                    return [FinalizationResult::paidUnallocated('capacity_unavailable'), null];
                }

                ['batch' => $batch, 'created' => $batchCreated] = $this->batches->findOrCreateBatchRecord($intent->start_date, $intent->end_date);
                if ($batchCreated) {
                    BatchStatusLog::create([
                        'batch_id' => $batch->id,
                        'old_status' => null,
                        'new_status' => 'confirmed',
                        'changed_by' => null,
                        'note' => "Auto-created for dive date {$intent->start_date->format('M d, Y')} when checkout {$intent->reference} was finalized.",
                    ]);
                }

                $booking = $this->createOfficialBooking($intent, $payment, $batch);
                $payload = $intent->payload;

                foreach ($payload['participants'] ?? [] as $participant) {
                    $booking->participants()->create(array_intersect_key($participant, array_flip(self::PARTICIPANT_FIELDS)));
                }

                foreach ($payload['pricing']['adjustments'] ?? [] as $adjustment) {
                    $booking->priceAdjustments()->create([
                        'pricing_rule_id' => $adjustment['rule_id'],
                        'rule_name' => $adjustment['rule_name'],
                        'rule_type' => $adjustment['rule_type'],
                        'condition_summary' => $adjustment['condition_summary'],
                        'base_price' => $adjustment['base_price'],
                        'adjustment_amount' => $adjustment['delta_per_pax'],
                        'adjusted_price' => $adjustment['adjusted_price'],
                    ]);
                }

                // PayMongo amounts are centavos; booking and payment amounts are pesos
                $paidPesos = round($payment->amountCentavos / 100, 2);
                $feePesos = $payment->feeCentavos !== null ? round($payment->feeCentavos / 100, 2) : 0.0;
                Payment::create([
                    'booking_id' => $booking->id,
                    'payment_method' => $payment->paymentMethod ?? 'paymongo',
                    'transaction_id' => 'PAY-' . strtoupper(Str::random(10)),
                    'paymongo_payment_id' => $payment->paymentId,
                    'paymongo_resource_id' => $payment->checkoutSessionId,
                    'amount' => $paidPesos,
                    'fee_amount' => $feePesos,
                    'net_amount' => round($paidPesos - $feePesos, 2),
                    'payment_type' => 'downpayment',
                    'status' => 'paid',
                    'paid_at' => $payment->paidAt ?? $payment->verifiedAt,
                ]);

                BookingStatusLog::create([
                    'booking_id' => $booking->id,
                    'old_status' => 'checkout',
                    'new_status' => 'confirmed',
                    'changed_by' => null,
                    'note' => "Created from checkout {$intent->reference} after a verified PayMongo payment of ₱" . number_format($paidPesos, 2) . '.',
                    'created_at' => now(),
                ]);

                $intent->fill([
                    'status' => CheckoutIntent::STATUS_FINALIZED,
                    'booking_id' => $booking->id,
                    'batch_id' => $batch->id,
                    'finalized_at' => now(),
                    'hold_expires_at' => now(), // the booking itself now takes the capacity
                ]);
                $this->slots->assertLockStillHeld($lock);
                $intent->save();

                AuditLogger::log('CHECKOUT_FINALIZED', "Checkout {$intent->reference} finalized as booking {$booking->booking_number} after a verified PayMongo payment.", null, 'Checkout finalizer');

                return [FinalizationResult::finalized($booking), $batchCreated ? $batch : null];
            });
        } catch (Throwable $e) {
            if (self::isCapacityLockFailure($e)) {
                return [FinalizationResult::retryable($e instanceof QueryException ? 'capacity_lock_unavailable' : 'capacity_lock_lost'), null];
            }
            Log::error('Checkout booking creation rolled back.', ['checkout_intent_id' => $intentId, 'exception' => class_basename($e)]);

            return [FinalizationResult::retryable('booking_creation_failed'), null];
        }
    }

    /** The official booking, with a new booking number and PIN (generated only here). */
    private function createOfficialBooking(CheckoutIntent $intent, VerifiedPayment $payment, Batch $batch): Booking
    {
        $payload = $intent->payload;
        $details = $payload['booking'];
        $contact = $payload['contact'];
        $fees = $payload['pricing']['fees'] ?? [];
        $paidPesos = round($payment->amountCentavos / 100, 2);

        $attributes = [
            'batch_id' => $batch->id,
            'class_type' => $details['class_type'],
            'is_certified_diver' => (bool) ($details['is_certified_diver'] ?? false),
            'start_date' => $details['start_date'],
            'end_date' => $details['end_date'],
            'pickup_option' => $details['pickup_option'],
            'pickup_location' => $details['pickup_location'] ?? null,
            'carpool_fee' => $fees['carpool'] ?? 0,
            'boat_dive' => (bool) ($details['boat_dive'] ?? false),
            'boat_dive_fee' => $fees['boat_dive'] ?? 0,
            'lgu_fee' => $fees['lgu'] ?? 0,
            'environmental_fee' => $fees['environmental'] ?? 0,
            'subtotal' => $payload['pricing']['subtotal'],
            'total_amount' => (float) $intent->total_amount,
            'downpayment_amount' => (float) $intent->downpayment_amount,
            'balance_amount' => max(0, round((float) $intent->total_amount - $paidPesos, 2)),
            'contact_name' => $contact['name'],
            'contact_email' => $contact['email'],
            'contact_phone' => $contact['phone'],
            'contact_facebook' => $contact['facebook'] ?? null,
            'status' => 'confirmed',
        ] + array_intersect_key($payload['guardian'] ?? [], array_flip(self::GUARDIAN_FIELDS));

        // A booking-number collision is retried inside a savepoint, so the outer transaction stays usable (PostgreSQL)
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($attributes, $intent) {
                    $booking = new Booking($attributes + [
                        'booking_number' => 'CFP-' . now()->format('Y') . '-' . strtoupper(Str::random(5)),
                        'pin' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                    ]);
                    $booking->forceFill(['checkout_intent_id' => $intent->id]);
                    $booking->save();

                    return $booking;
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::BOOKING_NUMBER_ATTEMPTS || ! str_contains($e->getMessage(), 'booking_number')) {
                    throw $e;
                }
            }
        }
    }

    /**
     * A review result without a matching verified payment (inconclusive verification, amount/currency mismatch,
     * identity conflict found at write time). Own transaction, intent row locked. Never changes a hold.
     *  - pending / expired / failed: move to payment_review, original hold kept (counts until it runs out).
     *  - paid: stays paid with its evidence and grace hold; retryable (Phase B can still finish).
     *  - finalized / paid_unallocated / payment_review: unchanged.
     * The event is recorded in every case.
     */
    private function markForReview(int $intentId, string $reason, array $details, string $source): FinalizationResult
    {
        return DB::transaction(function () use ($intentId, $reason, $details, $source) {
            $intent = CheckoutIntent::whereKey($intentId)->lockForUpdate()->firstOrFail();
            $event = $this->reviewEvent($intent, $reason, $source, $details);

            if (in_array($intent->status, [CheckoutIntent::STATUS_PENDING, CheckoutIntent::STATUS_EXPIRED, CheckoutIntent::STATUS_FAILED], true)) {
                return $this->moveToReview($intent, $event);
            }

            $this->recordReviewEvent($intent, $event);

            return $intent->status === CheckoutIntent::STATUS_PAID
                ? FinalizationResult::retryable($reason)
                : FinalizationResult::paymentReview($reason);
        });
    }

    /**
     * A verified payment that conflicts with this intent's state or payment identity. Inside a transaction, intent row
     * locked. A finalized, paid_unallocated or paid intent keeps its status and legitimate payment id (unless the
     * conflict is the paid intent's own payment id already being recorded elsewhere, which needs staff review).
     */
    private function paymentConflict(CheckoutIntent $intent, string $reason, VerifiedPayment $payment, string $source): FinalizationResult
    {
        $event = $this->reviewEvent($intent, $reason, $source, [
            'payment_id' => $payment->paymentId,
            'checkout_session_id' => $payment->checkoutSessionId,
            'actual_centavos' => $payment->amountCentavos,
            'actual_currency' => $payment->currency,
        ], $payment->verifiedAt->toIso8601String());

        $movesToReview = in_array($intent->status, [CheckoutIntent::STATUS_PENDING, CheckoutIntent::STATUS_EXPIRED, CheckoutIntent::STATUS_FAILED], true)
            || ($intent->status === CheckoutIntent::STATUS_PAID && $reason === 'payment_id_used_by_payment_record');

        if ($movesToReview) {
            return $this->moveToReview($intent, $event);
        }

        $this->recordReviewEvent($intent, $event);

        return FinalizationResult::paymentReview($reason);
    }

    /** Status payment_review; hold_expires_at untouched (never released early, never extended). */
    private function moveToReview(CheckoutIntent $intent, array $event): FinalizationResult
    {
        $intent->fill(['status' => CheckoutIntent::STATUS_PAYMENT_REVIEW, 'failure_reason' => $event['reason']]);
        $this->recordReviewEvent($intent, $event);

        return FinalizationResult::paymentReview($event['reason']);
    }

    /**
     * Append the event to verification.review_history (existing evidence is never replaced) and audit-log it.
     * A repeat of the same reason and payment id only bumps its counter and last_seen_at (no new audit row).
     * Saves the intent (with any pending changes).
     */
    private function recordReviewEvent(CheckoutIntent $intent, array $event): void
    {
        $verification = $intent->verification ?? [];
        $history = $verification['review_history'] ?? [];
        $repeat = null;
        foreach ($history as $i => $previous) {
            if ($previous['reason'] === $event['reason'] && ($previous['payment_id'] ?? null) === $event['payment_id']) {
                $repeat = $i;
                break;
            }
        }

        if ($repeat !== null) {
            $history[$repeat]['occurrences'] = ($history[$repeat]['occurrences'] ?? 1) + 1;
            $history[$repeat]['last_seen_at'] = $event['recorded_at'];
        } elseif (count($history) < self::REVIEW_HISTORY_LIMIT) {
            $history[] = $event + ['occurrences' => 1, 'last_seen_at' => $event['recorded_at']];
        } else {
            $verification['review_history_dropped'] = ($verification['review_history_dropped'] ?? 0) + 1;
        }

        $verification['review_history'] = $history;
        $intent->verification = $verification;
        $intent->save();

        if ($repeat === null) {
            AuditLogger::log('CHECKOUT_PAYMENT_REVIEW', sprintf(
                'Checkout %s (intent #%d) needs payment review: %s. State: %s; booking finalized: %s; PayMongo payment: %s; verified at: %s. No booking created or changed.',
                $intent->reference, $intent->id, $event['reason'], $event['intent_status'], $event['booking_finalized'] ? 'yes' : 'no',
                $event['payment_id'] ?? 'n/a', $event['verified_at'] ?? 'n/a'
            ), null, 'Checkout finalizer');
        }
    }

    /** Sanitized review event: ids, reasons, amounts and timestamps only (no keys, headers, provider bodies or personal data). */
    private function reviewEvent(CheckoutIntent $intent, string $reason, string $source, array $details, ?string $verifiedAt = null): array
    {
        $safe = [];
        foreach (array_intersect_key($details, array_flip(self::REVIEW_DETAIL_KEYS)) as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? Str::limit($value, 120, '') : $value;
            }
        }

        return [
            'reason' => $reason,
            'payment_id' => isset($safe['payment_id']) ? (string) $safe['payment_id'] : null,
            'intent_status' => $intent->status,
            'booking_finalized' => $intent->booking_id !== null,
            'source' => Str::limit($source, 40, ''),
            'verified_at' => $verifiedAt,
            'recorded_at' => now()->toIso8601String(),
            'details' => $safe,
        ];
    }

    /** New batch codes are temporary until renumbered; done after commit, under the batch-allocation lock, best effort. */
    private function renumberBatchesAfterCommit(): void
    {
        try {
            Cache::lock(self::BATCH_ALLOCATION_LOCK, 30)->block(5, fn () => $this->batches->resequenceBatches());
        } catch (Throwable $e) {
            Log::warning('Batch renumbering after checkout finalization did not complete; it will be corrected by the next renumbering.', ['exception' => class_basename($e)]);
        }
    }
}
