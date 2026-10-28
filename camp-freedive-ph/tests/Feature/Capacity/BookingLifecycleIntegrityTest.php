<?php

namespace Tests\Feature\Capacity;

use App\Mail\BookingConfirmedMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\BookingRefund;
use App\Models\CheckoutNotification;
use App\Models\Payment;
use App\Models\RefundAttempt;
use App\Models\RescheduleRequest;
use App\Services\BatchManagementService;
use App\Services\Gateways\PayMongoGateway;
use App\Services\Refunds\RefundCaseService;
use App\Services\SlotReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II A: capacity and booking lifecycle integrity. Every path that gives a booking a place checks capacity under
 * the shared lock; a payment that arrives when the date is full (or for a cancelled booking) is kept and flagged, never
 * overbooked; staff status changes are validated transitions. PayMongo is faked (Http::fake); mail is faked.
 */
class BookingLifecycleIntegrityTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;
    use RefundFixtures;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Mail::fake();
        $this->date = now()->addDays(30)->toDateString();
    }

    private function slots(): SlotReservationService
    {
        return app(SlotReservationService::class);
    }

    private function dayAfter(string $date): string
    {
        return Carbon::parse($date)->addDay()->toDateString();
    }

    private function batch(?string $date = null): Batch
    {
        $date ??= $this->date;

        return Batch::create(['batch_code' => 'B-' . Str::upper(Str::random(6)), 'name' => 'Batch ' . $date, 'start_date' => $date,
            'end_date' => $this->dayAfter($date), 'status' => 'confirmed', 'lifecycle_status' => 'confirmed']);
    }

    /** A booking on $date with $pax participants. */
    private function pax(int $pax, string $status = 'confirmed', ?string $date = null, ?int $batchId = null): Booking
    {
        $date ??= $this->date;
        $booking = $this->booking(['status' => $status, 'start_date' => $date, 'end_date' => $this->dayAfter($date), 'batch_id' => $batchId]);
        for ($i = 0; $i < $pax; $i++) {
            BookingParticipant::create(['booking_id' => $booking->id, 'name' => 'Guest ' . chr(65 + $i % 26), 'age' => 30, 'price_per_person' => 4250]);
        }

        return $booking->fresh();
    }

    /** A legacy reservation waiting for its ₱2,000 downpayment, with its pending PayMongo row. */
    private function unpaid(int $pax = 5, ?string $date = null): Booking
    {
        $booking = $this->pax($pax, 'pending_downpayment', $date);
        Payment::create(['booking_id' => $booking->id, 'payment_method' => 'paymongo', 'transaction_id' => 'PAY-' . Str::upper(Str::random(8)),
            'amount' => 2000, 'payment_type' => 'downpayment', 'status' => 'pending', 'paymongo_resource_id' => "cs_{$booking->id}"]);

        return $booking;
    }

    private function paidAttributes(int $centavos = 200000, array $overrides = []): array
    {
        return array_merge(['status' => 'paid', 'amount' => $centavos, 'currency' => 'PHP', 'livemode' => false, 'fee' => 5000], $overrides);
    }

    private function paidEvent(Booking $booking, string $payId, array $attributes, ?string $eventId = null): array
    {
        return ['data' => ['id' => $eventId ?? "evt_{$payId}", 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => [
            'id' => "cs_{$booking->id}", 'type' => 'checkout_session', 'attributes' => ['amount' => $attributes['amount'] ?? 0, 'payment_method_type' => 'gcash',
                'metadata' => ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number],
                'payments' => [['id' => $payId, 'attributes' => $attributes]]]]]]];
    }

    /** PayMongo shows the checkout session of $booking paid with $attributes. */
    private function fakePaidSession(Booking $booking, string $payId, array $attributes): void
    {
        Http::fake(["*/checkout_sessions/cs_{$booking->id}" => Http::response(['data' => ['id' => "cs_{$booking->id}", 'attributes' => [
            'payments' => [['id' => $payId, 'attributes' => $attributes]]]]])]);
    }

    private function returnTo(Booking $booking)
    {
        return $this->get(PayMongoGateway::signedReturnUrl('paymongo.success', $booking));
    }

    private function confirmations(Booking $booking): int
    {
        return CheckoutNotification::where('booking_id', $booking->id)->where('kind', CheckoutNotification::CUSTOMER_BOOKING_CONFIRMED)->count();
    }

    // ===================================================================== A-D, N-P: legacy payments

    public function test_A_late_paid_webhook_on_a_full_date_keeps_the_payment_and_never_overbooks(): void
    {
        $late = $this->unpaid(5);
        $this->pax(45);

        $this->postSignedPayMongoWebhook($this->paidEvent($late, 'pay_late_a', $this->paidAttributes()))->assertOk();

        $payment = Payment::where('paymongo_payment_id', 'pay_late_a')->sole();
        $this->assertSame('paid_unallocated', $late->fresh()->status);
        $this->assertSame(['paid', Payment::REVIEW_CAPACITY_UNAVAILABLE], [$payment->status, $payment->review_reason]);
        $this->assertSame(45, $this->slots()->getConfirmedPaxCount($this->date));
        $this->assertSame(0, $this->confirmations($late));
        Mail::assertNothingOutgoing();
        $this->assertDatabaseHas('audit_logs', ['action' => 'PAYMONGO_PAYMENT_UNALLOCATED']);
    }

    public function test_B_signed_return_on_a_full_date_keeps_the_payment_and_sends_no_confirmation(): void
    {
        $late = $this->unpaid(5);
        $this->pax(45);
        $this->fakePaidSession($late, 'pay_late_b', $this->paidAttributes());

        $this->returnTo($late)->assertRedirect(route('manage.show', ['booking_number' => $late->booking_number]))
            ->assertSessionHas('info', "We've received your payment, but we couldn't confirm your reservation for the selected dates. Our team will review your booking "
                . 'and contact you about the available next steps. Please keep your payment reference for your records. You do not need to pay again.');

        $this->assertSame('paid_unallocated', $late->fresh()->status);
        $this->assertSame(1, Payment::where('paymongo_payment_id', 'pay_late_b')->count());
        $this->assertSame(45, $this->slots()->getConfirmedPaxCount($this->date));
        Mail::assertNothingOutgoing();
    }

    public function test_C_O_P_webhook_return_race_and_reloads_apply_once_with_one_durable_confirmation(): void
    {
        $booking = $this->unpaid(2);
        $attributes = $this->paidAttributes();
        $this->fakePaidSession($booking, 'pay_once', $attributes);

        // Webhook first (guest closed the browser), then a duplicate delivery, then the return page twice
        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'pay_once', $attributes))->assertOk();
        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'pay_once', $attributes, 'evt_other_delivery'))->assertOk();
        $this->returnTo($booking)->assertRedirect(route('booking.create', ['confirmed' => $booking->booking_number]));
        $this->returnTo($booking)->assertRedirect(route('booking.create', ['confirmed' => $booking->booking_number]));

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->where('status', 'paid')->count(), 'one payment row');
        $this->assertSame(1, $this->confirmations($booking), 'one durable confirmation row, recorded by the webhook alone');
        Mail::assertSent(BookingConfirmedMail::class, 1);
        $this->assertSame(CheckoutNotification::SENT, CheckoutNotification::where('booking_id', $booking->id)->value('status'));
    }

    public function test_C_return_page_first_then_webhook_is_also_applied_once(): void
    {
        $booking = $this->unpaid(2);
        $attributes = $this->paidAttributes();
        $this->fakePaidSession($booking, 'pay_return_first', $attributes);

        $this->returnTo($booking);
        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'pay_return_first', $attributes))->assertOk();

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->where('status', 'paid')->count());
        $this->assertSame(1, $this->confirmations($booking));
        Mail::assertSent(BookingConfirmedMail::class, 1);
    }

    public function test_D_late_payment_on_a_cancelled_booking_is_kept_flagged_and_visible_to_staff(): void
    {
        $booking = $this->unpaid(2);
        $booking->update(['status' => 'cancelled_by_guest']);

        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'pay_after_cancel', $this->paidAttributes()))->assertOk();

        $payment = Payment::where('paymongo_payment_id', 'pay_after_cancel')->sole();
        $this->assertSame('cancelled_by_guest', $booking->fresh()->status);
        $this->assertSame(['paid', Payment::REVIEW_PAID_AFTER_CANCELLATION], [$payment->status, $payment->review_reason]);
        Mail::assertNothingOutgoing();
        $this->actingAs($this->staff())->get('/admin/payments/exceptions')->assertOk()
            ->assertSee($booking->booking_number)->assertSee('Paid after the booking was cancelled');
    }

    public static function unverifiablePayments(): array
    {
        return [
            'wrong currency' => [['currency' => 'USD']],
            'live payment on a test key' => [['livemode' => true]],
            'not paid' => [['status' => 'pending']],
            'no amount' => [['amount' => null]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unverifiablePayments')]
    public function test_N_unverifiable_payments_change_nothing_on_webhook_and_return(array $override): void
    {
        $booking = $this->unpaid(2);
        $attributes = $this->paidAttributes(200000, $override);
        $this->fakePaidSession($booking, 'pay_bad', $attributes);

        $this->postSignedPayMongoWebhook($this->paidEvent($booking, 'pay_bad', $attributes))->assertOk();
        $this->returnTo($booking);

        $this->assertSame('pending_downpayment', $booking->fresh()->status);
        $this->assertSame(0, Payment::where('paymongo_payment_id', 'pay_bad')->count());
        Mail::assertNothingOutgoing();
    }

    public function test_N_wrong_amount_is_kept_and_flagged_but_never_confirms(): void
    {
        $booking = $this->unpaid(2);
        $this->fakePaidSession($booking, 'pay_short', $this->paidAttributes(100000));

        $this->returnTo($booking);

        $this->assertSame('pending_downpayment', $booking->fresh()->status);
        $this->assertSame(Payment::REVIEW_AMOUNT_MISMATCH, Payment::where('paymongo_payment_id', 'pay_short')->value('review_reason'));
        Mail::assertNothingOutgoing();
    }

    public function test_return_page_without_paymongo_credentials_never_confirms(): void
    {
        config(['paymongo.secret_key' => '']);
        $booking = $this->unpaid(2);

        $this->returnTo($booking)->assertSessionHas('info', fn ($m) => str_contains($m, 'still waiting'));

        $this->assertSame('pending_downpayment', $booking->fresh()->status);
        $this->assertSame('pending', Payment::where('booking_id', $booking->id)->value('status'));
    }

    public function test_P_confirmation_is_withheld_if_the_booking_was_cancelled_before_delivery(): void
    {
        $booking = $this->unpaid(2);
        app(\App\Services\Checkout\CheckoutNotifier::class)->recordForBooking($booking); // recorded, then the booking changed
        $notification = CheckoutNotification::where('booking_id', $booking->id)->sole();

        app(\App\Services\Checkout\CheckoutNotifier::class)->deliver($notification->id);

        $this->assertSame([CheckoutNotification::FAILED, 'BookingNotConfirmed'], [$notification->fresh()->status, $notification->fresh()->last_error]);
        Mail::assertNothingOutgoing();
    }

    // ===================================================================== E-F: paying again

    public function test_E_F_no_new_payment_for_a_cancelled_or_already_confirmed_booking_or_a_full_date(): void
    {
        Http::fake(['*/checkout_sessions' => Http::response(['data' => ['id' => 'cs_again', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/again']]])]);
        foreach (['cancelled_by_camp', 'cancelled_by_guest', 'confirmed', 'paid_unallocated'] as $status) {
            $booking = $this->pax(1, $status);
            $this->withSession(['auth_booking_id' => $booking->id])->post("/booking/{$booking->id}/paymongo/checkout")
                ->assertSessionHas('error', fn ($m) => str_contains($m, 'not waiting for a payment'));
        }
        $late = $this->unpaid(5);
        $this->pax(45);
        $this->withSession(['auth_booking_id' => $late->id])->post("/booking/{$late->id}/paymongo/checkout")
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'now full'));

        Http::assertNothingSent();
        $this->assertSame(1, Payment::where('status', 'pending')->count(), 'only the original pending row');
    }

    // ===================================================================== G-L, Q: staff paths

    public function test_G_walk_in_respects_guests_holding_places_in_checkout_and_writes_nothing_when_full(): void
    {
        $this->pax(40);
        $this->slots()->acquireHold($this->date, 'CFP-HOLDING', 5);
        $before = [Booking::count(), Payment::count(), Batch::count()];

        $this->actingAs($this->staff())->post('/admin/bookings', [
            'class_type' => 'discovery', 'start_date' => $this->date, 'end_date' => $this->dayAfter($this->date),
            'participants' => array_map(fn ($i) => ['name' => 'Walk In ' . chr(64 + $i), 'age' => 30], range(1, 5)),
            'contact_name' => 'Walk In', 'contact_email' => 'walkin@example.test', 'contact_phone' => '09170000009',
            'pickup_option' => 'own', 'payment_method' => 'cash', 'payment_stage' => 'downpayment', 'payment_reference' => '', 'admin_notes' => '',
        ])->assertSessionHas('error', fn ($m) => str_contains($m, 'No room'));

        $this->assertSame($before, [Booking::count(), Payment::count(), Batch::count()], 'nothing written');
    }

    public function test_H_Q_date_edit_into_a_full_date_changes_nothing_and_a_free_date_moves_dates_and_batch_together(): void
    {
        $origin = $this->batch(now()->addDays(60)->toDateString());
        $booking = $this->pax(5, 'confirmed', now()->addDays(60)->toDateString(), $origin->id);
        $this->pax(45);
        $this->actingAs($this->staff());
        $form = fn (string $date, string $name) => [
            'start_date' => $date, 'end_date' => $this->dayAfter($date), 'contact_name' => $name, 'contact_email' => $booking->contact_email,
            'contact_phone' => '09170000001', 'edit_reason' => 'Guest asked',
            'participants' => $booking->participants->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'age' => 30])->all(),
        ];

        $this->put("/admin/bookings/{$booking->id}", $form($this->date, 'Renamed Guest'))->assertSessionHas('error', fn ($m) => str_contains($m, 'No room'));
        $fresh = $booking->fresh();
        $this->assertSame([now()->addDays(60)->toDateString(), $origin->id, 'Synthetic Guest'], [$fresh->start_date->toDateString(), $fresh->batch_id, $fresh->contact_name],
            'dates, batch and details all unchanged');

        $free = now()->addDays(90)->toDateString();
        $this->put("/admin/bookings/{$booking->id}", $form($free, 'Renamed Guest'))->assertSessionHas('success');
        $moved = $booking->fresh();
        $this->assertSame([$free, 'Renamed Guest'], [$moved->start_date->toDateString(), $moved->contact_name]);
        $this->assertSame($free, $moved->batch->start_date->toDateString(), 'the batch follows the date');
    }

    public function test_I_reschedule_approval_into_a_full_date_changes_nothing(): void
    {
        $this->batch();
        $booking = $this->pax(5, 'reschedule_requested', now()->addDays(60)->toDateString());
        $this->pax(45);
        $request = RescheduleRequest::create(['booking_id' => $booking->id, 'current_start_date' => $booking->start_date, 'current_end_date' => $booking->end_date,
            'requested_start_date' => $this->date, 'requested_end_date' => $this->dayAfter($this->date), 'reason' => 'test', 'status' => 'pending']);

        $this->actingAs($this->staff())->post("/admin/bookings/requests/reschedule/{$request->id}/approve", ['admin_notes' => 'ok'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No room'));

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(['reschedule_requested', now()->addDays(60)->toDateString()], [$booking->fresh()->status, $booking->fresh()->start_date->toDateString()]);
        $this->assertSame(45, $this->slots()->getConfirmedPaxCount($this->date));
        Mail::assertNothingOutgoing();
    }

    public function test_J_cancelled_booking_cannot_be_restored_while_its_refund_is_open_or_in_payout(): void
    {
        $booking = $this->pax(2, 'cancelled_by_camp');
        $payment = $this->receipt($booking, 2000);
        $case = app(RefundCaseService::class)->approve($this->campCase($booking, BookingRefund::REASON_CAMP_OPERATIONAL), 200000, $this->staff(), 'ok');
        RefundAttempt::create(['booking_refund_id' => $case->id, 'payment_id' => $payment->id, 'operation_id' => (string) Str::uuid(),
            'method' => 'paymongo', 'amount_centavos' => 200000, 'status' => RefundAttempt::UNKNOWN]);

        $this->actingAs($this->staff())->patch("/admin/bookings/{$booking->id}/status", ['status' => 'confirmed', 'note' => 'restore'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'refund case'));

        $this->assertSame('cancelled_by_camp', $booking->fresh()->status);
    }

    public function test_K_crafted_status_change_cannot_confirm_an_unpaid_booking_or_skip_capacity(): void
    {
        $unpaid = $this->pax(3, 'pending_downpayment');
        $this->actingAs($this->staff());

        $this->patch("/admin/bookings/{$unpaid->id}/status", ['status' => 'confirmed'])->assertSessionHas('error', fn ($m) => str_contains($m, 'No downpayment'));
        $this->assertSame('pending_downpayment', $unpaid->fresh()->status);

        // With the cash downpayment recorded and a free place it is allowed; a full date still refuses
        $this->receipt($unpaid, 6000, 'completed', null);
        $full = $this->pax(2, 'pending_downpayment');
        $this->receipt($full, 4000, 'completed', null);
        $this->pax(42);
        $this->patch("/admin/bookings/{$unpaid->id}/status", ['status' => 'confirmed', 'note' => ''])->assertSessionHas('success');
        $this->patch("/admin/bookings/{$full->id}/status", ['status' => 'confirmed', 'note' => ''])->assertSessionHas('error', fn ($m) => str_contains($m, 'No room'));
        $this->assertSame([45, 'pending_downpayment'], [$this->slots()->getConfirmedPaxCount($this->date), $full->fresh()->status]);
    }

    public function test_K_unsupported_or_premature_transitions_are_refused(): void
    {
        $this->actingAs($this->staff());
        $requested = $this->pax(1, 'cancellation_requested');
        $future = $this->pax(1);
        $paid = $this->pax(1);
        $this->receipt($paid, 2000);

        $this->patch("/admin/bookings/{$requested->id}/status", ['status' => 'cancelled_by_guest'])->assertSessionHas('error', fn ($m) => str_contains($m, 'Booking Requests'));
        $this->patch("/admin/bookings/{$future->id}/status", ['status' => 'completed'])->assertSessionHas('error', fn ($m) => str_contains($m, 'has not come yet'));
        $this->patch("/admin/bookings/{$paid->id}/status", ['status' => 'cancelled_by_guest'])->assertSessionHas('error', fn ($m) => str_contains($m, "guest's cancellation request"));

        $this->assertSame(['cancellation_requested', 'confirmed', 'confirmed'], [$requested->fresh()->status, $future->fresh()->status, $paid->fresh()->status]);
    }

    /**
     * Batch 3-II B1 changed the second half: a paid booking without a place whose payment is still under review is no
     * longer placed by a staff status change (it waits for the guest's choice and staff verification, B2). The review
     * stays unresolved and nothing changes.
     */
    public function test_staff_camp_cancellation_opens_a_refund_case_and_a_held_paid_unallocated_booking_is_not_placed_by_staff(): void
    {
        $paid = $this->pax(2);
        $this->receipt($paid, 2000);
        $waiting = $this->unpaid(2);
        $this->postSignedPayMongoWebhook($this->paidEvent($waiting, 'pay_wait', $this->paidAttributes()))->assertOk();
        $this->assertSame('confirmed', $waiting->fresh()->status); // room was free
        $waiting->update(['status' => 'paid_unallocated']);           // as if the date had been full
        Payment::where('paymongo_payment_id', 'pay_wait')->update(['review_reason' => Payment::REVIEW_CAPACITY_UNAVAILABLE, 'review_flagged_at' => now()]);
        $this->actingAs($this->staff());

        $this->patch("/admin/bookings/{$paid->id}/status", ['status' => 'cancelled_by_camp', 'note' => 'Boat engine failure'])->assertSessionHas('success');
        $this->assertSame([BookingRefund::AWAITING_STAFF_REVIEW, 'unknown'], [BookingRefund::where('booking_id', $paid->id)->value('status'), BookingRefund::where('booking_id', $paid->id)->value('reason_category')]);

        $this->patch("/admin/bookings/{$waiting->id}/status", ['status' => 'confirmed', 'note' => 'A place opened up'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'awaiting review') && str_contains($m, "guest's choice"));
        $this->assertSame('paid_unallocated', $waiting->fresh()->status);
        $this->assertSame(1, Payment::awaitingReview()->where('booking_id', $waiting->id)->count(), 'the review stays open');
    }

    public function test_L_bookings_never_join_a_batch_of_another_date(): void
    {
        $other = $this->batch(now()->addDays(90)->toDateString());
        $booking = $this->pax(2, 'confirmed', null, $this->batch()->id);
        $this->actingAs($this->staff());

        $this->post("/admin/batches/{$other->id}/move-booking", ['booking_id' => $booking->id, 'target_batch_id' => $other->id, 'reason' => 'x'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Change the booking dates first'));
        $this->assertNotSame($other->id, $booking->fresh()->batch_id);

        $batches = Batch::count();
        $this->post('/admin/batches', ['start_date' => now()->addDays(90)->toDateString(), 'end_date' => now()->addDays(91)->toDateString(), 'booking_ids' => [$booking->id]])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'cannot join'));
        $this->assertSame($batches, Batch::count(), 'no batch created');
    }

    public function test_M_find_or_create_returns_one_batch_per_date(): void
    {
        $service = app(BatchManagementService::class);
        $first = $service->findOrCreateBatchRecord($this->date, $this->dayAfter($this->date));
        $second = $service->findOrCreateBatchRecord($this->date, $this->dayAfter($this->date));

        $this->assertTrue($first['created']);
        $this->assertSame([false, $first['batch']->id], [$second['created'], $second['batch']->id]);
    }

    public function test_batch_completion_finishes_only_trips_that_were_going(): void
    {
        $batch = $this->batch(now()->subDays(2)->toDateString());
        $going = $this->pax(1, 'confirmed', now()->subDays(2)->toDateString(), $batch->id);
        $unpaid = $this->pax(1, 'pending_downpayment', now()->subDays(2)->toDateString(), $batch->id);
        $cancelled = $this->pax(1, 'cancelled_by_camp', now()->subDays(2)->toDateString(), $batch->id);

        app(BatchManagementService::class)->updateStatus($batch, 'completed', $this->staff());

        $this->assertSame(['completed', 'pending_downpayment', 'cancelled_by_camp'], [$going->fresh()->status, $unpaid->fresh()->status, $cancelled->fresh()->status]);
    }
}
