<?php

namespace App\Services\Payment;

use App\Models\Batch;
use App\Models\BatchStatusLog;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\BookingStatusLog;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionAllocationAttempt;
use App\Models\PaymentExceptionChoice;
use App\Models\PaymentExceptionEvent;
use App\Models\PaymentExceptionRefundReview;
use App\Models\ProviderPaymentIdentity;
use App\Models\RefundAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\BatchManagementService;
use App\Services\Booking\BookingTransitionService;
use App\Services\Booking\CapacityCoordinator;
use App\Services\Checkout\IntentBookingBuilder;
use App\Services\Payment\Allocation\InstructorCapacityVerifier;
use Carbon\Carbon;
use LogicException;
use RuntimeException;

/**
 * Acting on an independently verified guest choice (Batch 3-II B2.3 and B2.4). Staff-triggered only; nothing here
 * sends messages, refunds, captures or moves money.
 *
 * Refund review (B2.3, P5): the verified refund choice becomes an owner-review item linked to the exception, its
 * receipt and provider identity, the checkout intent or booking, and any booking refund case already open (never a
 * second one, and no booking refund API is called: those apply booking policy and amounts, which B3 and the owner
 * decide). The exception stays open and its money held. The choice becomes acted_on in the same transaction.
 *
 * Alternative date (B2.4): in one transaction, under the capacity locks of the dates involved and the established row
 * locks (dates -> staff -> booking or intent -> exception -> choice -> receipt), re-check the verified consent and its
 * exact dates, the original price and payment terms (P1: never repriced), the receipt, room on the date, and confirmed
 * instructor capacity (P3); then create (checkout intent) or move (legacy booking) the booking, mark the choice
 * acted_on, resolve the exception (resolution "allocated"), apply the receipt and record the attempt. Any failure
 * leaves none of it. A date that cannot be given spends the consent (P6: new choice and verification needed); a
 * price, receipt or instructor problem blocks it for staff review and keeps the choice.
 *
 * Instructor capacity has no authoritative source yet (UnconfirmedInstructorCapacity), so allocation is blocked for
 * staff scheduling review until one is approved. The rest of the allocation is implemented and tested behind that gate.
 */
class ExceptionResolutionService
{
    /** A camp batch in one of these states means the camp is not running that date. */
    private const NOT_OPERATING = ['cancelled_by_camp', 'cancelled', 'rescheduled', 'completed'];

    public function __construct(
        protected GuestChoiceService $choices,
        protected CapacityCoordinator $capacity,
        protected BookingTransitionService $transitions,
        protected BatchManagementService $batches,
        protected IntentBookingBuilder $builder,
        protected ReceiptRecorder $receipts,
        protected InstructorCapacityVerifier $instructors,
    ) {
    }

