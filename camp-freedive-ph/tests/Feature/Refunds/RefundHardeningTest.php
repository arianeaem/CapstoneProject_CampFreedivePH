<?php

namespace Tests\Feature\Refunds;

use App\Models\Booking;
use App\Models\BookingRefund;
use App\Models\CancellationRequest;
use App\Models\Payment;
use App\Models\RefundAttempt;
use App\Models\RefundRequest;
use App\Services\Refunds\RefundCaseService;
use App\Services\Refunds\RefundExecutor;
use App\Services\Refunds\RefundLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-I hardening (pre-commit audit H-1, H-2, M-1, M-2):
 *  - an earlier-terms refund promise is never forfeited, rejected or closed without an explicit, recorded owner decision;
 *  - an unclear PayMongo outcome stays reserved: staff notes never release it for a second POST;
 *  - a PayMongo refund counts as succeeded only when PayMongo itself confirms it (GET /refunds/{id});
 *  - an unresolved weather balance keeps its case open.
 * PayMongo is faked throughout (Http::fake); nothing is sent anywhere.
 */
class RefundHardeningTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;
    use RefundFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Mail::fake();
    }

    private function cases(): RefundCaseService
    {
        return app(RefundCaseService::class);
    }

    /** A booking under the earlier published terms with a pending cancellation quoted at ₱2,000. */
    private function historicalRequest(): CancellationRequest
    {
        $booking = $this->booking(['status' => 'cancellation_requested'], policyVersion: null);
        $this->receipt($booking, 2000);

        return CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 2000, 'status' => 'pending', 'policy_version' => null]);
    }

    /** An old refund-queue row (they were all created before the policy version existed). */
    private function legacyRequest(Booking $booking, Payment $payment, array $eligibility = ['refund_percentage' => 100], string $by = 'customer'): RefundRequest
    {
        return RefundRequest::create(['payment_id' => $payment->id, 'booking_id' => $booking->id, 'requested_by' => $by, 'requested_at' => now(),
            'eligibility_calculated' => $eligibility, 'status' => 'pending', 'notes' => 'Synthetic legacy row']);
    }

    private function historicalCase(): BookingRefund
    {
        return app(\App\Services\Booking\BookingRequestService::class)->approveCancellation($this->historicalRequest(), $this->staff(), 'policy_refund', null, null)['case'];
    }

    private function caseUrl(BookingRefund $case, string $tail = '', string $area = 'admin'): string
    {
        return "/{$area}/payments/refund-cases/{$case->id}" . ($tail ? "/{$tail}" : '');
    }

    /** An approved camp-operational case whose first PayMongo POST got $answer (left unknown/pending). */
    private function caseWithOpenAttempt(mixed $answer, array &$posts): array
    {
        $this->fakeRefunds([$answer, 'succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $case = $this->cases()->approve($this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL), 200000, $this->staff(), 'ok');
        app(RefundExecutor::class)->execute($case, $this->staff());

        return [$case, RefundAttempt::sole(), $booking];
    }

    // =================================================================== H-1: earlier-terms promises

    public function test_crafted_forfeit_post_cannot_approve_a_historical_quoted_cancellation_without_refund(): void
    {
        $request = $this->historicalRequest();

        $this->actingAs($this->staff())->post("/admin/bookings/requests/cancellation/{$request->id}/approve", ['action_type' => 'forfeit'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'earlier published terms'));

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('cancellation_requested', $request->booking->fresh()->status);
        $this->assertSame(0, BookingRefund::count());
        $this->assertEquals(2000, $request->fresh()->calculated_refund_amount, 'The quote is untouched.');
    }

    public function test_legacy_forfeit_cannot_keep_money_promised_under_earlier_terms(): void
    {
        $booking = $this->booking(['status' => 'cancellation_requested'], policyVersion: null);
        $payment = $this->receipt($booking, 2000);
        $request = $this->legacyRequest($booking, $payment);

        $this->actingAs($this->staff())->post("/admin/payments/refunds/{$request->id}/forfeit", ['forfeit_reason' => 'custom_administrative_decision'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot be forfeited'));

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertFalse((bool) $payment->fresh()->is_forfeited);

        // A row that showed 0% (outside the window back then) carries no promise and can still be forfeited
        $other = $this->booking(['status' => 'cancellation_requested'], policyVersion: null);
        $otherPayment = $this->receipt($other, 2000);
        $noPromise = $this->legacyRequest($other, $otherPayment, ['refund_percentage' => 0]);
        $this->post("/admin/payments/refunds/{$noPromise->id}/forfeit", ['forfeit_reason' => 'cancellation_outside_policy_window'])->assertSessionHas('success');
        $this->assertSame('forfeited', $otherPayment->fresh()->status);
    }

    public function test_rejecting_an_earlier_terms_promise_needs_the_owner_and_a_rationale_on_every_endpoint(): void
    {
        $request = $this->historicalRequest();
        $legacyBooking = $this->booking(['status' => 'cancellation_requested'], policyVersion: null);
        $legacy = $this->legacyRequest($legacyBooking, $this->receipt($legacyBooking, 2000));

        $this->actingAs($this->staff())->post("/admin/bookings/requests/cancellation/{$request->id}/reject", ['admin_notes' => 'Outside policy now'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Only the owner'));
        $this->post("/admin/payments/refunds/{$legacy->id}/reject", ['notes' => 'Outside policy now'])->assertSessionHas('error', fn ($m) => str_contains($m, 'Only the owner'));
        $owner = $this->staff('owner');
        $this->actingAs($owner)->post("/owner/bookings/requests/cancellation/{$request->id}/reject", ['admin_notes' => 'no'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'rationale'));
        $this->assertSame(['pending', 'pending'], [$request->fresh()->status, $legacy->fresh()->status]);

        $this->post("/owner/bookings/requests/cancellation/{$request->id}/reject", ['admin_notes' => 'Guest withdrew the request by phone on Oct 20; keeping the booking.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('rejected', $request->fresh()->status);
        $this->assertEquals(2000, $request->fresh()->calculated_refund_amount);
        $this->assertDatabaseHas('audit_logs', ['action' => 'HISTORICAL_PROMISE_DENIED']);
        $this->assertStringContainsString('₱2,000.00', \App\Models\AuditLog::where('action', 'HISTORICAL_PROMISE_DENIED')->value('description'));
    }

    public function test_historical_case_cannot_be_closed_without_the_owner_decision(): void
    {
        $case = $this->historicalCase();
        $admin = $this->staff();
        $owner = $this->staff('owner');

        $this->actingAs($admin)->post($this->caseUrl($case, 'close'), ['note' => 'Guest is outside the current window.'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Only the owner'));
        $this->actingAs($owner)->post($this->caseUrl($case, 'close', 'owner'), ['note' => 'Owner closing without a decision.'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'apply current terms'));

        $this->assertSame(BookingRefund::AWAITING_STAFF_REVIEW, $case->fresh()->status);
    }

    public function test_one_character_close_rationale_is_refused_by_the_form_and_the_service(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($booking, 2000);
        $case = $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL);

        $this->actingAs($this->staff())->post($this->caseUrl($case, 'close'), ['note' => '.'])->assertSessionHasErrors('note');
        try {
            $this->cases()->close($case, $this->staff('owner'), '.');
            $this->fail('Closed with a one-character rationale.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('at least 10', $e->getMessage());
        }
        $this->assertTrue(in_array($case->fresh()->status, BookingRefund::OPEN_STATUSES, true));
    }

    public function test_earlier_terms_decision_is_authorized_explicit_and_recorded_and_never_closes_by_itself(): void
    {
        $case = $this->historicalCase();
        $admin = $this->staff();
        $owner = $this->staff('owner');

        // Admin cannot apply the current terms instead of the promise; a short rationale is refused
        $this->actingAs($admin)->post($this->caseUrl($case, 'historical'), ['decision' => 'apply_current_terms', 'rationale' => 'Current policy applies to everyone.'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Only the owner'));
        $this->assertNull($case->fresh()->historical_decision);

        // Owner applies the current terms with a rationale: recorded with actor, time and the kept quote; still open
        $this->actingAs($owner)->post($this->caseUrl($case, 'historical', 'owner'), ['decision' => 'apply_current_terms', 'rationale' => 'Guest confirmed in writing they accept the new terms.'])
            ->assertSessionHas('success');
        $fresh = $case->fresh();
        $this->assertSame(['apply_current_terms', $owner->id, 'Guest confirmed in writing they accept the new terms.', 200000],
            [$fresh->historical_decision, $fresh->historical_decided_by, $fresh->historical_rationale, $fresh->quoted_centavos]);
        $this->assertNotNull($fresh->historical_decided_at);
        $this->assertSame(BookingRefund::AWAITING_STAFF_REVIEW, $fresh->status, 'The decision itself does not close or deny anything.');
        $this->assertStringContainsString('₱2,000.00 kept on record', \App\Models\AuditLog::where('action', 'REFUND_HISTORICAL_DECISION')->latest('id')->value('description'));

        // Only the owner can then close it; the request's quote stays as it was
        $this->actingAs($admin)->post($this->caseUrl($case, 'close'), ['note' => 'Closing per the owner decision.'])->assertSessionHas('error');
        $this->actingAs($owner)->post($this->caseUrl($case, 'close', 'owner'), ['note' => 'Closed per the recorded owner decision.'])->assertSessionHas('success');
        $this->assertSame(BookingRefund::CLOSED_NO_REFUND, $case->fresh()->status);
        $this->assertEquals(2000, $case->cancellationRequest->fresh()->calculated_refund_amount);
    }

    public function test_honouring_the_promise_is_open_to_staff_and_allows_up_to_the_quote(): void
    {
        $case = $this->historicalCase();

        $this->actingAs($this->staff())->post($this->caseUrl($case, 'historical'), ['decision' => 'honor_previous_terms', 'rationale' => 'short'])
            ->assertSessionHasErrors('rationale');
        try {
            $this->cases()->decideHistorical($case, 'honor_previous_terms', $this->staff(), 'short');
            $this->fail('Decision recorded with a short rationale.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('at least 10', $e->getMessage());
        }
        $this->post($this->caseUrl($case, 'historical'), ['decision' => 'honor_previous_terms', 'rationale' => 'Booked when the terms promised a downpayment refund.'])
            ->assertSessionHas('success');

        $this->assertSame(200000, app(RefundLedger::class)->entitlement($case->fresh())['cap']);
    }

    public function test_legacy_camp_promise_opened_from_the_queue_keeps_its_quote_and_needs_a_decision(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp'], policyVersion: null);
        $payment = $this->receipt($booking, 4250, 'paid', 'auto', 'full');
        $legacy = $this->legacyRequest($booking, $payment, ['refund_percentage' => 100], 'camp_force_majeure');

        $case = $this->cases()->openFromLegacyRequest($legacy, $this->staff());

        $this->assertSame([true, 425000], [$case->isHistorical(), $case->quoted_centavos]);
        $this->expectExceptionMessage('Only the owner');
        $this->cases()->close($case, $this->staff(), 'Camp decided the guest gets nothing.');
    }

    public function test_recording_a_date_move_cannot_quietly_close_an_earlier_terms_promise(): void
    {
        $booking = $this->booking(['status' => 'cancelled_by_camp'], policyVersion: null);
        $payment = $this->receipt($booking, 4250, 'paid', 'auto', 'full');
        $case = $this->cases()->openFromLegacyRequest($this->legacyRequest($booking, $payment, ['refund_percentage' => 100], 'camp_force_majeure'), $this->staff());
        $case = $this->cases()->classify($case, BookingRefund::REASON_CAMP_OPERATIONAL, 'no', $this->staff(), 'Boat engine failure confirmed');

        $this->actingAs($this->staff())->post($this->caseUrl($case, 'choice'), ['guest_choice' => 'reschedule', 'note' => 'Guest moved to Dec 19'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Only the owner'));
        $this->actingAs($this->staff('owner'))->post($this->caseUrl($case, 'choice', 'owner'), ['guest_choice' => 'reschedule', 'note' => 'ok'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'at least 10'));
        $this->assertSame(BookingRefund::AWAITING_GUEST_CHOICE, $case->fresh()->status);

        $this->post($this->caseUrl($case, 'choice', 'owner'), ['guest_choice' => 'reschedule', 'note' => 'Guest emailed: moving to Dec 19, gives up the refund.'])
            ->assertSessionHas('success');
        $this->assertSame([BookingRefund::CLOSED_NO_REFUND, 425000], [$case->fresh()->status, $case->fresh()->quoted_centavos]);
    }

    public function test_open_refund_case_cannot_be_bypassed_through_the_legacy_queue_or_cancellation_forfeit(): void
    {
        $booking = $this->booking(['status' => 'cancellation_requested']);
        $payment = $this->receipt($booking, 2000);
        $legacy = $this->legacyRequest($booking, $payment, ['refund_percentage' => 0]);
        $request = CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 0, 'status' => 'pending', 'policy_version' => $booking->refund_policy_version]);
        $this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL);

        $this->actingAs($this->staff());
        $this->post("/admin/payments/refunds/{$legacy->id}/forfeit", ['forfeit_reason' => 'cancellation_outside_policy_window'])->assertSessionHas('error', fn ($m) => str_contains($m, 'is open'));
        $this->post("/admin/payments/refunds/{$legacy->id}/reject", ['notes' => 'Not eligible'])->assertSessionHas('error', fn ($m) => str_contains($m, 'is open'));
        $this->post("/admin/bookings/requests/cancellation/{$request->id}/approve", ['action_type' => 'forfeit'])->assertSessionHas('error', fn ($m) => str_contains($m, 'is open'));

        $this->assertSame(['pending', 'pending', 'paid'], [$legacy->fresh()->status, $request->fresh()->status, $payment->fresh()->status]);
    }

    // =================================================================== H-2: unclear outcomes stay reserved

    public static function unclearAnswers(): array
    {
        return [
            'timeout' => [new ConnectionException('Synthetic timeout'), RefundAttempt::UNKNOWN],
            'HTTP 500' => [[500, ['errors' => [['code' => 'server_error']]]], RefundAttempt::UNKNOWN],
            'already refunded' => [[400, ['errors' => [['code' => 'parameter_above_maximum', 'detail' => 'Amount is above the refundable amount']]]], RefundAttempt::UNKNOWN],
            'provider pending' => ['pending', RefundAttempt::PROVIDER_PENDING],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unclearAnswers')]
    public function test_staff_cannot_release_an_unclear_refund_for_a_second_post(mixed $answer, string $status): void
    {
        $posts = [];
        $gets = [];
        [$case, $attempt, $booking] = $this->caseWithOpenAttempt($answer, $posts);
        $this->fakeRefundLookups([], $gets); // PayMongo knows no such refund id: 404
        $this->assertSame($status, $attempt->status);
        $admin = $this->staff();
        $this->actingAs($admin);

        // The old "mark failed" form no longer exists; free text alone is refused; a 404 lookup proves nothing
        $this->post($this->caseUrl($case, "attempts/{$attempt->id}/resolve"), ['outcome' => 'failed', 'evidence' => 'Not in the PayMongo dashboard'])->assertNotFound();
        if ($attempt->provider_refund_id === null) {
            $this->post($this->caseUrl($case, "attempts/{$attempt->id}/verify"), ['note' => 'Not in the PayMongo dashboard'])->assertSessionHas('error');
        }
        $this->post($this->caseUrl($case, "attempts/{$attempt->id}/verify"), ['provider_refund_id' => $attempt->provider_refund_id ? '' : 'ref_typed_by_staff', 'note' => 'PayMongo shows no refund'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'inconclusive'));
        $this->post($this->caseUrl($case, 'execute'));

        $this->assertCount(1, $posts, 'Never a second refund POST for the same money.');
        $this->assertSame($status, $attempt->fresh()->status);
        $this->assertSame(1, RefundAttempt::count());
        $position = app(RefundLedger::class)->position($booking);
        $this->assertSame([200000, 0, 0], [$position['reserved'], $position['refundable'], $position['refunded']], 'Still reserved, not refunded.');
        $this->assertStringContainsString('unverified staff note', $attempt->fresh()->evidence_note);
        $this->assertSame(BookingRefund::APPROVED, $case->fresh()->status);
    }

    public function test_fresh_and_stale_submissions_are_never_settled_by_free_text(): void
    {
        $posts = [];
        $gets = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $this->fakeRefundLookups(['ref_typed_by_staff' => [503, []]], $gets);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $payment = $this->receipt($booking, 2000);
        $case = $this->cases()->approve($this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL), 200000, $this->staff(), 'ok');
        $attempt = RefundAttempt::create(['booking_refund_id' => $case->id, 'payment_id' => $payment->id, 'operation_id' => (string) Str::uuid(),
            'method' => 'paymongo', 'amount_centavos' => 200000, 'status' => RefundAttempt::SUBMITTING, 'submitted_at' => now()]);
        $this->actingAs($this->staff());

        $this->post($this->caseUrl($case, "attempts/{$attempt->id}/verify"), ['provider_refund_id' => 'ref_typed_by_staff'])->assertSessionHas('error', fn ($m) => str_contains($m, 'moments ago'));
        $this->travel(11)->minutes();
        $this->post($this->caseUrl($case, "attempts/{$attempt->id}/verify"), ['provider_refund_id' => 'ref_typed_by_staff', 'note' => 'nothing there'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'inconclusive'));
        $this->post($this->caseUrl($case, 'execute'));

        $this->assertSame([], $posts);
        $this->assertSame(RefundAttempt::SUBMITTING, $attempt->fresh()->status);
        $this->assertSame(['ref_typed_by_staff'], $gets);
    }

    public function test_a_concurrent_provider_update_during_verification_is_never_overwritten(): void
    {
        $posts = [];
        [, $attempt] = $this->caseWithOpenAttempt([500, []], $posts);
        // While our lookup is in flight, a signed webhook settles the attempt as succeeded
        \Illuminate\Support\Facades\Http::fake(function ($request) use ($attempt) {
            if ($request->method() !== 'GET') {
                return null;
            }
            RefundAttempt::whereKey($attempt->id)->update(['status' => RefundAttempt::SUCCEEDED, 'provider_refund_id' => 'ref_from_webhook', 'resolution_source' => RefundAttempt::SOURCE_PROVIDER_WEBHOOK]);

            return \Illuminate\Support\Facades\Http::response(['data' => ['id' => 'ref_other', 'type' => 'refund', 'attributes' => $this->providerRefund($attempt, ['status' => 'failed'])]]);
        });

        $result = $this->cases()->verifyAttempt($attempt, 'ref_other', $this->staff(), 'checking');

        $this->assertStringStartsWith('inconclusive: the outcome changed meanwhile', $result);
        $this->assertSame([RefundAttempt::SUCCEEDED, 'ref_from_webhook'], [$attempt->fresh()->status, $attempt->fresh()->provider_refund_id]);
    }

    public function test_provider_confirmed_failure_releases_the_amount_for_one_new_attempt(): void
    {
        $posts = [];
        $gets = [];
        [$case, $attempt] = $this->caseWithOpenAttempt([500, []], $posts);
        $this->fakeRefundLookups(['ref_failed_1' => $this->providerRefund($attempt, ['status' => 'failed'])], $gets);

        $this->assertSame(RefundAttempt::FAILED, $this->cases()->verifyAttempt($attempt, 'ref_failed_1', $this->staff(), 'found it'));
        $this->assertSame([RefundAttempt::SOURCE_PROVIDER_VERIFICATION, 'ref_failed_1'], [$attempt->fresh()->resolution_source, $attempt->fresh()->provider_refund_id]);

        app(RefundExecutor::class)->execute($case, $this->staff());
        $this->assertCount(2, $posts, 'Only after PayMongo itself reported failure.');
        $this->assertSame(BookingRefund::COMPLETED, $case->fresh()->status);
    }

    // =================================================================== M-1: no unverified success

    public static function lookupMismatches(): array
    {
        return [
            'unknown id (404)' => [null, 'PayMongo lookup gave no answer (http_404)'],
            'lookup timeout' => ['timeout', 'PayMongo lookup gave no answer (no_response)'],
            'wrong payment' => [['payment_id' => 'pay_someone_else'], 'different payment'],
            'wrong amount' => [['amount' => 199999], 'amount differs'],
            'wrong currency' => [['currency' => 'USD'], 'currency is not PHP'],
            'live refund on a test key' => [['livemode' => true], 'test/live mode differs'],
            'not our operation' => [['notes' => 'Refund made in the dashboard'], 'not sent for this operation'],
            'unknown status' => [['status' => 'reversed'], 'not a known outcome'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lookupMismatches')]
    public function test_a_staff_entered_refund_id_is_not_proof_unless_paymongo_confirms_everything(mixed $override, string $why): void
    {
        $posts = [];
        $gets = [];
        [$case, $attempt, $booking] = $this->caseWithOpenAttempt([500, []], $posts);
        $lookups = match (true) {
            $override === null => [],
            $override === 'timeout' => ['ref_claimed' => new ConnectionException('Synthetic timeout')],
            default => ['ref_claimed' => $this->providerRefund($attempt, $override)],
        };
        $this->fakeRefundLookups($lookups, $gets);

        $result = $this->cases()->verifyAttempt($attempt, 'ref_claimed', $this->staff(), 'Saw it in the dashboard');

        $this->assertStringContainsString($why, $result);
        $this->assertSame([RefundAttempt::UNKNOWN, null, null], [$attempt->fresh()->status, $attempt->fresh()->provider_refund_id, $attempt->fresh()->resolution_source]);
        $this->assertSame(0, app(RefundLedger::class)->position($booking)['refunded']);
        $this->assertSame(BookingRefund::APPROVED, $case->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'REFUND_VERIFICATION_INCONCLUSIVE']);
    }

    public static function confirmedStatuses(): array
    {
        return [
            'pending' => ['pending', RefundAttempt::PROVIDER_PENDING, 0, BookingRefund::APPROVED],
            'processing' => ['processing', RefundAttempt::PROVIDER_PENDING, 0, BookingRefund::APPROVED],
            'failed' => ['failed', RefundAttempt::FAILED, 0, BookingRefund::APPROVED],
            'succeeded' => ['succeeded', RefundAttempt::SUCCEEDED, 200000, BookingRefund::COMPLETED],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmedStatuses')]
    public function test_a_matching_paymongo_refund_is_recorded_with_its_own_status(string $providerStatus, string $status, int $refunded, string $caseStatus): void
    {
        $posts = [];
        $gets = [];
        [$case, $attempt, $booking] = $this->caseWithOpenAttempt([500, []], $posts);
        $this->fakeRefundLookups(['ref_real_1' => $this->providerRefund($attempt, ['status' => $providerStatus])], $gets);

        $this->assertSame($status, $this->cases()->verifyAttempt($attempt, 'ref_real_1', $this->staff(), 'Found it'));

        $fresh = $attempt->fresh();
        $this->assertSame([$status, 'ref_real_1', RefundAttempt::SOURCE_PROVIDER_VERIFICATION], [$fresh->status, $fresh->provider_refund_id, $fresh->resolution_source]);
        $this->assertNotNull($fresh->verified_at);
        $this->assertSame($refunded, app(RefundLedger::class)->position($booking)['refunded']);
        $this->assertSame($caseStatus, $case->fresh()->status);
        $this->assertCount(1, $posts, 'Verification never sends a refund.');
    }

    public function test_a_refund_id_already_linked_elsewhere_is_rejected_and_offline_payouts_stay_separate(): void
    {
        $posts = [];
        $gets = [];
        [, $attempt] = $this->caseWithOpenAttempt([500, []], $posts);
        RefundAttempt::create(['payment_id' => $attempt->payment_id, 'operation_id' => (string) Str::uuid(), 'method' => 'external', 'amount_centavos' => 100,
            'status' => RefundAttempt::SUCCEEDED, 'provider_refund_id' => 'ref_taken', 'resolution_source' => RefundAttempt::SOURCE_PROVIDER_WEBHOOK]);
        $this->fakeRefundLookups(['ref_taken' => $this->providerRefund($attempt)], $gets);

        $this->assertStringContainsString('already linked', $this->cases()->verifyAttempt($attempt, 'ref_taken', $this->staff(), 'x'));
        $this->assertSame(RefundAttempt::UNKNOWN, $attempt->fresh()->status);

        // Cash payouts: documented by staff (separate path); a PayMongo lookup is not used for them
        $cash = $this->booking(['status' => 'cancelled_by_camp']);
        $this->receipt($cash, 2000, 'completed', null);
        $cashCase = $this->cases()->approve($this->campCase($cash, BookingRefund::REASON_CAMP_OPERATIONAL), 200000, $this->staff(), 'ok');
        app(RefundExecutor::class)->execute($cashCase, $this->staff());
        $offline = RefundAttempt::where('booking_refund_id', $cashCase->id)->sole();
        try {
            $this->cases()->verifyAttempt($offline, 'ref_taken', $this->staff(), 'x');
            $this->fail('Offline payout verified through PayMongo.');
        } catch (\RuntimeException) {
        }
        $this->cases()->confirmOfflinePayout($offline, $this->staff(), 'Cash returned at the office, receipt A-7');
        $this->assertSame([RefundAttempt::OFFLINE_CONFIRMED, RefundAttempt::SOURCE_OFFLINE_STAFF], [$offline->fresh()->status, $offline->fresh()->resolution_source]);
        $this->assertSame(BookingRefund::COMPLETED, $cashCase->fresh()->status);
    }

    // =================================================================== M-2: unresolved weather balance

    public function test_weather_case_with_unresolved_balance_stays_open_after_the_downpayment_is_refunded(): void
    {
        $posts = [];
        $this->fakeRefunds(['succeeded'], $posts);
        $booking = $this->booking(['status' => 'cancelled_by_camp']);
        $dp = $this->receipt($booking, 2000);
        $balance = $this->receipt($booking, 2250, 'completed', null, 'balance_settlement');
        $legacy = $this->legacyRequest($booking, $balance, ['refund_percentage' => 0]);
        $cancel = CancellationRequest::create(['booking_id' => $booking->id, 'calculated_refund_amount' => 0, 'status' => 'pending', 'policy_version' => $booking->refund_policy_version]);
        $case = $this->cases()->approve($this->campCase($booking, BookingRefund::REASON_WEATHER_PRE_DIVE), 200000, $this->staff(), 'Downpayment back');
        $this->assertSame('unresolved', $case->balance_review);
        $owner = $this->staff('owner');
        $this->actingAs($owner);

        $this->post($this->caseUrl($case, 'execute', 'owner'))->assertSessionHas('success');

        $fresh = $case->fresh();
        $this->assertSame([BookingRefund::APPROVED, 'unresolved'], [$fresh->status, $fresh->balance_review], 'Downpayment paid back; case not completed.');
        $this->assertTrue($fresh->confirmedPartRefundedBalanceOpen());
        $position = app(RefundLedger::class)->position($booking);
        $this->assertSame([425000, 200000, 225000], [$position['paid'], $position['refunded'], $position['refundable']], 'The balance amount is kept, not zeroed.');
        $this->get($this->caseUrl($case, '', 'owner'))->assertOk()->assertSee('Downpayment refunded; balance under review');
        $this->get('/owner/payments/refund-cases')->assertOk()->assertSee('Downpayment refunded; balance under review');

        // Nothing can close or clear it: not the case close (even the owner), the legacy queue or a cancellation forfeit
        $this->post($this->caseUrl($case, 'close', 'owner'), ['note' => 'Client said keep the balance.'])->assertSessionHas('error', fn ($m) => str_contains($m, 'still unresolved'));
        $this->post("/owner/payments/refunds/{$legacy->id}/forfeit", ['forfeit_reason' => 'custom_administrative_decision'])->assertSessionHas('error');
        $this->post("/owner/payments/refunds/{$legacy->id}/reject", ['notes' => 'Balance is kept by the camp.'])->assertSessionHas('error');
        $this->post("/owner/bookings/requests/cancellation/{$cancel->id}/approve", ['action_type' => 'forfeit'])->assertSessionHas('error');

        $this->assertSame([BookingRefund::APPROVED, 'unresolved'], [$case->fresh()->status, $case->fresh()->balance_review]);
        $this->assertSame(['completed', 'pending', 'pending'], [$balance->fresh()->status, $legacy->fresh()->status, $cancel->fresh()->status]);
        $this->assertCount(1, $posts);
        $this->assertSame(200000, $posts[0]['amount'], 'Only the downpayment was sent.');
        $this->assertSame($dp->paymongo_payment_id, $posts[0]['payment_id']);
        Mail::assertNothingOutgoing();  // no "fully refunded / settled" message
    }
}
