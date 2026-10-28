<?php

namespace Tests\Feature\Capacity;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Services\BatchManagementService;
use App\Services\Booking\DateNotOperatingException;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\FinalizationResult;
use App\Services\SlotReservationService;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * G1: a camp date that was cancelled or rescheduled (or completed) never becomes bookable again by itself. Checkout
 * start refuses it before any hold or payment; a payment verified afterwards is kept as paid-unallocated under its
 * payment exception; no booking path creates a new batch on it; only an explicit staff action reopens it. All data is
 * synthetic; PayMongo is faked.
 */
class NonOperatingDateTest extends TestCase
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
        $this->setUpCheckoutWorld(); // capacity 6, self::DATE = 2026-12-19 (Saturday), now = 2026-11-02
    }

    private function batchOn(string $date, string $status): Batch
    {
        return Batch::create(['batch_code' => 'G1-' . Str::upper(Str::random(6)), 'name' => 'G1 ' . $date, 'start_date' => $date,
            'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'status' => $status, 'lifecycle_status' => $status]);
    }

    /** Close a date the way staff do (status change through the service), so the cascade runs too. */
    private function closeDate(Batch $batch, string $status): void
    {
        app(BatchManagementService::class)->updateStatus($batch, $status, $this->staffUser('owner'), 'Synthetic G1 test',
            $status === 'cancelled_by_camp' ? ['reason_category' => 'camp_operational', 'activity_started' => 'no'] : []);
    }

    private function startCheckout(string $date = self::DATE, int $pax = 2): array
    {
        $end = Carbon::parse($date)->addDay()->toDateString();

        return app(CheckoutService::class)->start([
            'start_date' => $date, 'end_date' => $end, 'pax_count' => $pax, 'class_type' => 'discovery',
            'pickup_option' => 'own', 'total_amount' => 9200, 'downpayment_amount' => 4000,
        ], [
            'booking' => ['class_type' => 'discovery', 'is_certified_diver' => false, 'start_date' => $date, 'end_date' => $end, 'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false],
            'contact' => ['name' => 'Casey Fixture', 'email' => self::CONTACT_EMAIL, 'phone' => '09170000001', 'facebook' => null],
            'guardian' => [],
            'participants' => array_map(fn ($i) => ['name' => "Synthetic Diver {$i}", 'birthdate' => null, 'gender' => 'prefer_not_to_say', 'age' => 30,
                'health_condition' => null, 'swimmer_status' => 'swimmer', 'price_per_person' => 4250.0], range(1, $pax)),
            'pricing' => ['pricing_version' => 1, 'subtotal' => 4250.0 * $pax, 'adjustments' => [],
                'fees' => ['lgu' => 300.0 * $pax, 'environmental' => 50.0 * $pax, 'carpool' => 0.0, 'boat_dive' => 0.0], 'total_amount' => 9200, 'downpayment_amount' => 4000],
        ]);
    }

    private function counts(): array
    {
        return [Booking::count(), Batch::count(), Payment::count(), PaymentException::count(), CheckoutIntent::count()];
    }

    // ------------------------------------------------------------ operating-date rule

    public function test_the_operating_date_rule(): void
    {
        $service = app(BatchManagementService::class);
        $this->assertTrue($service->operatingDate(self::DATE)->isOperating(), 'no batch history: the first booking may create its batch');

        $cancelled = $this->batchOn(self::DATE, 'cancelled_by_camp');
        $this->assertFalse($service->operatingDate(self::DATE)->isOperating());
        $this->assertSame('cancelled_by_camp', $service->operatingDate(self::DATE)->closedStatus());

        $closed = ['rescheduled', 'completed', 'cancelled', 'archived_legacy_value']; // closed statuses, and an unknown one (fail closed)
        foreach ($closed as $k => $status) {
            $date = Carbon::parse(self::DATE)->addWeeks(1 + $k)->toDateString();
            $this->batchOn($date, $status);
            $this->assertFalse($service->operatingDate($date)->isOperating(), $status);
        }
        foreach (['open', 'confirmed', 'full'] as $k => $status) { // a full camp still runs (approved G1 compatibility rule)
            $date = Carbon::parse(self::DATE)->addWeeks(10 + $k)->toDateString();
            $batch = $this->batchOn($date, $status);
            $this->assertTrue($service->operatingDate($date)->isOperating(), $status);
            $this->assertSame($batch->id, $service->operatingDate($date)->batch->id, $status);
        }

        $reopened = $this->batchOn(self::DATE, 'confirmed'); // an explicit staff batch for the date
        $this->assertTrue($service->operatingDate(self::DATE)->isOperating());
        $this->assertSame($reopened->id, $service->operatingDate(self::DATE)->batch->id);
        $this->assertSame($cancelled->id, $service->operatingDate(self::DATE)->closedBy->id, 'history kept, deterministically');
    }

    public function test_shared_batch_creation_never_recreates_a_closed_date(): void
    {
        foreach (['cancelled_by_camp', 'rescheduled', 'completed'] as $i => $status) {
            $date = Carbon::parse(self::DATE)->addWeeks($i)->toDateString();
            $this->batchOn($date, $status);
            $before = Batch::count();
            try {
                app(BatchManagementService::class)->findOrCreateBatchRecord($date);
                $this->fail("a batch was created on a {$status} date");
            } catch (DateNotOperatingException $e) {
                $this->assertStringContainsString('not running', $e->getMessage());
            }
            $this->assertSame($before, Batch::count());
        }
        $fresh = app(BatchManagementService::class)->findOrCreateBatchRecord('2027-02-06');
        $this->assertTrue($fresh['created'], 'a date with no history still gets its first batch');
    }

    // ------------------------------------------------------------ a full batch still operates; capacity decides

    public function test_a_full_batch_is_reused_never_duplicated_and_preferred_after_an_open_one(): void
    {
        $full = $this->batchOn(self::DATE, 'full');
        $batches = Batch::count();

        $found = app(BatchManagementService::class)->findOrCreateBatchRecord(self::DATE);

        $this->assertSame([$full->id, false], [$found['batch']->id, $found['created']], 'the full batch is the date\'s batch');
        $this->assertSame($batches, Batch::count(), 'no second batch for the date');

        $later = $this->batchOn(self::DATE, 'confirmed'); // a date that already has both keeps its pre-G1 choice
        $this->assertSame($later->id, app(BatchManagementService::class)->operatingDate(self::DATE)->batch->id);
    }

    public function test_checkout_start_on_a_full_batch_is_decided_by_capacity_not_closed_date(): void
    {
        $full = $this->batchOn(self::DATE, 'full');
        $this->paxBooking(6, 'confirmed', self::DATE, $full->id); // no place left (capacity 6)

        $refused = $this->startCheckout();
        $this->assertSame([false, 422], [$refused['ok'], $refused['status']]);
        $this->assertStringContainsString('Capacity limit reached', $refused['message']);
        $this->assertStringNotContainsString('not running', $refused['message']);

        $date = Carbon::parse(self::DATE)->addWeek()->toDateString();
        $this->batchOn($date, 'full'); // labelled full, but places remain
        $this->fakeCheckoutSessionCreated('cs_test_g1_full');
        $this->assertTrue($this->startCheckout($date)['ok']);
    }

    public function test_finalization_on_a_full_batch_follows_capacity(): void
    {
        $full = $this->batchOn(self::DATE, 'full');
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->addMinutes(10)]);
        $batches = Batch::count();

        $this->assertSame(FinalizationResult::FINALIZED, $this->finalizeIntent($intent)->outcome);
        $this->assertSame($full->id, (int) Booking::where('checkout_intent_id', $intent->id)->sole()->batch_id, 'placed in the existing full batch');
        $this->assertSame($batches, Batch::count());

        $date = Carbon::parse(self::DATE)->addWeek()->toDateString();
        $packed = $this->batchOn($date, 'full');
        $this->paxBooking(5, 'confirmed', $date, $packed->id);
        $late = $this->checkoutIntent(30, ['start_date' => $date, 'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'hold_expires_at' => now()->subMinute()]);
        $result = $this->finalizeIntent($late);
        $this->assertSame([FinalizationResult::PAID_UNALLOCATED, 'capacity_unavailable'], [$result->outcome, $result->reason], 'capacity, not a closed date');
    }

    public function test_legacy_payment_on_a_full_batch_follows_capacity(): void
    {
        $full = $this->batchOn(self::DATE, 'full');
        $roomy = $this->unpaidBooking(2, self::DATE, $full->id);
        $this->payByWebhook($roomy, 'pay_g1_full_ok')->assertOk();
        $this->assertSame(['confirmed', $full->id], [$roomy->fresh()->status, (int) $roomy->fresh()->batch_id]);

        $date = Carbon::parse(self::DATE)->addWeek()->toDateString();
        $packed = $this->batchOn($date, 'full');
        $this->paxBooking(6, 'confirmed', $date, $packed->id);
        $late = $this->unpaidBooking(2, $date, $packed->id);
        $this->payByWebhook($late, 'pay_g1_full_late')->assertOk();
        $this->assertSame('paid_unallocated', $late->fresh()->status);
        $this->assertSame(Payment::REVIEW_CAPACITY_UNAVAILABLE, Payment::where('booking_id', $late->id)->where('status', 'paid')->sole()->review_reason);
    }

    public function test_b2_allocation_onto_a_full_batch_passes_the_date_rule_and_stays_fail_closed_on_instructors(): void
    {
        $intent = $this->unallocatedIntent('capacity_unavailable');
        $exception = PaymentException::where('checkout_intent_id', $intent->id)->sole();
        $target = '2027-01-23'; // not the fixture's full date (2027-01-09)
        $this->batchOn($target, 'full'); // places remain
        $choices = app(\App\Services\Payment\GuestChoiceService::class);
        $choice = $choices->record($exception, $this->staffUser('admin'), [
            'choice' => 'alternative_date', 'requested_start_date' => $target, 'requested_end_date' => Carbon::parse($target)->addDay()->toDateString(),
            'evidence_channel' => 'email', 'evidence_at' => now()->subHour()->toIso8601String(), 'evidence_reference' => 'synthetic-thread-g1',
            'evidence_summary' => 'Synthetic: guest asked for another weekend.', 'contact_match_method' => 'email_address', 'contact_match_outcome' => 'matched']);
        $choice = $choices->verify($choice, $this->staffUser('owner'), 'Synthetic: confirmed with the booking email.', 0);

        $attempt = app(\App\Services\Payment\ExceptionResolutionService::class)->allocateAlternativeDate($choice, $this->staffUser('admin'), 1);

        $this->assertSame(['blocked', 'instructor_schedule_unconfirmed'], [$attempt->outcome, $attempt->reason], 'not date_not_operating; instructors still fail closed');
        $this->assertSame('open', $exception->fresh()->status);
    }

    // ------------------------------------------------------------ checkout start

    public function test_checkout_start_is_refused_on_a_cancelled_or_rescheduled_date_with_no_hold_or_payment(): void
    {
        foreach (['cancelled_by_camp', 'rescheduled'] as $i => $status) {
            $date = Carbon::parse(self::DATE)->addWeeks($i)->toDateString();
            $this->closeDate($this->batchOn($date, 'confirmed'), $status);
            $before = $this->counts();

            $result = $this->startCheckout($date);

            $this->assertSame([false, 422], [$result['ok'], $result['status']], $status);
            $this->assertStringContainsString('not running', $result['message']);
            $this->assertSame($before, $this->counts(), 'no intent, batch or payment');
        }
        Http::assertNothingSent();
    }

    public function test_checkout_start_on_a_date_with_no_batch_history_still_works(): void
    {
        $this->fakeCheckoutSessionCreated('cs_test_g1_new');

        $this->assertTrue($this->startCheckout()['ok']);
        $this->assertSame(1, CheckoutIntent::count());
    }

    public function test_checkout_start_works_again_once_staff_explicitly_reopen_the_date(): void
    {
        $batch = $this->batchOn(self::DATE, 'confirmed');
        $this->closeDate($batch, 'cancelled_by_camp');
        $this->assertFalse($this->startCheckout()['ok']);

        $this->actingAs($this->staffUser('admin'))->post("/admin/batches/{$batch->id}/status", ['status' => 'confirmed', 'note' => 'Synthetic: camp reopened by staff'])
            ->assertSessionHas('success');
        $this->assertSame('confirmed', $batch->fresh()->status);
        $this->fakeCheckoutSessionCreated('cs_test_g1_reopened');

        $this->assertTrue($this->startCheckout()['ok'], 'reopened explicitly by staff');
    }

    public function test_only_owners_and_admins_can_reopen_a_closed_date(): void
    {
        $batch = $this->batchOn(self::DATE, 'cancelled_by_camp');

        $this->actingAs($this->staffUser('coach'))->post("/admin/batches/{$batch->id}/status", ['status' => 'confirmed'])->assertForbidden();
        auth()->logout();
        $this->post("/admin/batches/{$batch->id}/status", ['status' => 'confirmed'])->assertRedirect();

        $this->assertSame('cancelled_by_camp', $batch->fresh()->status);
        $this->assertFalse(app(BatchManagementService::class)->operatingDate(self::DATE)->isOperating());
    }

    // ------------------------------------------------------------ verified payment finalization

    public function test_a_payment_verified_after_cancellation_or_rescheduling_is_kept_as_paid_unallocated(): void
    {
        foreach (['cancelled_by_camp', 'rescheduled'] as $i => $status) {
            $date = Carbon::parse(self::DATE)->addWeeks($i)->toDateString();
            $batch = $this->batchOn($date, 'confirmed');
            $intent = $this->checkoutIntent(5, ['start_date' => $date, 'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'hold_expires_at' => now()->addMinutes(10)]);
            $this->closeDate($batch, $status); // while the guest is paying
            $batches = Batch::count();

            $result = $this->finalizeIntent($intent);

            $this->assertSame([FinalizationResult::PAID_UNALLOCATED, 'date_not_operating'], [$result->outcome, $result->reason], $status);
            $intent->refresh();
            $this->assertSame([CheckoutIntent::STATUS_PAID_UNALLOCATED, 'date_not_operating', null], [$intent->status, $intent->unallocated_reason, $intent->booking_id]);
            $this->assertFalse($intent->hasLiveHold(), 'no capacity kept');
            $receipt = Payment::where('checkout_intent_id', $intent->id)->sole();
            $this->assertSame([null, 400000], [$receipt->booking_id, $receipt->amountCentavos()], 'the verified payment is kept, unapplied');
            $exception = PaymentException::where('checkout_intent_id', $intent->id)->sole();
            $this->assertSame([PaymentException::KIND_INTENT_UNALLOCATED, 'open', $receipt->id], [$exception->kind, $exception->status, (int) $exception->primary_payment_id]);
            $this->assertSame(0, Booking::where('checkout_intent_id', $intent->id)->count());
            $this->assertSame($batches, Batch::count(), 'no batch recreated on the closed date');

            $again = $this->finalizeIntent($intent->fresh());
            $this->assertSame([FinalizationResult::PAID_UNALLOCATED, 'date_not_operating'], [$again->outcome, $again->reason], 'replay');
            $this->assertSame([1, 1, 0], [Payment::where('checkout_intent_id', $intent->id)->count(), PaymentException::where('checkout_intent_id', $intent->id)->count(),
                Booking::where('checkout_intent_id', $intent->id)->count()], 'no duplicate receipt, exception or booking');
        }
    }

    public function test_finalization_on_an_operating_date_is_unchanged(): void
    {
        $this->batchOn(self::DATE, 'confirmed');
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->addMinutes(10)]);

        $this->assertSame(FinalizationResult::FINALIZED, $this->finalizeIntent($intent)->outcome);
        $this->assertSame(1, Booking::where('checkout_intent_id', $intent->id)->count());
    }

    // ------------------------------------------------------------ legacy booking and payment

    public function test_a_pending_legacy_booking_whose_batch_was_rescheduled_is_not_confirmed_by_its_payment(): void
    {
        $batch = $this->batchOn(self::DATE, 'confirmed');
        $booking = $this->unpaidBooking(2, self::DATE, $batch->id);
        $this->closeDate($batch, 'rescheduled');
        $this->assertSame('pending_downpayment', $booking->fresh()->status, 'reschedule skips bookings without a place');

        $this->payByWebhook($booking, 'pay_g1_resched')->assertOk();

        $booking->refresh();
        $this->assertSame('paid_unallocated', $booking->status, 'never confirmed onto the rescheduled batch');
        $payment = Payment::where('booking_id', $booking->id)->where('status', 'paid')->sole();
        $this->assertSame(Payment::REVIEW_DATE_NOT_OPERATING, $payment->review_reason);
        $exception = PaymentException::where('booking_id', $booking->id)->sole();
        $this->assertSame([PaymentException::KIND_LEGACY_UNALLOCATED, 'open', $payment->id], [$exception->kind, $exception->status, (int) $exception->primary_payment_id]);

        $this->payByWebhook($booking, 'pay_g1_resched')->assertOk(); // replay
        $this->assertSame([1, 1], [Payment::where('booking_id', $booking->id)->where('status', 'paid')->count(), PaymentException::where('booking_id', $booking->id)->count()]);
    }

    public function test_a_pending_legacy_booking_on_a_cancelled_date_keeps_the_existing_late_payment_handling(): void
    {
        $batch = $this->batchOn(self::DATE, 'confirmed');
        $booking = $this->unpaidBooking(2, self::DATE, $batch->id);
        $this->closeDate($batch, 'cancelled_by_camp');

        $this->payByWebhook($booking, 'pay_g1_cancel')->assertOk();

        $this->assertSame('cancelled_by_camp', $booking->fresh()->status);
        $this->assertSame(Payment::REVIEW_PAID_AFTER_CANCELLATION, Payment::where('booking_id', $booking->id)->where('status', 'paid')->sole()->review_reason);
        $this->assertSame(PaymentException::KIND_PAID_AFTER_CANCELLATION, PaymentException::where('booking_id', $booking->id)->sole()->kind);
    }

    public function test_a_pending_legacy_booking_on_an_operating_date_is_still_confirmed(): void
    {
        $batch = $this->batchOn(self::DATE, 'confirmed');
        $booking = $this->unpaidBooking(2, self::DATE, $batch->id);

        $this->payByWebhook($booking, 'pay_g1_ok')->assertOk();

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(0, PaymentException::count());
    }

    public function test_the_public_legacy_booking_form_refuses_a_closed_date(): void
    {
        config(['checkout.intents_enabled' => false]);
        $this->mock(WeatherSafetyService::class, fn ($mock) => $mock->shouldReceive('getForecast')->andReturn([
            'risk_level' => 'safe', 'overall_classification' => 'Safe', 'is_bookable' => true, 'day1' => ['classification' => 'Safe'], 'day2' => ['classification' => 'Safe']]));
        $this->batchOn(self::DATE, 'cancelled_by_camp');
        $before = $this->counts();

        $response = $this->postJson(route('booking.store'), [
            'class_type' => 'discovery', 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'participants' => [['first_name' => 'Synthetic', 'last_name' => 'Alpha', 'age' => 30, 'health_condition' => 'None', 'swimmer_status' => 'swimmer']],
            'contact_first_name' => 'Casey', 'contact_last_name' => 'Fixture', 'contact_email' => self::CONTACT_EMAIL, 'contact_phone' => '09170000001',
            'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false, 'has_agreed_to_terms' => true, 'confirmation_ack' => true, 'payment_method' => 'paymongo',
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('not running', (string) $response->json('message'));
        $this->assertSame($before, $this->counts(), 'no booking, batch, payment or hold');
        $this->assertSame(0, app(SlotReservationService::class)->getActiveHoldPaxCount(self::DATE));
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ staff paths

    public function test_a_manual_booking_onto_a_closed_date_is_refused_and_writes_nothing(): void
    {
        $this->batchOn(self::DATE, 'cancelled_by_camp');
        $before = $this->counts();

        $this->actingAs($this->staffUser('admin'))->post('/admin/bookings', [
            'class_type' => 'discovery', 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'participants' => [['name' => 'Walk In A', 'age' => 30]],
            'contact_name' => 'Walk In', 'contact_email' => 'walkin@example.test', 'contact_phone' => '09170000009',
            'pickup_option' => 'own', 'payment_method' => 'cash', 'payment_stage' => 'downpayment', 'payment_reference' => '', 'admin_notes' => '',
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'not running'));

        $this->assertSame($before, $this->counts());
    }

    public function test_restoring_or_moving_a_booking_onto_a_closed_date_is_refused(): void
    {
        $batch = $this->batchOn(self::DATE, 'confirmed');
        $guestCancelled = $this->paxBooking(2, 'cancelled_by_guest', self::DATE, $batch->id);
        $this->receipt($guestCancelled, 2000); // its downpayment is on record, so only the date can refuse the restore
        $this->closeDate($batch, 'cancelled_by_camp');
        $batches = Batch::count();
        $staff = $this->staffUser('admin');

        $this->actingAs($staff)->patch("/admin/bookings/{$guestCancelled->id}/status", ['status' => 'confirmed', 'note' => 'Synthetic restore'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not running'));
        $this->assertSame('cancelled_by_guest', $guestCancelled->fresh()->status);

        $elsewhere = $this->paxBooking(2, 'confirmed', '2027-01-09', $this->batchOn('2027-01-09', 'confirmed')->id);
        $this->actingAs($staff)->put("/admin/bookings/{$elsewhere->id}", [
            'start_date' => self::DATE, 'end_date' => '2026-12-20', 'contact_name' => $elsewhere->contact_name, 'contact_email' => $elsewhere->contact_email,
            'contact_phone' => '09170000001', 'edit_reason' => 'Synthetic move',
            'participants' => $elsewhere->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'age' => 30])->all(),
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'not running'));
        $this->assertSame('2027-01-09', $elsewhere->fresh()->start_date->toDateString());
        $this->assertSame($batches + 1, Batch::count(), 'only the test\'s own origin batch was added; none on the closed date');
    }

    // ------------------------------------------------------------ guest communication

    private const NEUTRAL = "We've received your payment, but we couldn't confirm your reservation for the selected dates. Our team will review your booking and contact you about the available next steps. Please keep your payment reference for your records. You do not need to pay again.";

    /** A paid checkout that got no booking, for either reason. */
    private function unallocatedIntent(string $reason): CheckoutIntent
    {
        $date = $reason === 'date_not_operating' ? self::DATE : '2027-01-09';
        if ($reason === 'date_not_operating') {
            $this->batchOn($date, 'cancelled_by_camp');
        } else {
            $this->paxBooking(6, 'confirmed', $date);
        }
        $intent = $this->checkoutIntent(5, ['start_date' => $date, 'end_date' => Carbon::parse($date)->addDay()->toDateString(), 'hold_expires_at' => now()->addMinutes(10)]);
        $this->assertSame($reason, $this->finalizeIntent($intent)->reason);

        return $intent->fresh();
    }

    public function test_the_guest_payment_received_email_is_neutral_for_both_reasons_and_leaks_nothing(): void
    {
        foreach (['capacity_unavailable', 'date_not_operating'] as $reason) {
            $intent = $this->unallocatedIntent($reason);

            $html = (new \App\Mail\CheckoutPaymentReceivedMail($intent))->render();

            $this->assertStringContainsString(self::NEUTRAL, $html, $reason);
            $this->assertStringContainsString($intent->reference, $html);
            foreach (['filled up', 'were taken', 'capacity_unavailable', 'date_not_operating', 'not running', 'cancelled', 'refund', $intent->paymongo_payment_id, 'pay_test_final_'] as $absent) {
                $this->assertStringNotContainsString($absent, $html, "{$reason}: {$absent}");
            }
            $this->assertDoesNotMatchRegularExpression('/\bPIN\b/i', $html);
        }
    }

    public function test_the_guest_status_page_is_neutral_for_both_reasons(): void
    {
        foreach (['capacity_unavailable', 'date_not_operating'] as $reason) {
            $intent = $this->unallocatedIntent($reason);

            $page = $this->get($this->signedCheckoutUrl($intent, 'checkout.cancel'))->assertOk();

            $page->assertSee(self::NEUTRAL)->assertDontSee('were taken')->assertDontSee('filled up')->assertDontSee($reason);
        }
    }

    public function test_the_no_need_to_pay_again_reassurance_appears_only_after_a_verified_payment(): void
    {
        $pending = $this->checkoutIntent(5, ['hold_expires_at' => now()->addMinutes(10)]); // unpaid
        $this->get($this->signedCheckoutUrl($pending, 'checkout.cancel'))->assertOk()->assertDontSee('You do not need to pay again.');
        $failed = $this->checkoutIntent(5, ['status' => CheckoutIntent::STATUS_FAILED]);
        $this->get($this->signedCheckoutUrl($failed, 'checkout.cancel'))->assertOk()->assertDontSee('You do not need to pay again.');

        $legacy = $this->unpaidBooking(2, '2027-02-06');
        Http::fake(["*/checkout_sessions/cs_{$legacy->id}" => Http::response(['data' => ['id' => "cs_{$legacy->id}", 'attributes' => ['payments' => []]]])]);
        $this->get(\App\Services\Gateways\PayMongoGateway::signedReturnUrl('paymongo.success', $legacy))
            ->assertRedirect()->assertSessionHas('info', fn ($m) => ! str_contains($m, 'You do not need to pay again.'));
        $this->assertSame('pending_downpayment', $legacy->fresh()->status);

        $paid = $this->unallocatedIntent('date_not_operating'); // verified and recorded
        $this->get($this->signedCheckoutUrl($paid, 'checkout.cancel'))->assertOk()->assertSee('You do not need to pay again.');
    }

    public function test_the_unallocated_outcome_queues_the_same_messages_once_whatever_the_reason(): void
    {
        $this->staffUser('owner'); // staff alerts go to active owners and admins
        foreach (['capacity_unavailable', 'date_not_operating'] as $reason) {
            $intent = $this->unallocatedIntent($reason);
            $notifier = app(\App\Services\Checkout\CheckoutNotifier::class);

            $notifier->afterOutcome($intent);
            $notifier->afterOutcome($intent->fresh()); // a replayed outcome

            $kinds = \App\Models\CheckoutNotification::where('checkout_intent_id', $intent->id)->orderBy('kind')->pluck('kind')->all();
            $this->assertSame([\App\Models\CheckoutNotification::CUSTOMER_PAYMENT_RECEIVED, \App\Models\CheckoutNotification::STAFF_PAID_UNALLOCATED],
                array_values(array_unique($kinds)), $reason);
            $this->assertSame(1, \App\Models\CheckoutNotification::where('checkout_intent_id', $intent->id)
                ->where('kind', \App\Models\CheckoutNotification::CUSTOMER_PAYMENT_RECEIVED)->count(), "{$reason}: one guest message");
        }
    }

    public function test_the_legacy_return_page_is_neutral_when_the_payment_cannot_be_placed(): void
    {
        $batch = $this->batchOn(self::DATE, 'confirmed');
        $booking = $this->unpaidBooking(2, self::DATE, $batch->id);
        $this->closeDate($batch, 'rescheduled');
        Http::fake(["*/checkout_sessions/cs_{$booking->id}" => Http::response(['data' => ['id' => "cs_{$booking->id}", 'attributes' => [
            'payments' => [['id' => 'pay_g1_return', 'attributes' => $this->paidAttributes()]]]]])]);

        $this->get(\App\Services\Gateways\PayMongoGateway::signedReturnUrl('paymongo.success', $booking))
            ->assertRedirect()->assertSessionHas('info', self::NEUTRAL);

        $this->assertSame('paid_unallocated', $booking->fresh()->status);
    }

    // ------------------------------------------------------------ counting is unchanged

    public function test_capacity_counting_of_rescheduled_cancellation_requested_completed_and_no_show_bookings_is_unchanged(): void
    {
        foreach (['rescheduled', 'cancellation_requested', 'completed', 'no_show', 'confirmed'] as $status) {
            $booking = $this->booking(['status' => $status, 'start_date' => '2027-03-06', 'end_date' => '2027-03-07']);
            BookingParticipant::create(['booking_id' => $booking->id, 'name' => "Guest {$status}", 'age' => 30, 'price_per_person' => 4250]);
        }
        foreach (['pending_downpayment', 'paid_unallocated', 'cancelled_by_camp'] as $status) {
            $booking = $this->booking(['status' => $status, 'start_date' => '2027-03-06', 'end_date' => '2027-03-07']);
            BookingParticipant::create(['booking_id' => $booking->id, 'name' => "Guest {$status}", 'age' => 30, 'price_per_person' => 4250]);
        }

        $this->assertSame(5, app(SlotReservationService::class)->getConfirmedPaxCount('2027-03-06'), 'C1, C2, C4: counted as before');
    }
}