    /** B2.3: route a verified refund choice to owner review. No amount, refund case or payout. */
    public function routeToRefundReview(PaymentExceptionChoice $choice, User $actor, int $expectedVersion): PaymentExceptionRefundReview
    {
        return $this->choices->withLocks($choice->paymentException()->firstOrFail(), $actor, function (PaymentException $exception, User $staff) use ($choice, $expectedVersion) {
            $c = $this->choices->lockChoice($choice, $exception, [PaymentExceptionChoice::VERIFIED], $expectedVersion);
            if ($c->choice !== PaymentExceptionChoice::REFUND_REVIEW) {
                throw new RuntimeException('Only a verified refund-review choice can be routed to owner review; nothing was changed.');
            }
            if (PaymentExceptionRefundReview::where('payment_exception_id', $exception->id)->exists()) {
                throw new RuntimeException('This payment exception is already in owner refund review; nothing was changed.');
            }
            $receipt = Payment::whereKey($exception->primary_payment_id)->lockForUpdate()->firstOrFail();
            $openCase = $exception->booking_id !== null
                ? BookingRefund::where('booking_id', $exception->booking_id)->whereIn('status', BookingRefund::OPEN_STATUSES)->first() : null;

            $review = PaymentExceptionRefundReview::create([
                'payment_exception_id' => $exception->id,
                'choice_id' => $c->id,
                'primary_payment_id' => $receipt->id,
                'provider_payment_identity_id' => ProviderPaymentIdentity::where('payment_id', $receipt->id)->value('id'),
                'checkout_intent_id' => $exception->checkout_intent_id,
                'booking_id' => $exception->booking_id,
                'existing_booking_refund_id' => $openCase?->id,
                'received_centavos' => $exception->amount_centavos,
                'status' => PaymentExceptionRefundReview::AWAITING_OWNER_REVIEW,
                'routed_by' => $staff->id,
                'routed_at' => now(),
            ]);
            $this->choices->actOn($c, $exception, $staff, "refund_review:{$review->id}");
            PaymentExceptionEvent::create(['payment_exception_id' => $exception->id, 'type' => PaymentExceptionEvent::REFUND_REVIEW_ROUTED, 'actor_id' => $staff->id,
                'details' => ['review_id' => $review->id, 'choice_id' => $c->id, 'existing_booking_refund_id' => $openCase?->id]]);
            AuditLogger::log('PAYMENT_EXCEPTION_REFUND_REVIEW', "Payment exception #{$exception->id}: verified refund choice #{$c->id} routed to owner review #{$review->id}. "
                . 'The money stays held; no amount was set and nothing was refunded.', $staff, $staff->name);

            return $review;
        });
    }

    /**
     * B2.4: allocate the guest's verified alternative date. Returns the attempt record: allocated, failed (date cannot
     * be given, consent spent) or blocked (staff review). Throws (nothing recorded) for a busy date, a stale or
     * invalid choice, or missing permission.
     */
    public function allocateAlternativeDate(PaymentExceptionChoice $choice, User $actor, int $expectedVersion): PaymentExceptionAllocationAttempt
    {
        $seen = PaymentExceptionChoice::with('paymentException')->findOrFail($choice->id);
        if ($seen->choice !== PaymentExceptionChoice::ALTERNATIVE_DATE || $seen->status !== PaymentExceptionChoice::VERIFIED || $seen->lock_version !== $expectedVersion) {
            throw new RuntimeException("Guest choice #{$seen->id} is not a current verified alternative-date choice (now {$seen->status}). Reload and review it; nothing was changed.");
        }
        $exception = $seen->paymentException;
        $start = $seen->requested_start_date->format('Y-m-d');
        $end = $seen->requested_end_date->format('Y-m-d');

        try {
            $exception->kind === PaymentException::KIND_LEGACY_UNALLOCATED
                ? $this->allocateLegacy($exception, $seen, $actor, $expectedVersion, $start, $end)
                : $this->allocateIntent($exception, $seen, $actor, $expectedVersion, $start, $end);
        } catch (AllocationRefused $refused) {
            return $this->recordRefusal($exception, $seen, $actor, $expectedVersion, $start, $end, $refused);
        }

        return PaymentExceptionAllocationAttempt::where('choice_id', $seen->id)->where('outcome', PaymentExceptionAllocationAttempt::ALLOCATED)->firstOrFail();
    }

    private function allocateLegacy(PaymentException $exception, PaymentExceptionChoice $choice, User $actor, int $version, string $start, string $end): void
    {
        $booking = Booking::findOrFail($exception->booking_id);
        $this->transitions->allocateHeldBooking($booking, $start, $end, $actor,
            fn (Booking $locked, int $pax) => $this->assertFits($start, $pax),
            function (Booking $locked) use ($exception, $choice, $actor, $version, $start, $end) {
                $this->choices->withLocks($exception, $actor, function (PaymentException $ex, User $staff) use ($locked, $choice, $version, $start, $end) {
                    $pax = $locked->participants()->count();
                    [$c, $receipt] = $this->authorize($ex, $staff, $choice, $version, $start, $end, $pax, $locked);
                    // The receipt's B1 review flag is resolved by this authorized placement
                    Payment::whereKey($receipt->id)->update(['review_resolved_at' => now(), 'review_resolved_by' => $staff->id]);
                    $this->complete($ex, $c, $staff, $locked, $start, $end, $pax);
                });
            });
    }

