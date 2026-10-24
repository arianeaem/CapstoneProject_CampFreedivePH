<?php

namespace Tests\Feature\Refunds;

use App\Models\Batch;
use App\Models\BookingRefund;
use App\Models\CancellationRequest;
use App\Models\RefundRequest;
use App\Services\Booking\BookingRequestService;
use App\Services\BookingPolicyEngine;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundExecutor;
use App\Services\Refunds\RefundLedger;
use App\Services\WeatherSafetyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-I: client-confirmed refund rules, earlier-terms protection and the branches that stay with staff.
 * Synthetic data; PayMongo is faked and stray requests throw.
 */
class RefundPolicyTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;
    use RefundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Mail::fake();
        $this->mock(WeatherSafetyService::class, fn ($m) => $m->shouldReceive('isStormSignalActive')->andReturn(false));
    }

    private function ledger(): RefundLedger
    {
        return app(RefundLedger::class);
    }

    private function cases(): RefundCaseService
    {
        return app(RefundCaseService::class);
    }

    // ------------------------------------------------------------ voluntary cancellation

    public function test_voluntary_cancellation_more_than_14_days_ahead_quotes_no_refund_under_current_terms(): void
    {
        $booking = $this->booking(['start_date' => now()->addDays(20)->toDateString(), 'end_date' => now()->addDays(21)->toDateString()]);
        $this->receipt($booking, 2000);

        $policy = app(BookingPolicyEngine::class)->evaluate($booking);

        $this->assertSame(0.0, $policy['calculated_refund']);
        $this->assertSame(0, $policy['refund_percentage']);
        $this->assertTrue($policy['reschedule_allowed'], 'Moving dates stays available.');
        $this->assertFalse($policy['historical_terms']);
        $this->assertStringContainsString('non-refundable', $policy['cancel_message']);
    }

    public function test_voluntary_cancellation_7_to_14_days_and_under_7_days_keep_their_existing_rules(): void
    {
        $tenDays = $this->booking(['start_date' => now()->addDays(10)->toDateString(), 'end_date' => now()->addDays(11)->toDateString()]);
        $fiveDays = $this->booking(['start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(6)->toDateString()]);
        $engine = app(BookingPolicyEngine::class);

        $ten = $engine->evaluate($tenDays);
        $this->assertSame([false, true, 0.0], [$ten['cancel_allowed'], $ten['reschedule_allowed'], $ten['calculated_refund']]);
        $five = $engine->evaluate($fiveDays);
        $this->assertSame([false, false, 0.0], [$five['cancel_allowed'], $five['reschedule_allowed'], $five['calculated_refund']]);
    }

    public function test_approving_a_voluntary_cancellation_never_moves_money_and_policy_allows_nothing(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        // In a batch: the coach roster lookup used to crash here (accessor eager-loaded as a relationship)
        $batch = Batch::create(['batch_code' => 'BAT-RF-V', 'name' => 'Synthetic batch', 'start_date' => now()->addDays(30), 'end_date' => now()->addDays(31), 'status' => 'confirmed']);
        $booking = $this->booking(['status' => 'cancellation_requested', 'batch_id' => $batch->id]);
        $this->receipt($booking, 2000);
        $request = CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 0, 'status' => 'pending', 'policy_version' => $booking->refund_policy_version]);

        $result = app(BookingRequestService::class)->approveCancellation($request, $this->staff(), 'full_refund', null, null);

        $case = $result['case'];
        $this->assertSame(BookingRefund::AWAITING_STAFF_REVIEW, $case->status);
        $this->assertSame('Voluntary cancellations are not refunded under the current terms.', $this->ledger()->entitlement($case)['blocked']);
        $this->expectExceptionMessage('not refunded under the current terms');
        try {
            $this->cases()->approve($case, 200000, $this->staff(), 'trying anyway');
        } finally {
            $this->assertSame([], $posts, 'No PayMongo refund request.');
            $this->assertSame('cancelled_by_guest', $booking->fresh()->status);
        }
    }

    // ------------------------------------------------------- earlier published terms

    public function test_booking_under_earlier_terms_keeps_its_quote_and_waits_for_a_staff_decision(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancellation_requested', 'start_date' => now()->addDays(20)->toDateString(), 'end_date' => now()->addDays(21)->toDateString()], policyVersion: null);
        $this->receipt($booking, 2000);

        $policy = app(BookingPolicyEngine::class)->evaluate($booking);
        $this->assertTrue($policy['historical_terms']);
        $this->assertTrue($policy['refund_requires_review']);
        $this->assertSame(2000.0, $policy['calculated_refund'], 'The earlier quote is not reduced to 0.');

        $request = CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 2000, 'status' => 'pending', 'policy_version' => null]);
        $case = app(BookingRequestService::class)->approveCancellation($request, $this->staff(), 'policy_refund', null, null)['case'];

        $this->assertTrue($case->isHistorical());
        $this->assertSame(200000, $case->quoted_centavos);
        $this->assertSame(2000.0, (float) $request->fresh()->calculated_refund_amount, 'The quote shown to the guest is preserved.');
        $this->assertStringContainsString('record the earlier-terms decision', $this->ledger()->entitlement($case)['blocked']);
        $this->assertSame([], $posts, 'Neither repriced nor paid automatically.');

        // Applying the current terms instead of the quote is an owner decision, and it does not close the case
        $applied = $this->cases()->decideHistorical($case, 'apply_current_terms', $this->staff('owner'), 'Guest agreed to move dates instead.');
        $this->assertNotNull($this->ledger()->entitlement($applied)['blocked']);
        $this->assertSame(BookingRefund::AWAITING_STAFF_REVIEW, $applied->status);
    }

    public function test_honoring_earlier_terms_allows_at_most_the_quoted_amount(): void
    {
        $booking = $this->booking(['status' => 'cancellation_requested'], policyVersion: null);
        $this->receipt($booking, 4250, 'paid', 'auto', 'full');
        $request = CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 2000, 'status' => 'pending']);
        $case = app(BookingRequestService::class)->approveCancellation($request, $this->staff(), 'policy_refund', null, null)['case'];

        $case = $this->cases()->decideHistorical($case, 'honor_previous_terms', $this->staff(), 'Booked when the terms promised a full downpayment refund.');

        $this->assertSame(200000, $this->ledger()->entitlement($case)['cap'], 'Quoted ₱2,000, not the ₱4,250 paid.');
        $this->expectExceptionMessage('at most ₱2,000.00');
        $this->cases()->approve($case, 425000, $this->staff(), 'too much');
    }

    public function test_legacy_refund_queue_cannot_bypass_review(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $admin = $this->staff();
        $booking = $this->booking(['status' => 'cancelled_by_camp'], policyVersion: null);
        $payment = $this->receipt($booking, 2000);
        $queued = RefundRequest::create(['payment_id' => $payment->id, 'booking_id' => $booking->id, 'requested_by' => 'camp_force_majeure', 'requested_at' => now(), 'status' => 'pending']);

        $this->actingAs($admin)->post("/admin/payments/refunds/{$queued->id}/approve")->assertRedirect();
        $this->actingAs($admin)->post("/admin/payments/refunds/{$queued->id}/approve")->assertSessionHas('error'); // already handled

        $case = BookingRefund::sole();
        $this->assertSame([BookingRefund::REASON_UNKNOWN, BookingRefund::AWAITING_STAFF_REVIEW], [$case->reason_category, $case->status]);
        $this->assertSame('in_review', $queued->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status, 'Payment untouched.');
        $this->assertSame([], $posts);
        $this->actingAs($admin)->post("/admin/payments/refunds/{$queued->id}/forfeit", ['forfeit_reason' => 'customer_no_show'])->assertSessionHas('error');
    }

    // ------------------------------------------------------------- weather before diving

    public function test_weather_before_diving_with_downpayment_only_refunds_it_after_review_and_choice(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);

        $case = $this->campCase($booking, BookingRefund::REASON_WEATHER_PRE_DIVE, 'no', choice: null);
        $this->assertSame(BookingRefund::AWAITING_GUEST_CHOICE, $case->status);
        $this->assertNotNull($this->ledger()->entitlement($case)['blocked'], 'No refund before the guest chooses.');

        $case = $this->cases()->recordGuestChoice($case, 'refund', $this->staff(), 'Guest asked for the money back');
        $this->assertSame(['cap' => 200000, 'balance_unresolved' => false, 'blocked' => null], $this->ledger()->entitlement($case));
        $case = $this->cases()->approve($case, 200000, $this->staff(), 'Downpayment back');
        app(RefundExecutor::class)->execute($case, $this->staff());

        $this->assertCount(1, $posts);
        $this->assertSame(200000, $posts[0]['amount']);
        $this->assertSame(BookingRefund::COMPLETED, $case->fresh()->status);
    }

    public function test_weather_before_diving_fully_paid_refunds_only_the_downpayment_and_leaves_the_balance_open(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $this->receipt($booking, 2250, 'completed', null, 'balance_settlement');
        $case = $this->campCase($booking, BookingRefund::REASON_WEATHER_PRE_DIVE);

        $allowed = $this->ledger()->entitlement($case);
        $this->assertSame([200000, true], [$allowed['cap'], $allowed['balance_unresolved']]);

        $case = $this->cases()->approve($case, 200000, $this->staff(), 'Downpayment only');
        app(RefundExecutor::class)->execute($case, $this->staff());

        $case->refresh();
        $this->assertSame('unresolved', $case->balance_review);
        $this->assertSame(BookingRefund::APPROVED, $case->status, 'Not settled while the balance question is open.');
        $this->assertSame([200000], array_column($posts, 'amount'), 'Only the downpayment, from the PayMongo payment.');
        $this->assertSame(225000, $this->ledger()->position($booking)['refundable'], 'The balance is neither refunded nor forfeited.');
    }

    public function test_weather_needs_confirmed_progress_unknown_or_started_blocks_refunds(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);

        $unknown = $this->campCase($booking, BookingRefund::REASON_WEATHER_PRE_DIVE, 'unknown');
        $this->assertStringContainsString('had not started', $this->ledger()->entitlement($unknown)['blocked']);

        $inProgress = $this->cases()->classify($unknown, BookingRefund::REASON_WEATHER_IN_PROGRESS, 'yes', $this->staff(), 'Weather turned on Day 2');
        $this->assertStringContainsString('No refund applies', $this->ledger()->entitlement($inProgress)['blocked']);
    }

    public function test_cancelling_a_batch_for_weather_after_the_activity_started_is_refused(): void
    {
        $batch = Batch::create(['batch_code' => 'BAT-RF-1', 'name' => 'Synthetic batch', 'start_date' => now()->addDays(30), 'end_date' => now()->addDays(31), 'status' => 'confirmed']);
        $booking = $this->booking(['batch_id' => $batch->id]);
        $this->receipt($booking, 2000);
        $admin = $this->staff();

        $this->actingAs($admin)->post("/admin/batches/{$batch->id}/status", ['status' => 'cancelled_by_camp', 'note' => 'Storm on Day 2', 'cancellation_reason_category' => 'weather_in_progress', 'activity_started' => 'yes'])
            ->assertSessionHas('error');
        $this->actingAs($admin)->post("/admin/safety-monitoring/{$batch->id}/cancel", ['cancellation_reason' => 'Storm on Day 2', 'activity_started' => 'yes'])
            ->assertSessionHas('error');

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(0, BookingRefund::count());
        $this->assertSame(0, RefundRequest::count(), 'No automatic weather refund requests.');
    }

    public function test_batch_weather_cancellation_opens_cases_for_online_payments_and_emails_options_not_a_refund(): void
    {
        $batch = Batch::create(['batch_code' => 'BAT-RF-2', 'name' => 'Synthetic batch', 'start_date' => now()->addDays(30), 'end_date' => now()->addDays(31), 'status' => 'confirmed']);
        $paid = $this->booking(['batch_id' => $batch->id]);
        $this->receipt($paid, 2000); // online payment ("paid"), which the old code skipped
        $unpaid = $this->booking(['batch_id' => $batch->id]);

        $this->actingAs($this->staff())->post("/admin/safety-monitoring/{$batch->id}/cancel", ['cancellation_reason' => 'Typhoon signal #2', 'activity_started' => 'no'])
            ->assertSessionHas('success');

        $case = BookingRefund::sole();
        $this->assertSame([$paid->id, BookingRefund::REASON_WEATHER_PRE_DIVE, 'no', BookingRefund::AWAITING_GUEST_CHOICE], [$case->booking_id, $case->reason_category, $case->activity_started, $case->status]);
        $this->assertSame('cancelled_by_camp', $unpaid->fresh()->status);
        Mail::assertQueued(\App\Mail\BatchWeatherCancellationMail::class, fn ($m) => $m->booking->is($paid)
            && str_contains($m->optionsText(), 'downpayment (PHP 2,000.00) back') && str_contains($m->optionsText(), 'No refund has been sent'));
        Mail::assertQueued(\App\Mail\BatchWeatherCancellationMail::class, fn ($m) => $m->booking->is($unpaid) && str_contains($m->optionsText(), 'no payment on record'));
        Http::assertNothingSent();
    }

    // --------------------------------------------------------- camp operational reason

    public function test_operational_cancellation_refunds_everything_paid_minus_earlier_refunds(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $this->receipt($booking, 2250, 'partially_refunded', 'auto', 'balance_settlement', ['amount_refunded' => 250]);

        $case = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL);

        $this->assertSame(['cap' => 400000, 'balance_unresolved' => false, 'blocked' => null], $this->ledger()->entitlement($case));
    }

    public function test_operational_cancellation_without_a_guest_choice_or_with_reschedule_never_refunds(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);

        $case = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL, 'no', choice: null);
        $this->assertSame("The guest has not chosen a refund yet.", $this->ledger()->entitlement($case)['blocked']);

        $moved = $this->cases()->recordGuestChoice($case, 'reschedule', $this->staff(), 'Guest moved to Dec 19');
        $this->assertSame(BookingRefund::CLOSED_NO_REFUND, $moved->status);
    }

    public function test_camp_cancellation_without_a_reason_goes_to_review_and_keeps_its_label(): void
    {
        $batch = Batch::create(['batch_code' => 'BAT-RF-3', 'name' => 'Synthetic batch', 'start_date' => now()->addDays(30), 'end_date' => now()->addDays(31), 'status' => 'confirmed']);
        $booking = $this->booking(['batch_id' => $batch->id]);
        $this->receipt($booking, 2000);

        // An older caller without the new fields
        $this->actingAs($this->staff())->post("/admin/batches/{$batch->id}/status", ['status' => 'cancelled_by_camp', 'note' => 'Boat engine failure'])->assertSessionHas('success');

        $case = BookingRefund::sole();
        $this->assertSame([BookingRefund::REASON_UNKNOWN, BookingRefund::AWAITING_STAFF_REVIEW, null], [$case->reason_category, $case->status, $case->reason_confirmed_by]);
        $this->assertSame('The cancellation reason has not been classified.', $this->ledger()->entitlement($case)['blocked']);
        $this->assertSame('cancelled_by_camp', $booking->fresh()->status);
    }
}
