<?php

namespace Tests\Feature\Payments;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionAllocationAttempt;
use App\Models\PaymentExceptionChoice;
use App\Models\PaymentExceptionEvent;
use App\Models\PaymentExceptionRefundReview;
use App\Models\RefundAttempt;
use App\Services\Payment\Allocation\InstructorCapacityResult;
use App\Services\Payment\Allocation\InstructorCapacityVerifier;
use App\Services\Payment\ExceptionResolutionService;
use App\Services\Payment\GuestChoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B2.3 and B2.4: acting on a verified guest choice. A refund choice becomes an owner-review item (no
 * amount, case or refund; the exception stays open). An alternative date is allocated atomically at the original
 * price and terms, only with room and confirmed instructor capacity; the default verifier fails closed. Synthetic only.
 */
class ExceptionResolutionTest extends TestCase
{
    use BuildsCheckoutIntents;
    use FakesPayMongo;
    use RefreshDatabase;
    use ReceiptFixtures;
    use RefundFixtures;

    private const ALT_START = '2026-12-26';
    private const ALT_END = '2026-12-27';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        $this->setUpCheckoutWorld(); // capacity 6 on every date, now = 2026-11-02
    }

    private function service(): ExceptionResolutionService
    {
        return app(ExceptionResolutionService::class);
    }

    private function choices(): GuestChoiceService
    {
        return app(GuestChoiceService::class);
    }

    /** Test double for an approved instructor schedule (none exists yet in the application). */
    private function instructorsConfirmed(): void
    {
        $this->app->instance(InstructorCapacityVerifier::class, new class implements InstructorCapacityVerifier {
            public function verify(string $startDate, string $endDate, int $additionalPax): InstructorCapacityResult
            {
                return InstructorCapacityResult::verified(['source' => 'test double']);
            }
        });
    }

    private function intentException(): PaymentException
    {
        $this->fillDate(5);
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->subMinutes(5)]);
        $this->finalizeIntent($intent);

        return PaymentException::where('checkout_intent_id', $intent->id)->sole();
    }

    private function legacyException(): PaymentException
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $this->fillDate(5);
        $this->payByWebhook($booking, 'pay_resolve_' . $booking->id)->assertOk();

        return PaymentException::where('booking_id', $booking->id)->sole();
    }

    /** A recorded and independently verified choice. */
    private function verified(PaymentException $exception, string $choice, array $overrides = []): PaymentExceptionChoice
    {
        $data = array_merge([
            'choice' => $choice, 'evidence_channel' => 'email', 'evidence_at' => now()->subHour()->toIso8601String(),
            'evidence_reference' => 'synthetic-thread-0002', 'evidence_summary' => 'Synthetic: guest replied with their choice.',
            'contact_match_method' => 'email_address', 'contact_match_outcome' => 'matched',
        ], $choice === PaymentExceptionChoice::ALTERNATIVE_DATE ? ['requested_start_date' => self::ALT_START, 'requested_end_date' => self::ALT_END] : [], $overrides);
        $recorded = $this->choices()->record($exception, $this->staffUser('admin'), $data);

        return $this->choices()->verify($recorded, $this->staffUser('owner'), 'Synthetic: confirmed with the booking contact.', 0);
    }

    private function refused(callable $action, string $contains = ''): string
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());

            return $e->getMessage();
        }
        $this->fail('expected the action to be refused');
    }

    private function stillHeld(PaymentException $exception): void
    {
        $fresh = $exception->fresh();
        $this->assertSame(['open', null, null], [$fresh->status, $fresh->resolution, $fresh->resolved_at], 'exception stays open');
        $this->assertSame(0, RefundAttempt::count(), 'nothing refunded');
    }

    // ------------------------------------------------------------ B2.3 refund review

    public function test_a_verified_refund_choice_is_routed_to_owner_review_without_any_money_decision(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::REFUND_REVIEW);
        $staff = $this->staffUser('admin');

        $review = $this->service()->routeToRefundReview($choice, $staff, 1);

        $receipt = Payment::find($exception->primary_payment_id);
        $this->assertSame([$exception->id, $choice->id, $receipt->id, (int) $exception->checkout_intent_id, null, null, $exception->amount_centavos, 'awaiting_owner_review', $staff->id],
            [(int) $review->payment_exception_id, (int) $review->choice_id, (int) $review->primary_payment_id, (int) $review->checkout_intent_id, $review->booking_id,
                $review->existing_booking_refund_id, (int) $review->received_centavos, $review->status, (int) $review->routed_by]);
        $this->assertNotNull($review->provider_payment_identity_id, 'linked to the receipt identity');
        $acted = $choice->fresh();
        $this->assertSame([PaymentExceptionChoice::ACTED_ON, "refund_review:{$review->id}", $staff->id], [$acted->status, $acted->resolution_reference, (int) $acted->acted_by]);
        $this->stillHeld($exception);
        $this->assertSame(0, BookingRefund::count(), 'no refund case opened');
        $this->assertNull($receipt->fresh()->booking_id);
        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, CheckoutIntent::find($exception->checkout_intent_id)->status);
        $this->assertTrue(PaymentExceptionEvent::where('payment_exception_id', $exception->id)->where('type', PaymentExceptionEvent::REFUND_REVIEW_ROUTED)->exists());
        $this->assertTrue(PaymentExceptionEvent::where('payment_exception_id', $exception->id)->where('type', PaymentExceptionEvent::CHOICE_ACTED_ON)->exists());
    }

    public function test_an_acted_on_choice_is_terminal_routing_twice_supersede_and_a_new_choice_are_refused(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::REFUND_REVIEW);
        $this->service()->routeToRefundReview($choice, $this->staffUser('admin'), 1);
        $acted = $choice->fresh();

        $this->refused(fn () => $this->service()->routeToRefundReview($acted, $this->staffUser('admin'), $acted->lock_version));
        $this->refused(fn () => $this->choices()->verify($acted, $this->staffUser('owner'), 'Synthetic: second verification.', $acted->lock_version));
        $this->refused(fn () => $this->choices()->withdraw($acted, $this->staffUser('owner'), 'Synthetic: withdraw attempt.', $acted->lock_version));
        $this->refused(fn () => $this->choices()->reject($acted, $this->staffUser('owner'), 'Synthetic: reject attempt.', $acted->lock_version));
        $this->refused(fn () => $this->choices()->supersede($acted, $this->staffUser('admin'), [
            'choice' => PaymentExceptionChoice::REFUND_REVIEW, 'evidence_channel' => 'email', 'evidence_at' => now()->subMinute()->toIso8601String(),
            'evidence_reference' => 'synthetic-thread-0003', 'evidence_summary' => 'Synthetic: changed mind.', 'contact_match_method' => 'email_address',
            'contact_match_outcome' => 'matched'], 'Synthetic: guest changed their mind.', $acted->lock_version));
        $this->refused(fn () => $this->choices()->record($exception, $this->staffUser('admin'), [
            'choice' => PaymentExceptionChoice::REFUND_REVIEW, 'evidence_channel' => 'email', 'evidence_at' => now()->subMinute()->toIso8601String(),
            'evidence_reference' => 'synthetic-thread-0004', 'evidence_summary' => 'Synthetic: again.', 'contact_match_method' => 'email_address',
            'contact_match_outcome' => 'matched']), 'already acted on');

        $this->assertSame(1, PaymentExceptionRefundReview::count());
        $this->assertSame(1, PaymentExceptionChoice::where('payment_exception_id', $exception->id)->count());
    }

    public function test_the_database_freezes_an_acted_on_choice_and_its_review_item(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::REFUND_REVIEW);
        $review = $this->service()->routeToRefundReview($choice, $this->staffUser('admin'), 1);

        foreach ([['resolution_reference' => 'refund_review:999'], ['status' => PaymentExceptionChoice::SUPERSEDED], ['acted_by' => null]] as $change) {
            $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->where('id', $choice->id)->update($change)), json_encode($change));
        }
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_refund_reviews')->where('id', $review->id)->update(['status' => 'approved'])));
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_refund_reviews')->where('id', $review->id)->delete()));
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exceptions')->where('id', $exception->id)->update(['status' => 'resolved', 'resolution' => 'allocated',
            'resolved_at' => now(), 'resolved_by' => $choice->acted_by, 'lock_version' => $exception->fresh()->lock_version + 1])), 'a refund-review action never resolves the exception');
        $this->assertSame('refund_review:' . $review->id, $choice->fresh()->resolution_reference);
    }

    public function test_the_database_requires_an_open_exception_and_a_reference_to_act_on_a_choice(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::REFUND_REVIEW);
        $staff = $this->staffUser('admin');

        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->where('id', $choice->id)
            ->update(['status' => 'acted_on', 'acted_by' => $staff->id, 'acted_at' => now(), 'lock_version' => 2])), 'no reference');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->where('id', $choice->id)
            ->update(['resolution_reference' => 'refund_review:1', 'lock_version' => 2])), 'a reference only with acted_on');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exceptions')->where('id', $exception->id)->update(['status' => 'resolved', 'resolution' => 'allocated',
            'resolved_at' => now(), 'resolved_by' => $staff->id, 'lock_version' => $exception->lock_version + 1])), 'no allocation choice');
        $this->assertSame(PaymentExceptionChoice::VERIFIED, $choice->fresh()->status);
    }

    public function test_a_legacy_refund_choice_links_the_open_booking_refund_case_without_opening_another(): void
    {
        $exception = $this->legacyException();
        $case = BookingRefund::create(['booking_id' => $exception->booking_id, 'source' => 'legacy_refund_request', 'status' => BookingRefund::AWAITING_STAFF_REVIEW]);
        $choice = $this->verified($exception, PaymentExceptionChoice::REFUND_REVIEW);

        $review = $this->service()->routeToRefundReview($choice, $this->staffUser('owner'), 1);

        $this->assertSame([(int) $exception->booking_id, $case->id, null], [(int) $review->booking_id, (int) $review->existing_booking_refund_id, $review->checkout_intent_id]);
        $this->assertSame(1, BookingRefund::count(), 'the existing case is referenced, not duplicated');
        $this->assertSame(BookingRefund::AWAITING_STAFF_REVIEW, $case->fresh()->status, 'the case is not advanced');
        $this->assertSame('paid_unallocated', Booking::find($exception->booking_id)->status);
        $this->stillHeld($exception);
    }

    public function test_routing_refuses_unverified_stale_alternative_date_and_non_staff(): void
    {
        $exception = $this->intentException();
        $recorded = $this->choices()->record($exception, $this->staffUser('admin'), [
            'choice' => PaymentExceptionChoice::REFUND_REVIEW, 'evidence_channel' => 'email', 'evidence_at' => now()->subHour()->toIso8601String(),
            'evidence_reference' => 'synthetic-thread-0005', 'evidence_summary' => 'Synthetic.', 'contact_match_method' => 'email_address', 'contact_match_outcome' => 'matched']);
        $this->refused(fn () => $this->service()->routeToRefundReview($recorded, $this->staffUser('admin'), 0));

        $verified = $this->choices()->verify($recorded, $this->staffUser('owner'), 'Synthetic check.', 0);
        $this->refused(fn () => $this->service()->routeToRefundReview($verified, $this->staffUser('admin'), 0), 'changed');
        $this->refused(fn () => $this->service()->routeToRefundReview($verified, $this->staffUser('coach'), 1), 'owner or admin');

        $other = $this->legacyException();
        $alt = $this->verified($other, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $this->refused(fn () => $this->service()->routeToRefundReview($alt, $this->staffUser('admin'), 1), 'refund-review choice');

        $this->assertSame(0, PaymentExceptionRefundReview::count());
    }

    // ------------------------------------------------------------ B2.4 allocation

    public function test_allocation_is_blocked_by_default_because_instructor_capacity_cannot_be_proven(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);

        $attempt = $this->service()->allocateAlternativeDate($choice, $this->staffUser('admin'), 1);

        $this->assertSame([PaymentExceptionAllocationAttempt::BLOCKED, 'instructor_schedule_unconfirmed', null], [$attempt->outcome, $attempt->reason, $attempt->booking_id]);
        $this->assertSame(PaymentExceptionChoice::VERIFIED, $choice->fresh()->status, 'the consent stays usable');
        $this->assertSame(0, Booking::where('checkout_intent_id', $exception->checkout_intent_id)->count());
        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, CheckoutIntent::find($exception->checkout_intent_id)->status);
        $this->stillHeld($exception);
        $this->assertTrue(PaymentExceptionEvent::where('payment_exception_id', $exception->id)->where('type', PaymentExceptionEvent::ALLOCATION_BLOCKED)->exists());
    }

    public function test_a_checkout_is_allocated_atomically_at_its_original_price_and_terms(): void
    {
        $this->instructorsConfirmed();
        $exception = $this->intentException();
        $intent = CheckoutIntent::find($exception->checkout_intent_id);
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $staff = $this->staffUser('admin');

        $attempt = $this->service()->allocateAlternativeDate($choice, $staff, 1);

        $booking = Booking::where('checkout_intent_id', $intent->id)->sole();
        $this->assertSame([PaymentExceptionAllocationAttempt::ALLOCATED, $booking->id], [$attempt->outcome, (int) $attempt->booking_id]);
        $this->assertSame(['confirmed', self::ALT_START, self::ALT_END, 9200.0, 4000.0, 5200.0, 2],
            [$booking->status, $booking->start_date->format('Y-m-d'), $booking->end_date->format('Y-m-d'), (float) $booking->total_amount,
                (float) $booking->downpayment_amount, (float) $booking->balance_amount, $booking->participants()->count()], 'original price and terms');
        $this->assertSame(self::ALT_START, Batch::find($booking->batch_id)->start_date->format('Y-m-d'));
        $intent->refresh();
        $this->assertSame([CheckoutIntent::STATUS_FINALIZED, $booking->id], [$intent->status, (int) $intent->booking_id]);
        $this->assertSame($booking->id, (int) Payment::find($exception->primary_payment_id)->booking_id, 'receipt applied in the same transaction');
        $fresh = $exception->fresh();
        $this->assertSame(['resolved', 'allocated', $staff->id], [$fresh->status, $fresh->resolution, (int) $fresh->resolved_by]);
        $this->assertSame([PaymentExceptionChoice::ACTED_ON, "allocation:{$booking->id}"], [$choice->fresh()->status, $choice->fresh()->resolution_reference]);
        $this->assertSame(0, RefundAttempt::count());
        $this->assertSame(1, Booking::where('checkout_intent_id', $intent->id)->count(), 'one booking per checkout');

        $this->refused(fn () => $this->service()->allocateAlternativeDate($choice->fresh(), $staff, $choice->fresh()->lock_version), 'not a current verified');
        $this->assertSame(1, Booking::where('checkout_intent_id', $intent->id)->count());
    }

    public function test_a_legacy_booking_is_placed_on_the_verified_date_with_its_original_price(): void
    {
        $this->instructorsConfirmed();
        $exception = $this->legacyException();
        $booking = Booking::find($exception->booking_id);
        $before = [(float) $booking->total_amount, (float) $booking->downpayment_amount, (float) $booking->balance_amount];
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);

        $attempt = $this->service()->allocateAlternativeDate($choice, $this->staffUser('owner'), 1);

        $booking->refresh();
        $this->assertSame([PaymentExceptionAllocationAttempt::ALLOCATED, 'confirmed', self::ALT_START], [$attempt->outcome, $booking->status, $booking->start_date->format('Y-m-d')]);
        $this->assertSame($before, [(float) $booking->total_amount, (float) $booking->downpayment_amount, (float) $booking->balance_amount]);
        $this->assertSame(self::ALT_START, Batch::find($booking->batch_id)->start_date->format('Y-m-d'));
        $receipt = Payment::find($exception->primary_payment_id);
        $this->assertNotNull($receipt->review_resolved_at, 'the held receipt is released by the authorized placement');
        $this->assertSame('resolved', $exception->fresh()->status);
        $this->assertSame(PaymentExceptionChoice::ACTED_ON, $choice->fresh()->status);
    }

    public function test_a_full_date_spends_the_consent_and_a_new_choice_can_then_be_recorded(): void
    {
        $this->instructorsConfirmed();
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $this->paxBooking(5, 'confirmed', self::ALT_START);

        $attempt = $this->service()->allocateAlternativeDate($choice, $this->staffUser('admin'), 1);

        $this->assertSame([PaymentExceptionAllocationAttempt::FAILED, 'capacity_full'], [$attempt->outcome, $attempt->reason]);
        $this->assertSame(PaymentExceptionChoice::ACTION_FAILED, $choice->fresh()->status, 'P6: new consent needed');
        $this->assertSame(0, Booking::where('checkout_intent_id', $exception->checkout_intent_id)->count());
        $this->stillHeld($exception);

        $again = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE, ['requested_start_date' => '2027-01-09', 'requested_end_date' => '2027-01-10']);
        $this->assertSame(PaymentExceptionChoice::VERIFIED, $again->status);
        $this->assertSame(1, PaymentExceptionAllocationAttempt::where('outcome', 'failed')->count(), 'the failed attempt is kept');
    }

    public function test_a_changed_price_or_receipt_blocks_allocation_for_staff_review(): void
    {
        $this->instructorsConfirmed();
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        DB::table('checkout_intents')->where('id', $exception->checkout_intent_id)->update(['total_amount' => 9900]);

        $attempt = $this->service()->allocateAlternativeDate($choice, $this->staffUser('admin'), 1);

        $this->assertSame([PaymentExceptionAllocationAttempt::BLOCKED, 'price_changed'], [$attempt->outcome, $attempt->reason]);
        $this->assertSame(PaymentExceptionChoice::VERIFIED, $choice->fresh()->status);
        $this->assertSame(0, Booking::where('checkout_intent_id', $exception->checkout_intent_id)->count());
        $this->stillHeld($exception);
    }

    public function test_a_passed_date_fails_and_a_cancelled_session_blocks(): void
    {
        $this->instructorsConfirmed();
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        Batch::create(['batch_code' => 'B2-CANCELLED-01', 'start_date' => self::ALT_START, 'end_date' => self::ALT_END, 'status' => 'cancelled_by_camp', 'max_capacity' => 6]);

        $blocked = $this->service()->allocateAlternativeDate($choice, $this->staffUser('admin'), 1);
        $this->assertSame([PaymentExceptionAllocationAttempt::BLOCKED, 'date_not_operating'], [$blocked->outcome, $blocked->reason]);

        $this->travelTo(now()->setDate(2026, 12, 27));
        $failed = $this->service()->allocateAlternativeDate($choice->fresh(), $this->staffUser('admin'), 1);
        $this->assertSame([PaymentExceptionAllocationAttempt::FAILED, 'date_passed'], [$failed->outcome, $failed->reason]);
        $this->assertSame(PaymentExceptionChoice::ACTION_FAILED, $choice->fresh()->status);
        $this->stillHeld($exception);
    }

    public function test_a_refused_legacy_allocation_leaves_the_booking_receipt_and_balances_untouched(): void
    {
        $exception = $this->legacyException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $snapshot = fn () => [Booking::find($exception->booking_id)->only(['status', 'start_date', 'end_date', 'batch_id', 'total_amount', 'downpayment_amount', 'balance_amount']),
            Payment::find($exception->primary_payment_id)->only(['booking_id', 'status', 'amount', 'review_resolved_at', 'review_resolved_by'])];
        $before = $snapshot();

        $blocked = $this->service()->allocateAlternativeDate($choice, $this->staffUser('admin'), 1); // default: instructors unconfirmed
        $this->assertSame([PaymentExceptionAllocationAttempt::BLOCKED, 'instructor_schedule_unconfirmed'], [$blocked->outcome, $blocked->reason]);
        $this->assertEquals($before, $snapshot());

        $this->instructorsConfirmed();
        $this->paxBooking(5, 'confirmed', self::ALT_START);
        $failed = $this->service()->allocateAlternativeDate($choice->fresh(), $this->staffUser('admin'), 1);
        $this->assertSame([PaymentExceptionAllocationAttempt::FAILED, 'capacity_full'], [$failed->outcome, $failed->reason]);
        $this->assertEquals($before, $snapshot());
        $this->assertSame(PaymentExceptionChoice::ACTION_FAILED, $choice->fresh()->status);
        $this->stillHeld($exception);
    }

    public function test_writes_inside_a_refused_allocation_roll_back_while_the_attempt_is_kept(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $counts = fn () => [Booking::count(), Batch::count(), Payment::whereNotNull('booking_id')->count()];
        $before = $counts();
        // A verifier that writes inside the allocation transaction before refusing: the write must not survive
        $this->app->instance(InstructorCapacityVerifier::class, new class implements InstructorCapacityVerifier {
            public function verify(string $startDate, string $endDate, int $additionalPax): InstructorCapacityResult
            {
                Batch::create(['batch_code' => 'B2-PARTIAL-01', 'start_date' => $startDate, 'end_date' => $endDate, 'status' => 'open', 'max_capacity' => 6]);

                return InstructorCapacityResult::unverified('instructor_ratio_unknown');
            }
        });

        $attempt = $this->service()->allocateAlternativeDate($choice, $this->staffUser('admin'), 1);

        $this->assertSame([PaymentExceptionAllocationAttempt::BLOCKED, 'instructor_ratio_unknown'], [$attempt->outcome, $attempt->reason]);
        $this->assertSame($before, $counts(), 'the attempted allocation was rolled back');
        $this->assertSame(0, Batch::where('batch_code', 'B2-PARTIAL-01')->count());
        $this->assertTrue($attempt->exists && PaymentExceptionAllocationAttempt::whereKey($attempt->id)->exists(), 'the attempt is durable');
        $this->stillHeld($exception);
    }

    public function test_an_action_failed_choice_cannot_be_reused_and_a_new_date_needs_fresh_consent(): void
    {
        $this->instructorsConfirmed();
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $this->paxBooking(5, 'confirmed', self::ALT_START);
        $this->service()->allocateAlternativeDate($choice, $this->staffUser('admin'), 1);
        $failed = $choice->fresh();
        $this->assertSame(PaymentExceptionChoice::ACTION_FAILED, $failed->status);
        Booking::whereDate('start_date', self::ALT_START)->update(['status' => 'cancelled']); // room again: still no reuse

        $this->refused(fn () => $this->service()->allocateAlternativeDate($failed, $this->staffUser('admin'), $failed->lock_version), 'not a current verified');
        $this->refused(fn () => $this->choices()->verify($failed, $this->staffUser('owner'), 'Synthetic: second verification.', $failed->lock_version));
        $this->refused(fn () => $this->choices()->withdraw($failed, $this->staffUser('owner'), 'Synthetic: withdraw attempt.', $failed->lock_version));
        foreach ([['status' => 'verified'], ['status' => 'acted_on', 'acted_by' => $failed->closed_by, 'acted_at' => now(), 'resolution_reference' => 'allocation:1']] as $change) {
            $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->where('id', $failed->id)->update($change + ['lock_version' => $failed->lock_version + 1])));
        }
        $this->assertSame(0, Booking::where('checkout_intent_id', $exception->checkout_intent_id)->count());

        $recorded = $this->choices()->record($exception, $this->staffUser('admin'), [
            'choice' => PaymentExceptionChoice::ALTERNATIVE_DATE, 'requested_start_date' => self::ALT_START, 'requested_end_date' => self::ALT_END,
            'evidence_channel' => 'email', 'evidence_at' => now()->subMinutes(10)->toIso8601String(), 'evidence_reference' => 'synthetic-thread-0006',
            'evidence_summary' => 'Synthetic: guest confirmed the same date again.', 'contact_match_method' => 'email_address', 'contact_match_outcome' => 'matched']);
        $this->refused(fn () => $this->service()->allocateAlternativeDate($recorded, $this->staffUser('admin'), 0), 'not a current verified');
        $again = $this->choices()->verify($recorded, $this->staffUser('owner'), 'Synthetic: confirmed again with the guest.', 0);
        $this->assertSame(PaymentExceptionAllocationAttempt::ALLOCATED, $this->service()->allocateAlternativeDate($again, $this->staffUser('admin'), 1)->outcome);
        $this->assertSame(['failed', 'allocated'], PaymentExceptionAllocationAttempt::orderBy('id')->pluck('outcome')->all());
    }

    public function test_repeated_blocked_attempts_keep_a_consistent_history_until_allocation(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $staff = $this->staffUser('admin');

        $this->service()->allocateAlternativeDate($choice, $staff, 1);
        $this->service()->allocateAlternativeDate($choice->fresh(), $staff, 1);
        $this->assertSame([PaymentExceptionChoice::VERIFIED, 1], [$choice->fresh()->status, $choice->fresh()->lock_version], 'a blocked attempt never spends or changes the consent');
        $this->instructorsConfirmed();
        $this->service()->allocateAlternativeDate($choice->fresh(), $staff, 1);

        $this->assertSame(['blocked', 'blocked', 'allocated'], PaymentExceptionAllocationAttempt::orderBy('id')->pluck('outcome')->all());
        $this->assertSame(['allocation_blocked', 'allocation_blocked', 'guest_choice_acted_on', 'allocated'], PaymentExceptionEvent::where('payment_exception_id', $exception->id)
            ->whereIn('type', ['allocation_blocked', 'allocation_failed', 'allocated', 'guest_choice_acted_on', 'guest_choice_action_failed'])->orderBy('id')->pluck('type')->all());
        $this->assertSame(1, Booking::where('checkout_intent_id', $exception->checkout_intent_id)->count());
    }

    public function test_choice_locks_and_actions_refuse_to_run_outside_with_locks(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $staff = $this->staffUser('admin');
        $other = $this->staffUser('owner');

        foreach ([
            fn () => $this->choices()->lockChoice($choice, $exception, [PaymentExceptionChoice::VERIFIED], 1),
            fn () => DB::transaction(fn () => $this->choices()->actOn($choice, $exception, $staff, 'allocation:1')),
            fn () => DB::transaction(fn () => $this->choices()->failAction($choice, $exception, $staff, 'Synthetic: outside the locks.')),
            fn () => $this->choices()->withLocks($exception, $staff, fn ($ex) => $this->choices()->actOn($choice, $ex, $other, 'allocation:1')),
        ] as $call) {
            try {
                $call();
                $this->fail('expected a LogicException');
            } catch (\LogicException $e) {
                $this->assertStringContainsString('only be locked or acted on inside', $e->getMessage());
            }
        }
        $this->assertSame([PaymentExceptionChoice::VERIFIED, 1], [$choice->fresh()->status, $choice->fresh()->lock_version]);
    }

    public function test_the_database_resolves_only_with_the_matching_attempt_choice_and_booking(): void
    {
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);
        $staff = $this->staffUser('admin');
        $foreign = $this->paxBooking(2, 'confirmed', self::ALT_START);
        $attempt = ['payment_exception_id' => $exception->id, 'choice_id' => $choice->id, 'outcome' => 'allocated', 'booking_id' => $foreign->id,
            'requested_start_date' => self::ALT_START, 'requested_end_date' => self::ALT_END, 'attempted_by' => $staff->id, 'attempted_at' => now()];

        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_allocation_attempts')->insert($attempt)), 'allocated needs an acted-on choice');
        DB::table('payment_exception_choices')->where('id', $choice->id)->update(['status' => 'acted_on', 'acted_by' => $staff->id, 'acted_at' => now(),
            'resolution_reference' => "allocation:{$foreign->id}", 'lock_version' => 2]);
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_allocation_attempts')->insert($attempt)), 'not this checkout\'s booking');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exceptions')->where('id', $exception->id)->update(['status' => 'resolved', 'resolution' => 'allocated',
            'resolved_at' => now(), 'resolved_by' => $staff->id, 'lock_version' => $exception->fresh()->lock_version + 1])), 'no matching allocated attempt');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_allocation_attempts')->insert(array_merge($attempt, ['outcome' => 'blocked', 'reason' => 'x', 'booking_id' => null]))),
            'a blocked attempt needs a verified choice');
        $this->assertSame('open', $exception->fresh()->status);
    }

    public function test_allocation_refuses_non_staff_and_a_stale_choice_without_recording_anything(): void
    {
        $this->instructorsConfirmed();
        $exception = $this->intentException();
        $choice = $this->verified($exception, PaymentExceptionChoice::ALTERNATIVE_DATE);

        $this->refused(fn () => $this->service()->allocateAlternativeDate($choice, $this->staffUser('coach'), 1), 'owner or admin');
        $this->refused(fn () => $this->service()->allocateAlternativeDate($choice, $this->staffUser('admin'), 0), 'not a current verified');

        $this->assertSame(0, PaymentExceptionAllocationAttempt::count());
        $this->assertSame(PaymentExceptionChoice::VERIFIED, $choice->fresh()->status);
        $this->stillHeld($exception);
    }
}