    private function allocateIntent(PaymentException $exception, PaymentExceptionChoice $choice, User $actor, int $version, string $start, string $end): void
    {
        $created = null;
        $this->capacity->transaction([$start], function () use ($exception, $choice, $actor, $version, $start, $end, &$created) {
            $this->choices->withLocks($exception, $actor, function (PaymentException $ex, User $staff) use ($choice, $version, $start, $end, &$created) {
                $intent = CheckoutIntent::findOrFail($ex->checkout_intent_id); // locked by withLocks
                $pax = (int) $intent->pax_count;
                $this->assertFits($start, $pax);
                [$c, $receipt] = $this->authorize($ex, $staff, $choice, $version, $start, $end, $pax, null, $intent);

                ['batch' => $batch, 'created' => $isNew] = $this->batches->findOrCreateBatchRecord($start, $end, $staff);
                if ($isNew) {
                    $created = $batch;
                    BatchStatusLog::create(['batch_id' => $batch->id, 'old_status' => null, 'new_status' => 'confirmed', 'changed_by' => $staff->id,
                        'note' => "Auto-created for {$start} when checkout {$intent->reference} was placed on the guest's verified alternative date."]);
                }
                $booking = $this->builder->build($intent, $receipt->amountCentavos(), $batch, $start, $end);
                BookingStatusLog::create(['booking_id' => $booking->id, 'old_status' => 'checkout', 'new_status' => 'confirmed', 'changed_by' => $staff->id,
                    'note' => "Created from checkout {$intent->reference} on the guest's independently verified alternative date; original price and payment terms kept.",
                    'created_at' => now()]);
                $intent->fill(['status' => CheckoutIntent::STATUS_FINALIZED, 'booking_id' => $booking->id, 'batch_id' => $batch->id,
                    'finalized_at' => now(), 'hold_expires_at' => now()])->save();

                $this->complete($ex, $c, $staff, $booking, $start, $end, $pax);
                $attached = $this->receipts->attach($receipt, $booking, 'b2_allocation');
                if ($attached->status !== ReceiptOutcome::ATTACHED) {
                    throw new LogicException("The receipt could not be applied to the new booking ({$attached->describe()}); nothing was allocated.");
                }
            });
        });
        if ($created) {
            $this->batches->completeCreatedBatch($created, $actor);
        }
    }

    /**
     * Re-check, under the locks, everything the allocation relies on. Returns the locked choice and receipt.
     *
     * @return array{0: PaymentExceptionChoice, 1: Payment}
     */
    private function authorize(PaymentException $exception, User $staff, PaymentExceptionChoice $choice, int $version, string $start, string $end, int $pax,
        ?Booking $booking, ?CheckoutIntent $intent = null): array
    {
        $c = $this->choices->lockChoice($choice, $exception, [PaymentExceptionChoice::VERIFIED], $version);
        if ($c->choice !== PaymentExceptionChoice::ALTERNATIVE_DATE || $c->requested_start_date->format('Y-m-d') !== $start || $c->requested_end_date->format('Y-m-d') !== $end) {
            throw new RuntimeException('The verified choice no longer matches these dates; nothing was changed.');
        }
        if ($start <= Carbon::today('Asia/Manila')->toDateString()) {
            throw AllocationRefused::failed('date_passed', 'The verified date has passed: a new date needs the guest\'s new choice and an independent verification.');
        }
        $notOperating = Batch::whereDate('start_date', $start)->whereIn('status', self::NOT_OPERATING)->exists();
        if ($notOperating && ! Batch::whereDate('start_date', $start)->whereIn('status', ['open', 'confirmed'])->exists()) {
            throw AllocationRefused::blocked('date_not_operating', 'The camp\'s session on the verified date is cancelled or moved, so nothing was allocated. '
                . 'The verified choice stays on record for staff review.');
        }

        $receipt = Payment::whereKey($exception->primary_payment_id)->lockForUpdate()->firstOrFail();
        $this->assertPriceAndReceipt($exception, $c, $receipt, $booking, $intent, $pax);

        $instructors = $this->instructors->verify($start, $end, $pax);
        if (! $instructors->verified) {
            throw AllocationRefused::blocked($instructors->reason, 'Confirmed instructor capacity for the verified date cannot be proven, so nothing was allocated. '
                . 'The guest\'s verified choice stays on record for staff scheduling review.', $instructors->details);
        }

        return [$c, $receipt];
    }

