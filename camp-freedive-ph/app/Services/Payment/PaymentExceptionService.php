<?php

namespace App\Services\Payment;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Payment exceptions (Batch 3-II B1): opening one per receipt, recording events, and answering which receipts are
 * held. Nothing here refunds, allocates, forfeits, closes or resolves anything; B1 has no resolution workflow.
 *
 * A receipt is held while it belongs to an unresolved exception, or carries an unresolved review flag (a flag from
 * before exceptions existed is treated the same way: fail closed). Held receipts are not spent by ordinary booking
 * refunds (RefundLedger), and a booking with a held receipt is not placed, restored, moved or camp-cancelled from
 * paid_unallocated by a staff status change (BookingTransitionService): that waits for the B2 guest-choice workflow.
 */
class PaymentExceptionService
{
    /** Open the exception for $receipt (inside the caller's locked transaction). A second call returns the first. */
    public function open(string $kind, Payment $receipt, string $source, array $evidence = []): PaymentException
    {
        if (! array_key_exists($kind, PaymentException::KINDS)) {
            throw new LogicException("Unknown payment exception kind {$kind}.");
        }
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Payment exceptions are opened inside the caller\'s locked transaction.');
        }
        $bookingId = $receipt->booking_id;
        $intentId = $receipt->checkout_intent_id;
        $identity = $receipt->providerIdentity()->first();

        try {
            $exception = DB::transaction(function () use ($kind, $receipt, $source, $evidence, $bookingId, $intentId, $identity) {
                $exception = PaymentException::create([
                    'kind' => $kind,
                    'status' => PaymentException::OPEN,
                    'primary_payment_id' => $receipt->id,
                    'checkout_intent_id' => $intentId,
                    'booking_id' => $bookingId,
                    'amount_centavos' => $receipt->amountCentavos(),
                    'currency' => $identity?->isVerified() ? $identity->currency : null,
                    'provider_payment_id' => $receipt->paymongo_payment_id,
                    'evidence' => ['source' => $source, 'payment_id' => $receipt->id, 'provider_payment_id' => $receipt->paymongo_payment_id,
                        'livemode' => $identity?->livemode, 'verification' => $identity?->verification] + $evidence,
                    'opened_at' => now(),
                ]);
                DB::table('payment_exception_receipts')->insert([
                    'payment_exception_id' => $exception->id,
                    'payment_id' => $receipt->id,
                    'owner_type' => $bookingId !== null ? 'booking' : 'checkout_intent',
                    'owner_id' => $bookingId ?? $intentId,
                    'created_at' => now(),
                ]);
                PaymentExceptionEvent::create(['payment_exception_id' => $exception->id, 'type' => PaymentExceptionEvent::OPENED, 'details' => ['kind' => $kind, 'source' => $source]]);

                return $exception;
            });
        } catch (UniqueConstraintViolationException) {
            return PaymentException::where('primary_payment_id', $receipt->id)->firstOrFail(); // a retried event: opened once
        }

        AuditLogger::log('PAYMENT_EXCEPTION_OPENED', "Payment exception #{$exception->id} opened ({$kind}) for receipt #{$receipt->id} of ₱"
            . number_format($receipt->amountCentavos() / 100, 2) . ($bookingId ? " (booking #{$bookingId})" : " (checkout intent #{$intentId}, no booking)")
            . ". The money is kept and held; nothing was refunded, allocated or closed. Needs staff follow-up.", null, 'Payment exceptions');

        return $exception;
    }

    public function recordEvent(PaymentException $exception, string $type, ?User $actor, array $details = []): void
    {
        PaymentExceptionEvent::create(['payment_exception_id' => $exception->id, 'type' => $type, 'actor_id' => $actor?->id, 'details' => $details]);
    }

    /**
     * A camp or weather batch cancellation reached a booking with held money. $skipped: a paid booking without a place
     * was left as it is (its money waits for the guest's choice); otherwise the booking was cancelled with the batch
     * and its exceptions stay open. Recorded on every open exception of the booking and audited, for staff follow-up.
     */
    public function noteCampCancellation(Booking $booking, Batch $batch, ?User $actor, bool $skipped, ?string $issue = null): void
    {
        $exceptions = $this->unresolvedForBooking($booking);
        foreach ($exceptions as $exception) {
            $this->recordEvent($exception, $skipped ? PaymentExceptionEvent::BATCH_CANCELLED : PaymentExceptionEvent::BOOKING_CANCELLED, $actor,
                ['batch_id' => $batch->id, 'batch_code' => $batch->batch_code, 'booking_status' => $booking->status, 'left_unchanged' => $skipped]);
        }
        if ($skipped || $exceptions->isNotEmpty()) {
            AuditLogger::log('PAYMENT_EXCEPTION_CAMP_CANCELLATION', $skipped
                ? "Batch {$batch->batch_code} was cancelled by the camp, but booking {$booking->booking_number} (paid, no place) has held money ({$issue}). It was left unchanged and no refund case was opened; staff follow up in Payment Exceptions."
                : "Booking {$booking->booking_number} was cancelled with batch {$batch->batch_code}; its payment exception(s) stay open and their receipts are not spent by the camp refund case.", $actor, $actor?->name);
        }
    }

    /**
     * A batch rescheduled by the camp reached a paid booking without a place whose money is held: it is left unchanged
     * (rescheduled would give it a place without a capacity check or the guest's choice). Recorded and audited.
     */
    public function noteBatchRescheduleSkipped(Booking $booking, Batch $batch, ?User $actor, string $issue): void
    {
        foreach ($this->unresolvedForBooking($booking) as $exception) {
            $this->recordEvent($exception, PaymentExceptionEvent::BATCH_RESCHEDULED, $actor,
                ['batch_id' => $batch->id, 'batch_code' => $batch->batch_code, 'booking_status' => $booking->status, 'left_unchanged' => true]);
        }
        AuditLogger::log('PAYMENT_EXCEPTION_BATCH_RESCHEDULE', "Batch {$batch->batch_code} was rescheduled by the camp, but booking {$booking->booking_number} (paid, no place) has held money ({$issue}). It was left unchanged; staff follow up in Payment Exceptions.", $actor, $actor?->name);
    }

    /**
     * Refuses a change that would give the booking a place, restore it or move it while a payment is held (see the
     * class notes). Call with the booking row locked, so a payment recorded concurrently is either seen or comes after.
     */
    public function assertNotHeld(Booking $booking, string $action): void
    {
        $issue = $this->blockingIssue($booking);
        if ($issue !== null) {
            throw new RuntimeException("This booking cannot be {$action} yet: {$issue}. Money that could not simply be applied waits for the guest's choice and staff verification (Payment Exceptions); nothing was changed.");
        }
    }

    /** Unresolved exceptions of this booking: opened for it, or holding one of its receipts. */
    public function unresolvedForBooking(Booking $booking): Collection
    {
        return PaymentException::unresolved()
            ->where(fn ($q) => $q->where('booking_id', $booking->id)->orWhereExists(fn ($s) => $s->from('payment_exception_receipts as r')
                ->join('payments as p', 'p.id', '=', 'r.payment_id')
                ->whereColumn('r.payment_exception_id', 'payment_exceptions.id')
                ->where('p.booking_id', $booking->id)))
            ->orderBy('id')->get();
    }

    /** What blocks staff placement, restore or date changes for this booking, or null. Read under the booking lock. */
    public function blockingIssue(Booking $booking): ?string
    {
        $exception = $this->unresolvedForBooking($booking)->first();
        if ($exception) {
            return "payment exception #{$exception->id} ({$exception->label()}) is still open";
        }
        $flagged = Payment::where('booking_id', $booking->id)->awaitingReview()->orderBy('id')->first();

        return $flagged ? "payment #{$flagged->id} is still awaiting review (" . str_replace('_', ' ', $flagged->review_reason) . ')' : null;
    }

    /** @return array<int, int> payment id => id of the unresolved exception holding it */
    public static function heldByException(array $paymentIds): array
    {
        if ($paymentIds === []) {
            return [];
        }

        return DB::table('payment_exception_receipts as r')
            ->join('payment_exceptions as e', 'e.id', '=', 'r.payment_exception_id')
            ->whereIn('e.status', PaymentException::UNRESOLVED_STATUSES)
            ->whereIn('r.payment_id', $paymentIds)
            ->pluck('r.payment_exception_id', 'r.payment_id')
            ->mapWithKeys(fn ($exceptionId, $paymentId) => [(int) $paymentId => (int) $exceptionId])
            ->all();
    }

    /**
     * Receipts of $payments that are held, with why: "exception #N", or "review" for an unresolved review flag.
     *
     * @param Collection<int, Payment> $payments
     * @return array<int, string>
     */
    public static function held(Collection $payments): array
    {
        $held = array_map(fn (int $id) => "exception #{$id}", self::heldByException($payments->pluck('id')->all()));
        foreach ($payments as $payment) {
            if ($payment->review_reason !== null && $payment->review_resolved_at === null && ! isset($held[$payment->id])) {
                $held[$payment->id] = 'review';
            }
        }

        return $held;
    }
}
