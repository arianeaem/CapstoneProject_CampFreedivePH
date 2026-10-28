<?php

namespace App\Services\Booking;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\ProviderPaymentIdentity;
use App\Services\AuditLogger;
use App\Services\BatchManagementService;
use App\Services\Checkout\CheckoutNotifier;
use App\Services\Payment\PaymentExceptionService;
use App\Services\Payment\ProviderPayment;
use App\Services\Payment\ReceiptOutcome;
use App\Services\Payment\ReceiptRecorder;
use App\Services\PayMongoService;
use App\Services\SlotReservationService;
use RuntimeException;

/**
 * Applies a PayMongo payment to a legacy booking (one created by the booking form before checkout intents). The only
 * code that confirms a legacy booking from a payment; used by the signed webhook and the signed return page alike.
 *
 *  1. The payment must be verifiable: "pay_" id, positive integer amount, PHP, the same test/live mode as our key,
 *     status paid. Otherwise nothing is recorded (audited).
 *  2. Under the date's capacity lock (CapacityCoordinator) and the booking row lock, the payment is recorded once
 *     through ReceiptRecorder (Batch 3-II B1): a known payment id is applied again only as a verified replay (same
 *     amount, currency, mode and booking); anything else is a conflict for staff, never moved or overwritten. Then:
 *       - waiting for its downpayment, right amount, a place still free  -> confirmed (the only "confirmed" outcome)
 *       - waiting, right amount, the date is full (the 15-minute hold ran out) -> paid_unallocated: payment kept,
 *         booking not confirmed, flagged for staff (never overbooked)
 *       - waiting, right amount, but the camp is not running on its date, or its batch was cancelled, rescheduled or
 *         completed (G1) -> paid_unallocated the same way (date_not_operating), never confirmed onto that batch
 *       - cancelled                     -> payment kept and flagged; the booking stays cancelled
 *       - amount different / paid again -> payment kept and flagged; the booking is unchanged
 *  3. Only a real confirmation queues the guest's confirmation (durable outbox, once per booking).
 *  Every flagged outcome also opens a payment exception for the receipt, which holds it until staff resolve it.
 * No refund, booking number or PIN is ever created here.
 */
class LegacyPaymentConfirmer
{
    public const CONFIRMED = 'confirmed';
    public const PAID_UNALLOCATED = 'paid_unallocated';
    public const REVIEW = 'review';
    public const ALREADY_RECORDED = 'already_recorded';
    public const CONFLICT = 'conflict';
    public const REJECTED = 'rejected';

    /** The payment exception each review flag opens. */
    private const EXCEPTION_KINDS = [
        Payment::REVIEW_CAPACITY_UNAVAILABLE => PaymentException::KIND_LEGACY_UNALLOCATED,
        Payment::REVIEW_DATE_NOT_OPERATING => PaymentException::KIND_LEGACY_UNALLOCATED,
        Payment::REVIEW_PAID_AFTER_CANCELLATION => PaymentException::KIND_PAID_AFTER_CANCELLATION,
        Payment::REVIEW_AMOUNT_MISMATCH => PaymentException::KIND_AMOUNT_MISMATCH,
        Payment::REVIEW_PAID_AGAIN => PaymentException::KIND_PAID_AGAIN,
    ];

    public function __construct(
        protected CapacityCoordinator $capacity,
        protected PayMongoService $paymongo,
        protected CheckoutNotifier $notifier,
        protected SlotReservationService $slots,
        protected ReceiptRecorder $receipts,
        protected PaymentExceptionService $exceptions,
        protected BatchManagementService $batches,
    ) {
    }

    /** Why this PayMongo payment cannot be applied, or null when it is verifiable. */
    public function verificationProblem(?string $paymentId, array $attributes, ?bool $eventLivemode = null): ?string
    {
        $amount = $attributes['amount'] ?? null;
        $livemode = $attributes['livemode'] ?? $eventLivemode;
        $expectedMode = $this->paymongo->configuredLivemode();

        return match (true) {
            ! is_string($paymentId) || ! str_starts_with($paymentId, 'pay_') => 'payment id missing',
            ! is_int($amount) || $amount <= 0 => 'amount missing',
            ($attributes['currency'] ?? null) !== 'PHP' => 'currency is not PHP',
            ! is_bool($livemode) || $expectedMode === null || $livemode !== $expectedMode => 'test/live mode does not match',
            ($attributes['status'] ?? null) !== 'paid' => 'payment status is not paid',
            default => null,
        };
    }