    /** P1: the original quote, terms and the held receipt must be intact and consistent; otherwise staff review. */
    private function assertPriceAndReceipt(PaymentException $exception, PaymentExceptionChoice $c, Payment $receipt, ?Booking $booking, ?CheckoutIntent $intent, int $pax): void
    {
        $cents = fn ($pesos) => (int) round((float) $pesos * 100);
        $source = $booking ?? $intent;
        $blocked = fn (string $reason, string $what) => throw AllocationRefused::blocked($reason, "{$what}, so nothing was allocated. The verified choice stays on record for staff review.");

        if ($source === null || $c->original_total_centavos === null || $c->original_downpayment_centavos === null
            || $c->price_snapshot_source !== ($booking ? 'booking' : 'checkout_intent') || (int) $c->price_snapshot_source_id !== (int) $source->id) {
            $blocked('price_unverified', 'The original price snapshot is missing or belongs to another record');
        }
        if ($cents($source->total_amount) !== $c->original_total_centavos || $cents($source->downpayment_amount) !== $c->original_downpayment_centavos) {
            $blocked('price_changed', 'The booking\'s price or downpayment changed since the guest chose');
        }
        $received = $receipt->amountCentavos();
        if ($received !== $c->received_centavos || $received !== $exception->amount_centavos || $received !== $c->original_downpayment_centavos) {
            $blocked('payment_terms_unverified', 'The amount received does not match the original downpayment and the recorded snapshot');
        }
        if ($booking !== null && $cents($booking->balance_amount) !== $c->original_total_centavos - $received) {
            $blocked('balance_unverified', 'The booking\'s remaining balance does not match its price minus the amount received');
        }
        if ($intent !== null && ((int) round(((float) ($intent->payload['pricing']['total_amount'] ?? -1)) * 100) !== $c->original_total_centavos
            || count($intent->payload['participants'] ?? []) !== $pax)) {
            $blocked('price_unverified', 'The checkout\'s pricing snapshot or participant list is incomplete');
        }
        $owned = in_array($receipt->status, Payment::RECEIPT_STATUSES, true) && ($booking
            ? (int) $receipt->booking_id === (int) $booking->id
            : $receipt->booking_id === null && (int) $receipt->checkout_intent_id === (int) $intent->id);
        $identity = ProviderPaymentIdentity::where('payment_id', $receipt->id)->first();
        if (! $owned || $identity === null || ! $identity->isVerified() || RefundAttempt::where('payment_id', $receipt->id)->where('status', '!=', RefundAttempt::FAILED)->exists()) {
            $blocked('receipt_unverified', 'The held receipt is not a verified, unrefunded receipt of this booking or checkout');
        }
        if ($booking !== null) {
            $others = app(PaymentExceptionService::class)->unresolvedForBooking($booking)->where('id', '!=', $exception->id)->isNotEmpty()
                || Payment::where('booking_id', $booking->id)->whereKeyNot($receipt->id)->awaitingReview()->exists();
            if ($others) {
                $blocked('other_held_payment', 'Another payment of this booking is also held');
            }
        }
    }

