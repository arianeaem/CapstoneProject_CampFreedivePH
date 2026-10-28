<?php

namespace Tests\Feature\Payments;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\CheckoutIntent;
use App\Models\CheckoutNotification;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionChoice;
use App\Models\PaymentExceptionEvent;
use App\Models\PaymentExceptionRefundReview;
use App\Models\ProviderPaymentIdentity;
use App\Models\RefundAttempt;
use App\Models\User;
use App\Services\Payment\ExceptionRefundService;
use App\Services\Payment\ExceptionResolutionService;
use App\Services\Payment\GuestChoiceService;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundExecutor;
use App\Services\Refunds\RefundLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * B3: owner/admin approval of exception refunds under the client-approved policies. P1: the full verified, unallocated
 * amount (no deductions) less what is already refunded or reserved; P2: an active owner or admin decides; P3: rejection
 * only for invalid requests and nothing is forfeited; P4/P5: approval reserves, pays nothing and resolves nothing;
 * P6: one balance per payment across booking and exception refunds. All data is synthetic; PayMongo is faked.
 */
class ExceptionRefundApprovalTest extends TestCase
{
    use BuildsCheckoutIntents;
    use FakesPayMongo;
    use RefreshDatabase;
    use ReceiptFixtures;
    use RefundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        $this->setUpCheckoutWorld(); // capacity 6 on self::DATE, mail/queue faked, now = 2026-11-02
    }

    private function refunds(): ExceptionRefundService
    {
        return app(ExceptionRefundService::class);
    }

    /** A paid checkout that got no booking: capacity_unavailable (date full) or date_not_operating (camp cancelled). */
    private function intentException(string $reason = 'capacity_unavailable'): PaymentException
    {
        if ($reason === 'date_not_operating') {
            Batch::create(['batch_code' => 'B3-' . Str::upper(Str::random(6)), 'name' => 'B3 closed', 'start_date' => self::DATE, 'end_date' => '2026-12-20',
                'status' => 'cancelled_by_camp', 'lifecycle_status' => 'cancelled_by_camp']);
        } else {
            $this->fillDate(5);
        }
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->subMinutes(5)]);
        $this->assertSame($reason, $this->finalizeIntent($intent)->reason);

        return PaymentException::where('checkout_intent_id', $intent->id)->sole();
    }

    private function legacyException(): PaymentException
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $this->fillDate(5);
        $this->payByWebhook($booking, 'pay_b3_' . $booking->id)->assertOk();

        return PaymentException::where('booking_id', $booking->id)->sole();
    }

    private function choiceData(string $choice = 'refund_review'): array
    {
        return ['choice' => $choice, 'evidence_channel' => 'email', 'evidence_at' => now()->subHour()->toIso8601String(), 'evidence_reference' => 'synthetic-thread-b3',
            'evidence_summary' => 'Synthetic: guest asked for their money back.', 'contact_match_method' => 'email_address', 'contact_match_outcome' => 'matched']
            + ($choice === 'alternative_date' ? ['requested_start_date' => '2027-01-23', 'requested_end_date' => '2027-01-24'] : []);
    }

    /** A verified refund choice routed to owner review (B2). */
    private function review(PaymentException $exception): PaymentExceptionRefundReview
    {
        $choices = app(GuestChoiceService::class);
        $choice = $choices->verify($choices->record($exception, $this->staffUser('admin'), $this->choiceData()), $this->staffUser('owner'), 'Synthetic: confirmed by email.', 0);

        return app(ExceptionResolutionService::class)->routeToRefundReview($choice, $this->staffUser('admin'), 1);
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

    private function stillHeldAndUnpaid(PaymentException $exception): void
    {
        $fresh = $exception->fresh();
        $this->assertSame(['open', null, null], [$fresh->status, $fresh->resolution, $fresh->resolved_at], 'exception stays open');
        $this->assertSame(0, RefundAttempt::count(), 'nothing executed');
        Http::assertNothingSent();
    }

    private function attempt(PaymentException $exception, int $centavos, string $status): void
    {
        RefundAttempt::create(['payment_id' => $exception->primary_payment_id, 'operation_id' => (string) Str::uuid(), 'method' => 'offline',
            'amount_centavos' => $centavos, 'currency' => 'PHP', 'status' => $status]);
    }

    // ------------------------------------------------------------ P1 entitlement

    private function assertFullEntitlementApproved(string $reason): void
    {
        $exception = $this->intentException($reason);
        $review = $this->review($exception);
        $owner = $this->staffUser('owner');

        $this->assertSame(['centavos' => $exception->amount_centavos, 'currency' => 'PHP', 'blocked' => null], $this->refunds()->entitlement($review));
        $approved = $this->refunds()->approve($review, $owner, 0, 'Synthetic: full refund per policy P1.');

        $this->assertSame([PaymentExceptionRefundReview::APPROVED_AWAITING_PAYOUT, $exception->amount_centavos, 'PHP', $owner->id, 'owner', 1],
            [$approved->status, $approved->approved_centavos, $approved->approved_currency, (int) $approved->approved_by, $approved->approver_role, $approved->lock_version]);
        $this->stillHeldAndUnpaid($exception);
    }

    public function test_full_entitlement_without_deductions_when_the_date_was_full(): void
    {
        $this->assertFullEntitlementApproved('capacity_unavailable');
    }

    public function test_full_entitlement_without_deductions_when_the_camp_was_not_running(): void
    {
        $this->assertFullEntitlementApproved('date_not_operating');
    }

    public function test_earlier_confirmed_and_reserved_refunds_reduce_the_entitlement(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $this->attempt($exception, 50000, RefundAttempt::SUCCEEDED);
        $this->attempt($exception, 30000, RefundAttempt::PROVIDER_PENDING);
        $this->attempt($exception, 99999, RefundAttempt::FAILED); // failed attempts give nothing back and hold nothing

        $approved = $this->refunds()->approve($review, $this->staffUser('admin'), 0);

        $this->assertSame($exception->amount_centavos - 80000, $approved->approved_centavos);
        $this->assertSame(0, app(RefundLedger::class)->paymentRow(Payment::find($exception->primary_payment_id))['refundable'], 'fully claimed now');
    }

    public function test_a_payment_with_nothing_left_cannot_be_approved(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $this->attempt($exception, $exception->amount_centavos, RefundAttempt::SUCCEEDED);

        $this->refused(fn () => $this->refunds()->approve($review, $this->staffUser('owner'), 0), 'nothing of this payment is left');
        $this->assertSame(PaymentExceptionRefundReview::AWAITING_OWNER_REVIEW, $review->fresh()->status);
    }

    public function test_bookingless_checkout_approval_creates_no_booking_and_keeps_the_receipt_unapplied(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $bookings = Booking::count();

        $this->refunds()->approve($review, $this->staffUser('admin'), 0);

        $this->assertSame($bookings, Booking::count(), 'no fake booking');
        $this->assertNull($review->fresh()->booking_id);
        $this->assertNull(Payment::find($exception->primary_payment_id)->booking_id, 'receipt still unapplied');
        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, CheckoutIntent::find($exception->checkout_intent_id)->status);
    }

    // ------------------------------------------------------------ P6 one balance with booking refunds

    public function test_a_legacy_booking_case_and_the_exception_share_one_balance(): void
    {
        $exception = $this->legacyException();
        $case = BookingRefund::create(['booking_id' => $exception->booking_id, 'source' => 'legacy_refund_request', 'status' => BookingRefund::AWAITING_STAFF_REVIEW]);
        $review = $this->review($exception);
        $this->assertSame($case->id, (int) $review->existing_booking_refund_id, 'linked, not duplicated');

        $this->refunds()->approve($review, $this->staffUser('owner'), 0);

        $row = app(RefundLedger::class)->position(Booking::find($exception->booking_id))['payments']->firstWhere('payment.id', $exception->primary_payment_id);
        $this->assertSame([$exception->amount_centavos, 0], [$row['reserved'], $row['refundable']], 'the booking side sees the approval as reserved');
        $this->assertSame(1, BookingRefund::count());
        $this->assertSame(BookingRefund::AWAITING_STAFF_REVIEW, $case->fresh()->status, 'the booking case is not advanced');
    }

    // ------------------------------------------------------------ P2 authorization

    public function test_owners_and_admins_approve_through_the_screen_coaches_and_guests_cannot(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $url = "/admin/payments/exceptions/{$exception->id}/refund-reviews/{$review->id}/approve";

        $this->actingAs($this->staffUser('coach'))->post($url, ['lock_version' => '0'])->assertForbidden();
        auth()->logout();
        $this->post($url, ['lock_version' => '0'])->assertRedirect();
        $this->assertSame(PaymentExceptionRefundReview::AWAITING_OWNER_REVIEW, $review->fresh()->status);

        $this->actingAs($this->staffUser('admin'))->get("/admin/payments/exceptions/{$exception->id}")->assertOk()->assertSee('Approve refund');
        $this->actingAs($this->staffUser('admin'))->post($url, ['lock_version' => '0', 'approved_centavos' => '1', 'amount' => '1'])->assertSessionHas('success');
        $fresh = $review->fresh();
        $this->assertSame(['admin', $exception->amount_centavos], [$fresh->approver_role, $fresh->approved_centavos], 'the server computes the amount; form values are ignored');
    }

    public function test_a_coach_or_inactive_account_cannot_approve_through_the_service(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $inactive = $this->staffUser('admin');
        $inactive->forceFill(['status' => 'inactive'])->save();

        $this->refused(fn () => $this->refunds()->approve($review, $this->staffUser('coach'), 0), 'Only an active owner or admin');
        $this->refused(fn () => $this->refunds()->approve($review, $inactive, 0), 'Only an active owner or admin');
        $this->assertSame(PaymentExceptionRefundReview::AWAITING_OWNER_REVIEW, $review->fresh()->status);
    }

    public function test_csrf_is_enforced(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $this->withMiddleware()->actingAs($this->staffUser('owner'));
        $this->app['env'] = 'production';

        $this->assertSame(419, $this->call('POST', "/admin/payments/exceptions/{$exception->id}/refund-reviews/{$review->id}/approve", ['lock_version' => '0'])->status());
        $this->assertSame(PaymentExceptionRefundReview::AWAITING_OWNER_REVIEW, $review->fresh()->status);
    }

    public function test_booking_refund_approval_and_payout_also_require_an_active_owner_or_admin(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $case = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL);

        $this->refused(fn () => app(RefundCaseService::class)->approve($case, 200000, $this->staffUser('coach'), 'Synthetic approval'), 'Only an active owner or admin');
        $this->assertNull($case->fresh()->approved_by);
        $this->refused(fn () => app(RefundExecutor::class)->execute($case, $this->staffUser('coach')), 'Only an active owner or admin');

        $approved = app(RefundCaseService::class)->approve($case, 200000, $this->staffUser('admin'), 'Synthetic approval');
        $this->assertSame(BookingRefund::APPROVED, $approved->status, 'existing booking policy unchanged for an owner or admin');
    }

    // ------------------------------------------------------------ idempotency and stale actions

    public function test_a_replayed_approval_returns_the_same_outcome_and_a_stale_one_is_refused(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $owner = $this->staffUser('owner');

        $first = $this->refunds()->approve($review, $owner, 0);
        $again = $this->refunds()->approve($review, $this->staffUser('admin'), 0); // same form submitted twice

        $this->assertSame([$first->id, 1, (int) $owner->id], [$again->id, $again->lock_version, (int) $again->approved_by]);
        $this->assertSame(1, PaymentExceptionEvent::where('type', PaymentExceptionEvent::REFUND_APPROVED)->count(), 'no duplicate audit event');
        $this->refused(fn () => $this->refunds()->approve($review, $owner, 5), 'changed meanwhile');
        $this->refused(fn () => $this->refunds()->reject($review, $owner, 'Synthetic: duplicate request.', 1), 'changed meanwhile');
    }

    // ------------------------------------------------------------ guest-choice states

    public function test_only_a_verified_refund_choice_reaches_review_superseded_withdrawn_or_rejected_ones_cannot(): void
    {
        $exception = $this->intentException();
        $choices = app(GuestChoiceService::class);
        $routing = app(ExceptionResolutionService::class);
        $admin = $this->staffUser('admin');

        $withdrawn = $choices->withdraw($choices->record($exception, $admin, $this->choiceData()), $admin, 'Synthetic: recorded by mistake.', 0);
        $this->refused(fn () => $routing->routeToRefundReview($withdrawn, $admin, 1));
        $rejected = $choices->reject($choices->record($exception, $admin, $this->choiceData()), $this->staffUser('owner'), 'Synthetic: evidence not accepted.', 0);
        $this->refused(fn () => $routing->routeToRefundReview($rejected, $admin, 1));
        $first = $choices->verify($choices->record($exception, $admin, $this->choiceData()), $this->staffUser('owner'), 'Synthetic: confirmed by email.', 0);
        $choices->supersede($first, $this->staffUser('owner'), $this->choiceData('alternative_date'), 'Synthetic: guest changed their mind.', 1);
        $this->refused(fn () => $routing->routeToRefundReview($first->fresh(), $admin, 2));

        $this->assertSame(0, PaymentExceptionRefundReview::count(), 'nothing to approve');
    }

    // ------------------------------------------------------------ database rules (identity, currency, amount, states)

    public function test_the_database_refuses_identity_changes_wrong_amounts_currency_and_premature_refunded(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $owner = $this->staffUser('owner');
        $approve = fn (array $over) => DB::table('payment_exception_refund_reviews')->where('id', $review->id)->update(array_merge([
            'status' => 'approved_awaiting_payout', 'approved_centavos' => $exception->amount_centavos, 'approved_currency' => 'PHP',
            'approved_by' => $owner->id, 'approver_role' => 'owner', 'approved_at' => now(), 'lock_version' => 1], $over));

        foreach ([
            'over the amount received' => ['approved_centavos' => $exception->amount_centavos + 1],
            'negative amount' => ['approved_centavos' => -100],
            'zero amount' => ['approved_centavos' => 0],
            'currency mismatch' => ['approved_currency' => 'USD'],
            'role mismatch' => ['approver_role' => 'admin'],
            'coach approver' => ['approved_by' => $this->staffUser('coach')->id, 'approver_role' => 'coach'],
            'version skipped' => ['lock_version' => 2],
            'refunded without payout' => ['status' => 'refunded'],
            'another receipt' => ['primary_payment_id' => $this->receipt($this->booking(), 10)->id],
            'another checkout' => ['checkout_intent_id' => $this->checkoutIntent()->id],
            'amount received rewritten' => ['received_centavos' => 1, 'approved_centavos' => 1],
        ] as $label => $over) {
            $this->assertNotNull($this->databaseRefusal(fn () => $approve($over)), $label);
        }
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_refund_reviews')->where('id', $review->id)->delete()), 'delete');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_refund_reviews')->where('id', $review->id)
            ->update(['status' => 'rejected_invalid', 'rejected_by' => $owner->id, 'rejected_at' => now(), 'rejection_reason' => 'short', 'lock_version' => 1])), 'rejection without a real reason');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('provider_payment_identities')->where('payment_id', $exception->primary_payment_id)
            ->update(['verification' => 'unverified'])), 'the payment identity stays verified');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payments')->where('id', $exception->primary_payment_id)
            ->update(['booking_id' => $this->booking()->id])), 'the receipt is never reassigned');

        $this->assertSame(PaymentExceptionRefundReview::AWAITING_OWNER_REVIEW, $review->fresh()->status);
        $this->refunds()->approve($review, $owner, 0);
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_refund_reviews')->where('id', $review->id)
            ->update(['status' => 'refunded', 'lock_version' => 2])), 'approved never becomes refunded without a verified payout');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exceptions')->where('id', $exception->id)
            ->update(['status' => 'resolved', 'resolution' => 'refunded', 'resolved_at' => now(), 'resolved_by' => $owner->id, 'lock_version' => $exception->fresh()->lock_version + 1])),
            'approval does not resolve the exception');
    }

    public function test_an_unverified_payment_has_no_currency_so_the_database_refuses_its_approval(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $identity = ProviderPaymentIdentity::where('payment_id', $exception->primary_payment_id)->sole();
        $this->assertSame([true, 'PHP'], [$identity->isVerified(), $exception->currency], 'the verified identity gives the exception its currency');
        // Simulate an unverified receipt (test database only): lift the exception guard for this one write
        DB::statement(DB::getDriverName() === 'pgsql' ? 'ALTER TABLE payment_exceptions DISABLE TRIGGER USER' : 'DROP TRIGGER IF EXISTS payment_exceptions_guard');
        DB::table('payment_exceptions')->where('id', $exception->id)->update(['currency' => null]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE payment_exceptions ENABLE TRIGGER USER');
        }

        $this->refused(fn () => $this->refunds()->approve($review, $this->staffUser('owner'), 0), 'currency does not match');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_refund_reviews')->where('id', $review->id)->update([
            'status' => 'approved_awaiting_payout', 'approved_centavos' => 100, 'approved_currency' => 'PHP', 'approved_by' => $this->staffUser('owner')->id,
            'approver_role' => 'owner', 'approved_at' => now(), 'lock_version' => 1])), 'no currency, no approval');
    }

    // ------------------------------------------------------------ P3 rejection, P4/P5 no execution or resolution

    public function test_rejecting_an_invalid_request_keeps_the_money_held_and_nothing_forfeited(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);

        $this->refused(fn () => $this->refunds()->reject($review, $this->staffUser('owner'), 'too short', 0), '10 to 500');
        $this->refused(fn () => $this->refunds()->reject($review, $this->staffUser('owner'), 'Synthetic: guest wrote from someone.else@example.test.', 0), 'email');
        $rejected = $this->refunds()->reject($review, $this->staffUser('owner'), 'Synthetic: duplicate of an earlier request.', 0);

        $this->assertSame(PaymentExceptionRefundReview::REJECTED_INVALID, $rejected->status);
        $this->stillHeldAndUnpaid($exception);
        $receipt = Payment::find($exception->primary_payment_id);
        $this->assertSame([null, 'paid'], [$receipt->booking_id, $receipt->status], 'the verified payment is untouched');
        $this->assertSame($exception->amount_centavos, app(RefundLedger::class)->paymentRow($receipt)['refundable'], 'the entitlement is not reduced');
        $this->refused(fn () => $this->refunds()->approve($review, $this->staffUser('owner'), 1), 'changed meanwhile');
    }

    public function test_approval_executes_nothing_notifies_nobody_and_resolves_nothing(): void
    {
        $exception = $this->intentException();
        $review = $this->review($exception);
        $notifications = CheckoutNotification::count();
        Mail::fake();

        $this->refunds()->approve($review, $this->staffUser('owner'), 0);

        $this->stillHeldAndUnpaid($exception);
        $this->assertSame($notifications, CheckoutNotification::count(), 'no guest message');
        Mail::assertNothingOutgoing();
        $this->assertSame(PaymentExceptionChoice::ACTED_ON, PaymentExceptionChoice::find($review->choice_id)->status);
        $this->assertSame(['refund_review_routed', 'refund_approved'], PaymentExceptionEvent::where('payment_exception_id', $exception->id)
            ->whereIn('type', ['refund_review_routed', 'refund_approved', 'refund_rejected'])->orderBy('id')->pluck('type')->all(), 'audit trail');
    }

    public function test_the_migration_refuses_rollback_once_a_decision_exists(): void
    {
        $exception = $this->intentException();
        $this->refunds()->approve($this->review($exception), $this->staffUser('owner'), 0);
        $migration = require database_path('migrations/2026_10_29_000001_add_exception_refund_decisions.php');

        $this->refused(fn () => $migration->down(), 'Refund review decisions are recorded');
        $this->assertSame(1, PaymentExceptionRefundReview::where('status', 'approved_awaiting_payout')->count());
    }
}
