<?php

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentExceptionChoice;
use App\Models\PaymentExceptionEvent;
use App\Models\RefundAttempt;
use App\Models\User;
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
 * Batch 3-II B2.1: staff record a guest's explicit choice (refund review or an alternative date) with synthetic text
 * evidence; a different active owner or admin verifies it. The database enforces four-eyes verification,
 * transitions, immutability and one actionable choice per exception. Nothing allocates, refunds, moves money or
 * resolves the exception. All evidence here is synthetic.
 */
class GuestChoiceTest extends TestCase
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
        $this->setUpCheckoutWorld(); // capacity 6 on self::DATE, mail/queue/weather faked, now = 2026-11-02
    }

    private function service(): GuestChoiceService
    {
        return app(GuestChoiceService::class);
    }

    /** A paid checkout that got no place: intent_unallocated exception, receipt without a booking. */
    private function intentException(): PaymentException
    {
        $this->fillDate(5);
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->subMinutes(5)]);
        $this->finalizeIntent($intent);

        return PaymentException::where('checkout_intent_id', $intent->id)->sole();
    }

    /** A legacy booking paid after its date filled up: legacy_unallocated exception. */
    private function legacyException(): PaymentException
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $this->fillDate(5);
        $this->payByWebhook($booking, 'pay_choice_' . $booking->id)->assertOk();

        return PaymentException::where('booking_id', $booking->id)->sole();
    }

    private function evidence(array $overrides = []): array
    {
        return array_merge([
            'choice' => PaymentExceptionChoice::REFUND_REVIEW,
            'evidence_channel' => 'email',
            'evidence_at' => now()->subHour()->toIso8601String(),
            'evidence_reference' => 'synthetic-msg-0001@example.test',
            'evidence_summary' => 'Synthetic: guest replied asking for a refund review instead of a new date.',
            'contact_match_method' => 'email_address',
            'contact_match_outcome' => 'matched',
        ], $overrides);
    }

    private function alternative(array $overrides = []): array
    {
        return $this->evidence(array_merge(['choice' => PaymentExceptionChoice::ALTERNATIVE_DATE, 'requested_start_date' => '2026-12-26',
            'requested_end_date' => '2026-12-27', 'evidence_summary' => 'Synthetic: guest asked to move to Dec 26-27 at the same price.'], $overrides));
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

    private function untouched(PaymentException $exception): void
    {
        $fresh = $exception->fresh();
        $this->assertSame(['open', null, null, null], [$fresh->status, $fresh->resolved_at, $fresh->resolution, $fresh->resolved_by], 'exception stays financially open');
        $this->assertSame(0, BookingRefund::count(), 'no refund case');
        $this->assertSame(0, RefundAttempt::count(), 'no refund');
        $receipt = Payment::find($exception->primary_payment_id);
        $this->assertSame($exception->booking_id === null ? null : (int) $exception->booking_id, $receipt->booking_id === null ? null : (int) $receipt->booking_id, 'receipt not applied');
    }

    // ----------------------------------------------------------------- workflow

    public function test_a_refund_choice_is_recorded_and_verified_by_a_different_admin_without_any_financial_effect(): void
    {
        $exception = $this->intentException();
        $recorder = $this->staffUser('admin');
        $verifier = $this->staffUser('admin');

        $choice = $this->service()->record($exception, $recorder, $this->evidence());
        $verified = $this->service()->verify($choice, $verifier, 'Synthetic: reply came from the booking email address.', 0);

        $intent = CheckoutIntent::find($exception->checkout_intent_id);
        $this->assertSame([PaymentExceptionChoice::VERIFIED, 1, $verifier->id, $recorder->id], [$verified->status, $verified->lock_version, (int) $verified->verified_by, (int) $verified->recorded_by]);
        $this->assertSame([(int) round((float) $intent->total_amount * 100), (int) round((float) $intent->downpayment_amount * 100), $exception->amount_centavos, 'checkout_intent', $intent->id],
            [$verified->original_total_centavos, $verified->original_downpayment_centavos, $verified->received_centavos, $verified->price_snapshot_source, (int) $verified->price_snapshot_source_id], 'original quote kept, never repriced');
        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $intent->status);
        $this->assertSame(0, Booking::where('checkout_intent_id', $intent->id)->count(), 'nothing allocated');
        $this->untouched($exception);
        $this->assertSame([PaymentExceptionEvent::CHOICE_RECORDED, PaymentExceptionEvent::CHOICE_VERIFIED],
            $this->service()->history($exception)['events']->pluck('type')->all());
    }

    public function test_an_alternative_date_choice_on_a_legacy_booking_is_verified_by_a_different_owner(): void
    {
        $exception = $this->legacyException();
        $choice = $this->service()->record($exception, $this->staffUser('owner'), $this->alternative());

        $verified = $this->service()->verify($choice, $this->staffUser('owner'), 'Synthetic: confirmed by phone with the booking contact.', 0);

        $this->assertSame([PaymentExceptionChoice::VERIFIED, '2026-12-26', '2026-12-27', 'booking'],
            [$verified->status, $verified->requested_start_date->toDateString(), $verified->requested_end_date->toDateString(), $verified->price_snapshot_source]);
        $this->assertSame('paid_unallocated', Booking::find($exception->booking_id)->status, 'no place given');
        $this->untouched($exception);
    }

    public function test_the_recorder_can_never_verify_or_reject_their_own_record(): void
    {
        $exception = $this->intentException();
        $recorder = $this->staffUser('admin');
        $choice = $this->service()->record($exception, $recorder, $this->evidence());

        $this->refused(fn () => $this->service()->verify($choice, $recorder, 'Synthetic: trying to verify my own record.', 0), 'You recorded this guest choice');
        $this->refused(fn () => $this->service()->reject($choice, $recorder, 'Synthetic: trying to reject my own record.', 0), 'You recorded this guest choice');
        $this->assertSame(PaymentExceptionChoice::RECORDED, $choice->fresh()->status);
    }

    public function test_coaches_inactive_staff_and_unsupported_or_ineligible_exceptions_are_refused(): void
    {
        $exception = $this->intentException();
        $coach = $this->staffUser('coach');
        $archived = $this->staffUser('admin');
        $archived->update(['status' => 'archived']);

        $this->refused(fn () => $this->service()->record($exception, $coach, $this->evidence()), 'Only an active owner or admin');
        $this->refused(fn () => $this->service()->record($exception, $archived, $this->evidence()), 'Only an active owner or admin');
        $choice = $this->service()->record($exception, $this->staffUser('admin'), $this->evidence());
        $this->refused(fn () => $this->service()->verify($choice, $coach, 'Synthetic: coach attempting verification.', 0), 'Only an active owner or admin');
        $this->refused(fn () => $this->service()->verify($choice, $archived, 'Synthetic: archived admin attempting.', 0), 'Only an active owner or admin');

        // Unsupported kind: paid again on a confirmed booking
        $booking = $this->unpaidBooking(1, now()->addDays(40)->toDateString());
        $this->payByWebhook($booking, 'pay_choice_first')->assertOk();
        $booking->payments()->create(['payment_method' => 'paymongo', 'transaction_id' => 'PAY-CHOICE-2', 'amount' => 2000, 'payment_type' => 'downpayment',
            'status' => 'pending', 'paymongo_resource_id' => "cs_{$booking->id}"]);
        $this->payByWebhook($booking, 'pay_choice_again')->assertOk();
        $paidAgain = PaymentException::where('booking_id', $booking->id)->sole();
        $this->assertSame(PaymentException::KIND_PAID_AGAIN, $paidAgain->kind);
        $this->refused(fn () => $this->service()->record($paidAgain, $this->staffUser('owner'), $this->evidence()), 'needs an owner decision');
    }

    public function test_incomplete_or_sensitive_evidence_is_refused_and_nothing_is_recorded(): void
    {
        $exception = $this->intentException();
        $admin = $this->staffUser('admin');
        $bad = [
            'no choice' => ['choice' => null],
            'unknown choice' => ['choice' => 'keep_money'],
            'refund with dates' => ['requested_start_date' => '2026-12-26', 'requested_end_date' => '2026-12-27'],
            'unknown channel' => ['evidence_channel' => 'telepathy'],
            'no time or reference' => ['evidence_at' => null, 'evidence_reference' => '  '],
            'future time' => ['evidence_at' => now()->addDay()->toIso8601String()],
            'short summary' => ['evidence_summary' => 'refund'],
            'card number' => ['evidence_summary' => 'Synthetic: guest read out card 4111 1111 1111 1111 to us.'],
            'pin' => ['evidence_summary' => 'Synthetic: guest gave PIN: 4821 to confirm identity.'],
            'contact not matched' => ['contact_match_outcome' => 'not_matched'],
            'unknown match method' => ['contact_match_method' => 'guess'],
        ];
        foreach ($bad as $label => $override) {
            $this->refused(fn () => $this->service()->record($exception, $admin, $this->evidence($override)), 'not recorded');
        }
        foreach (['end before start' => ['requested_end_date' => '2026-12-25'], 'past date' => ['requested_start_date' => '2026-10-01', 'requested_end_date' => '2026-10-02'],
            'malformed date' => ['requested_start_date' => '2026-13-40']] as $label => $override) {
            $this->refused(fn () => $this->service()->record($exception, $admin, $this->alternative($override)), 'not recorded');
        }
        $this->assertSame(0, PaymentExceptionChoice::count());
    }

    public function test_reject_and_withdraw_keep_the_record_and_allow_a_new_choice(): void
    {
        $exception = $this->intentException();
        $a = $this->staffUser('admin');
        $b = $this->staffUser('admin');

        $first = $this->service()->record($exception, $a, $this->evidence());
        $this->service()->reject($first, $b, 'Synthetic: reply did not come from the booking email.', 0);
        $second = $this->service()->record($exception, $a, $this->alternative());
        $this->service()->withdraw($second, $a, 'Synthetic: recorded on the wrong exception by mistake.', 0);
        $third = $this->service()->record($exception, $b, $this->evidence());

        $this->assertSame([PaymentExceptionChoice::REJECTED, PaymentExceptionChoice::WITHDRAWN, PaymentExceptionChoice::RECORDED],
            PaymentExceptionChoice::orderBy('id')->pluck('status')->all());
        $this->assertSame($third->id, $this->service()->current($exception)->id);
        $this->assertSame([PaymentExceptionEvent::CHOICE_RECORDED, PaymentExceptionEvent::CHOICE_REJECTED, PaymentExceptionEvent::CHOICE_RECORDED,
            PaymentExceptionEvent::CHOICE_WITHDRAWN, PaymentExceptionEvent::CHOICE_RECORDED], $this->service()->history($exception)['events']->pluck('type')->all());
        $this->untouched($exception);
    }

    public function test_one_actionable_choice_and_changes_of_mind_need_new_consent_and_new_verification(): void
    {
        $exception = $this->legacyException();
        $a = $this->staffUser('admin');
        $b = $this->staffUser('owner');
        $first = $this->service()->record($exception, $a, $this->evidence());

        $this->refused(fn () => $this->service()->record($exception, $b, $this->alternative()), 'already on record');

        // Unverified: superseded by the guest's new choice
        $second = $this->service()->supersede($first, $b, $this->alternative(), 'Synthetic: guest now prefers another date.', 0);
        $verified = $this->service()->verify($second, $a, 'Synthetic: confirmed with the guest by phone.', 0);
        // Verified: a change of mind needs a new record and a new verification; the verified record stays as evidence
        $third = $this->service()->supersede($verified, $a, $this->evidence(), 'Synthetic: guest changed their mind, refund review.', 1);

        $this->assertSame([PaymentExceptionChoice::SUPERSEDED, PaymentExceptionChoice::SUPERSEDED, PaymentExceptionChoice::RECORDED],
            [$first->fresh()->status, $verified->fresh()->status, $third->status]);
        $this->assertSame([$first->id, $second->id], [(int) $second->supersedes_choice_id, (int) $third->supersedes_choice_id]);
        $this->assertSame((int) $a->id, (int) $verified->fresh()->verified_by, 'the earlier verification stays on record');
        $this->assertSame(1, PaymentExceptionChoice::where('payment_exception_id', $exception->id)->actionable()->count());
        $this->untouched($exception);
    }

    public function test_stale_versions_and_closed_choices_are_refused(): void
    {
        $exception = $this->intentException();
        $a = $this->staffUser('admin');
        $b = $this->staffUser('admin');
        $choice = $this->service()->record($exception, $a, $this->evidence());
        $this->service()->verify($choice, $b, 'Synthetic: verified once, correctly.', 0);

        $this->refused(fn () => $this->service()->verify($choice, $b, 'Synthetic: second click on an old page.', 0), 'changed meanwhile');
        $this->refused(fn () => $this->service()->withdraw($choice, $a, 'Synthetic: withdrawing a verified one.', 1), 'changed meanwhile');
        $this->refused(fn () => $this->service()->supersede($choice, $a, $this->alternative(), 'Synthetic: from a stale page view.', 0), 'changed meanwhile');
        $this->assertSame([PaymentExceptionChoice::VERIFIED, 1], [$choice->fresh()->status, $choice->fresh()->lock_version]);
    }

    public function test_choices_are_refused_once_the_booking_no_longer_waits_for_a_place(): void
    {
        $exception = $this->legacyException();
        $a = $this->staffUser('admin');
        $choice = $this->service()->record($exception, $a, $this->evidence());
        Booking::whereKey($exception->booking_id)->update(['status' => 'cancelled_by_camp']); // simulated eligibility change

        $this->refused(fn () => $this->service()->verify($choice, $this->staffUser('admin'), 'Synthetic: verifying after the booking changed.', 0), 'no longer matches');
        $this->assertSame(PaymentExceptionChoice::RECORDED, $choice->fresh()->status);
    }

    // ------------------------------------------------------- database-level integrity

    public function test_the_database_refuses_self_verification_tampering_deletion_and_a_second_actionable_choice(): void
    {
        $exception = $this->intentException();
        $a = $this->staffUser('admin');
        $b = $this->staffUser('admin');
        $choice = $this->service()->record($exception, $a, $this->evidence());
        $copy = (array) DB::table('payment_exception_choices')->where('id', $choice->id)->first(['payment_exception_id', 'choice', 'received_centavos',
            'evidence_channel', 'evidence_at', 'evidence_reference', 'evidence_summary', 'contact_match_method', 'contact_match_outcome', 'recorded_by', 'recorded_at']);
        $copy += ['status' => 'recorded', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()];
        $update = fn (array $set) => fn () => DB::table('payment_exception_choices')->where('id', $choice->id)->update($set);

        $refusals = [
            'self-verification' => $update(['status' => 'verified', 'verified_by' => $a->id, 'verified_at' => now(), 'lock_version' => 1]),
            'verification by a coach' => $update(['status' => 'verified', 'verified_by' => $this->staffUser('coach')->id, 'verified_at' => now(), 'lock_version' => 1]),
            'stale version' => $update(['status' => 'verified', 'verified_by' => $b->id, 'verified_at' => now(), 'lock_version' => 0]),
            'evidence rewritten' => $update(['evidence_summary' => 'Synthetic: a different story written later.', 'lock_version' => 1]),
            'choice flipped' => $update(['choice' => 'alternative_date', 'requested_start_date' => '2026-12-26', 'requested_end_date' => '2026-12-27', 'lock_version' => 1]),
            'resolution written' => $update(['resolution_reference' => 'booking:1', 'lock_version' => 1]),
            'invalid status' => $update(['status' => 'approved', 'lock_version' => 1]),
            'deleted' => fn () => DB::table('payment_exception_choices')->where('id', $choice->id)->delete(),
            'second actionable' => fn () => DB::table('payment_exception_choices')->insert($copy),
        ];
        foreach ($refusals as $label => $write) {
            $this->assertNotNull($this->databaseRefusal($write), "{$label} must be refused by the database");
        }

        // The legitimate transition still works directly, and then verified facts are frozen
        $this->assertNull($this->databaseRefusal($update(['status' => 'verified', 'verified_by' => $b->id, 'verified_at' => now(), 'verification_note' => 'Synthetic note.', 'lock_version' => 1])));
        foreach (['re-verified by another' => $update(['verified_by' => $a->id, 'lock_version' => 2]), 'back to recorded' => $update(['status' => 'recorded', 'verified_by' => null, 'verified_at' => null, 'lock_version' => 2]),
            'withdrawn after verification' => $update(['status' => 'withdrawn', 'closed_by' => $a->id, 'closed_at' => now(), 'closed_reason' => 'Synthetic reason.', 'lock_version' => 2])] as $label => $write) {
            $this->assertNotNull($this->databaseRefusal($write), "{$label} must be refused by the database");
        }
        $this->assertSame(1, PaymentExceptionChoice::count());
    }

    public function test_the_database_refuses_choices_for_unsupported_exceptions_and_inactive_recorders(): void
    {
        $exception = $this->intentException();
        $archived = $this->staffUser('admin');
        $archived->update(['status' => 'archived']);
        $base = ['payment_exception_id' => $exception->id, 'choice' => 'refund_review', 'status' => 'recorded', 'received_centavos' => 1, 'evidence_channel' => 'email',
            'evidence_at' => now(), 'evidence_summary' => 'Synthetic: a valid enough summary.', 'contact_match_method' => 'email_address', 'contact_match_outcome' => 'matched',
            'recorded_by' => $this->staffUser('admin')->id, 'recorded_at' => now(), 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now()];

        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->insert(['recorded_by' => $archived->id] + $base)), 'inactive recorder');
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->insert(['status' => 'verified'] + $base)), 'inserted already verified');
        $invalid = [
            'bad date range' => ['choice' => 'alternative_date', 'requested_start_date' => '2026-12-27', 'requested_end_date' => '2026-12-26'],
            'refund with dates' => ['requested_start_date' => '2026-12-26', 'requested_end_date' => '2026-12-27'],
            'missing evidence time and reference' => ['evidence_at' => null, 'evidence_reference' => null],
            'short summary' => ['evidence_summary' => 'short'],
            'unknown channel' => ['evidence_channel' => 'fax'],
            'unknown choice' => ['choice' => 'keep_money'],
            'pre-set resolution' => ['resolution_reference' => 'booking:1'],
        ];
        foreach ($invalid as $label => $override) {
            $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->insert($override + $base)), "{$label} must be refused by the database");
        }
        $booking = $this->paxBooking(1);
        $other = $this->receipt($booking, 2000, 'paid', 'pay_choice_other');
        $paidAgain = DB::transaction(fn () => app(\App\Services\Payment\PaymentExceptionService::class)->open(PaymentException::KIND_PAID_AGAIN, $other, 'test'));
        $this->assertNotNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->insert(['payment_exception_id' => $paidAgain->id] + $base)), 'unsupported kind');
        $this->assertNull($this->databaseRefusal(fn () => DB::table('payment_exception_choices')->insert($base)), 'a valid row is accepted');
    }
}