    /** Room on the date (the booking's own place is not counted: it holds none). */
    private function assertFits(string $start, int $pax): void
    {
        $left = $this->capacity->remaining($start);
        if ($left < $pax) {
            throw AllocationRefused::failed('capacity_full', 'The verified date no longer has room. A new date needs the guest\'s new choice and an independent verification.',
                ['pax' => $pax, 'left' => $left]);
        }
    }

    /** Choice acted_on, exception resolved, attempt and events: all in the allocation's transaction. */
    private function complete(PaymentException $exception, PaymentExceptionChoice $c, User $staff, Booking $booking, string $start, string $end, int $pax): void
    {
        // Order matters to the database guards: acted-on choice, then its allocated attempt, then the resolution
        $this->choices->actOn($c, $exception, $staff, "allocation:{$booking->id}");
        PaymentExceptionAllocationAttempt::create(['payment_exception_id' => $exception->id, 'choice_id' => $c->id, 'outcome' => PaymentExceptionAllocationAttempt::ALLOCATED,
            'booking_id' => $booking->id, 'requested_start_date' => $start, 'requested_end_date' => $end, 'details' => ['pax' => $pax],
            'attempted_by' => $staff->id, 'attempted_at' => now()]);
        $exception->forceFill(['status' => PaymentException::RESOLVED, 'resolution' => PaymentException::RESOLUTION_ALLOCATED, 'resolved_at' => now(),
            'resolved_by' => $staff->id, 'lock_version' => $exception->lock_version + 1])->save();
        PaymentExceptionEvent::create(['payment_exception_id' => $exception->id, 'type' => PaymentExceptionEvent::ALLOCATED, 'actor_id' => $staff->id,
            'details' => ['choice_id' => $c->id, 'booking_id' => $booking->id, 'start_date' => $start]]);
        AuditLogger::log('PAYMENT_EXCEPTION_ALLOCATED', "Payment exception #{$exception->id}: booking #{$booking->id} placed on {$start} from verified choice #{$c->id}; "
            . 'original price and terms kept, exception resolved. No money moved and no message was sent.', $staff, $staff->name);
    }

    /** The refusal is recorded (and a failed date spends the consent), in its own locked transaction. */
    private function recordRefusal(PaymentException $exception, PaymentExceptionChoice $choice, User $actor, int $version, string $start, string $end, AllocationRefused $refused): PaymentExceptionAllocationAttempt
    {
        return $this->choices->withLocks($exception, $actor, function (PaymentException $ex, User $staff) use ($choice, $version, $start, $end, $refused) {
            $c = $this->choices->lockChoice($choice, $ex, [PaymentExceptionChoice::VERIFIED], $version);
            $attempt = PaymentExceptionAllocationAttempt::create(['payment_exception_id' => $ex->id, 'choice_id' => $c->id, 'outcome' => $refused->outcome,
                'reason' => $refused->reason, 'requested_start_date' => $start, 'requested_end_date' => $end, 'details' => $refused->details,
                'attempted_by' => $staff->id, 'attempted_at' => now()]);
            if ($refused->outcome === PaymentExceptionAllocationAttempt::FAILED) {
                $this->choices->failAction($c, $ex, $staff, "The verified date could not be allocated ({$refused->reason}). A new date needs a new choice and an independent verification.");
            }
            PaymentExceptionEvent::create(['payment_exception_id' => $ex->id, 'actor_id' => $staff->id,
                'type' => $refused->outcome === PaymentExceptionAllocationAttempt::FAILED ? PaymentExceptionEvent::ALLOCATION_FAILED : PaymentExceptionEvent::ALLOCATION_BLOCKED,
                'details' => ['choice_id' => $c->id, 'attempt_id' => $attempt->id, 'reason' => $refused->reason]]);
            AuditLogger::log('PAYMENT_EXCEPTION_ALLOCATION_' . strtoupper($refused->outcome), "Payment exception #{$ex->id}: allocation of verified choice #{$c->id} {$refused->outcome} ({$refused->reason}); nothing was allocated.", $staff, $staff->name);

            return $attempt;
        });
    }
}