    /**
     * @param string $source webhook | return
     * @return string one of the outcome constants
     * @throws CapacityException when the date is busy (nothing written; PayMongo retries the webhook, the guest reloads)
     */
    public function apply(Booking $booking, ?string $paymentId, array $attributes, ?string $sessionId, string $source,
        ?bool $eventLivemode = null, ?string $fallbackMethod = null): string
    {
        $problem = $this->verificationProblem($paymentId, $attributes, $eventLivemode);
        if ($problem !== null) {
            AuditLogger::log($source === 'webhook' ? 'PAYMONGO_WEBHOOK_PAYMENT_REJECTED' : 'PAYMONGO_RETURN_PAYMENT_REJECTED',
                "Paid event for Booking #{$booking->booking_number} not applied: {$problem}. Needs staff review.", null, "PayMongo {$source}");

            return self::REJECTED;
        }

        $verified = new ProviderPayment(ProviderPaymentIdentity::PROVIDER_PAYMONGO, $paymentId, (int) $attributes['amount'], 'PHP', $attributes['livemode'] ?? $eventLivemode);

        $lockedDate = $booking->start_date->format('Y-m-d');
        $outcome = $this->capacity->transaction([$lockedDate], function () use ($booking, $paymentId, $attributes, $sessionId, $source, $fallbackMethod, $verified, $lockedDate) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $amountCentavos = (int) $attributes['amount'];
            $amountPesos = round($amountCentavos / 100, 2);
            $feePesos = is_int($attributes['fee'] ?? null) ? round($attributes['fee'] / 100, 2) : 0.0;
            $expectedCentavos = null;

            // Recorded once: a seen payment id is only a verified replay (duplicate delivery, webhook and return page
            // racing) or a conflict for staff; it is never applied again, moved or overwritten
            $recorded = $this->receipts->record($verified, null, $locked->id, "legacy:{$source}", function () use ($locked, $paymentId, $sessionId, $attributes, $fallbackMethod, $amountPesos, $feePesos, &$expectedCentavos) {
                // The pending downpayment row for this checkout (or any pending one); never a row that is already paid
                $payment = Payment::where('booking_id', $locked->id)->where('status', 'pending')
                    ->orderByRaw('CASE WHEN paymongo_resource_id = ? THEN 0 ELSE 1 END', [(string) $sessionId])
                    ->latest('id')->lockForUpdate()->first();
                $expectedCentavos = (int) round((float) ($payment?->amount ?? $locked->downpayment_amount) * 100);

                $payment ??= new Payment(['booking_id' => $locked->id, 'payment_type' => 'downpayment', 'transaction_id' => 'TXN-' . strtoupper(bin2hex(random_bytes(4)))]);
                $payment->fill([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'payment_method' => (string) ($attributes['source']['type'] ?? $attributes['payment_method_type'] ?? $fallbackMethod ?? 'paymongo'),
                    'paymongo_payment_id' => $paymentId,
                    'paymongo_resource_id' => $payment->paymongo_resource_id ?? $sessionId,
                    'amount' => $amountPesos,
                    'fee_amount' => $feePesos,
                    'net_amount' => round($amountPesos - $feePesos, 2),
                ])->save();

                return $payment;
            });
            if ($recorded->isReplay()) {
                return self::ALREADY_RECORDED;
            }
            if ($recorded->status !== ReceiptOutcome::RECORDED) {
                AuditLogger::log('PAYMONGO_WEBHOOK_PAYMENT_CONFLICT', "Payment {$paymentId} does not match what is already recorded for it ({$recorded->describe()}); event for Booking #{$locked->booking_number} not applied. Needs staff review.", null, "PayMongo {$source}");

                return self::CONFLICT;
            }
            $payment = $recorded->payment;
            $peso = '₱' . number_format($amountPesos, 2);

            if (str_starts_with($locked->status, 'cancelled')) {
                $this->flag($payment, Payment::REVIEW_PAID_AFTER_CANCELLATION, $source, ['booking_status' => $locked->status]);
                AuditLogger::log('PAYMONGO_WEBHOOK_LATE_PAYMENT', "Payment of {$peso} received for cancelled Booking #{$locked->booking_number}; kept as received, booking not reinstated. Needs staff review.", null, "PayMongo {$source}");

                return self::REVIEW;
            }
            if ($amountCentavos !== $expectedCentavos) {
                $this->flag($payment, Payment::REVIEW_AMOUNT_MISMATCH, $source, ['expected_centavos' => $expectedCentavos, 'actual_centavos' => $amountCentavos]);
                AuditLogger::log('PAYMONGO_WEBHOOK_AMOUNT_MISMATCH', "Payment of {$peso} received for Booking #{$locked->booking_number}, expected ₱" . number_format($expectedCentavos / 100, 2) . ". Recorded; booking not confirmed automatically. Needs staff review.", null, "PayMongo {$source}");

                return self::REVIEW;
            }
            if (! in_array($locked->status, ['pending_downpayment', 'pending'], true)) {
                $this->flag($payment, Payment::REVIEW_PAID_AGAIN, $source, ['booking_status' => $locked->status]);
                AuditLogger::log('PAYMONGO_PAYMENT_AGAIN', "Another payment of {$peso} received for Booking #{$locked->booking_number} ({$locked->status}); kept as received, nothing else changed. Needs staff review.", null, "PayMongo {$source}");

                return self::REVIEW;
            }

            $date = $locked->start_date->format('Y-m-d');
            if ($date !== $lockedDate) {
                throw new RuntimeException("Booking #{$locked->booking_number} moved to another date meanwhile; nothing was applied. The payment will be applied on retry.");
            }
            $pax = $locked->participants()->count();
            // G1: the camp must be running on the date and the booking's own batch (if any) must still be that date's
            // open or confirmed batch: a batch rescheduled or cancelled after the booking was made never takes it on
            $batch = $locked->batch_id ? Batch::find($locked->batch_id) : null;
            $operating = $this->batches->operatingDate($date)->isOperating()
                && ($batch === null || (in_array($batch->status, BatchManagementService::OPERATING_STATUSES, true) && $batch->start_date?->format('Y-m-d') === $date));
            $placed = $operating && $pax <= $this->capacity->remaining($date, $locked); // its own legacy hold is not counted against it
            $newStatus = $placed ? 'confirmed' : self::PAID_UNALLOCATED;
            $locked->update(['status' => $newStatus]);
            $this->slots->releaseHold($date, $locked->booking_number);
            if (! $placed) {
                $operating
                    ? $this->flag($payment, Payment::REVIEW_CAPACITY_UNAVAILABLE, $source, ['start_date' => $date, 'pax' => $pax])
                    : $this->flag($payment, Payment::REVIEW_DATE_NOT_OPERATING, $source, ['start_date' => $date, 'pax' => $pax,
                        'batch_id' => $batch?->id, 'batch_status' => $batch?->status]);
            }
            BookingStatusLog::create([
                'booking_id' => $locked->id,
                'old_status' => 'pending_downpayment',
                'new_status' => $newStatus,
                'changed_by' => null,
                'note' => $placed
                    ? "Booking confirmed after a verified PayMongo downpayment of {$peso} ({$source})."
                    : ($operating
                        ? "Verified PayMongo downpayment of {$peso} ({$source}) arrived after the date filled up: payment kept, no place taken. Needs staff review."
                        : "Verified PayMongo downpayment of {$peso} ({$source}) arrived, but the camp is not running on {$date} (session " . str_replace('_', ' ', (string) $batch?->status) . '): payment kept, no place taken. Needs staff review.'),
                'created_at' => now(),
            ]);
            AuditLogger::log($placed ? 'PAYMONGO_WEBHOOK_PAID' : 'PAYMONGO_PAYMENT_UNALLOCATED', $placed
                ? "Booking #{$locked->booking_number} confirmed after a verified payment of {$peso} ({$source})."
                : ($operating
                    ? "Booking #{$locked->booking_number} paid {$peso} but {$date} is full ({$pax} pax): not confirmed, payment kept for staff review."
                    : "Booking #{$locked->booking_number} paid {$peso} but the camp is not running on {$date}: not confirmed, payment kept for staff review."), null, "PayMongo {$source}");

            return $placed ? self::CONFIRMED : self::PAID_UNALLOCATED;
        });

        if ($outcome === self::CONFIRMED) {
            $this->notifier->recordForBooking($booking->fresh()); // after commit; once per booking
        }

        return $outcome;
    }

    /** Flag the receipt for staff and open its payment exception, in the same locked transaction. */
    private function flag(Payment $payment, string $reason, string $source, array $evidence): void
    {
        $payment->update(['review_reason' => $reason, 'review_flagged_at' => now()]);
        $this->exceptions->open(self::EXCEPTION_KINDS[$reason], $payment, "legacy:{$source}", ['review_reason' => $reason] + $evidence);
    }
}
