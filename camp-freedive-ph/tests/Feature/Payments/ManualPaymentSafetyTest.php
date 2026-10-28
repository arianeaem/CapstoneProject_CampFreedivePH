<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\PaymentStatusLog;
use App\Models\ProviderPaymentIdentity;
use App\Models\RefundRequest;
use App\Services\BatchManagementService;
use App\Services\Payment\ManualPaymentService;
use App\Services\Payment\RefundService;
use App\Services\Refunds\RefundLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * B4: staff-recorded (offline) payments. A manual payment is the recording staff member's attestation: owner/admin only,
 * for a payable booking, never more than is due, a unique reference that cannot pass for a PayMongo id, a stale-form
 * check, provenance kept (created_by, no provider identity), and it never confirms a booking by itself. Confirmation
 * still needs the downpayment, room and an operating date. Held money is never given a manual payment or forfeited.
 * No PayMongo call is made anywhere here. Synthetic data only.
 */
class ManualPaymentSafetyTest extends TestCase
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
        $this->setUpCheckoutWorld(); // capacity 6 on self::DATE, now = 2026-11-02
    }

    private function form(Booking $booking, array $overrides = []): array
    {
        return array_merge(['booking_id' => $booking->id, 'amount' => 2000, 'payment_method' => 'cash', 'payment_type' => 'downpayment',
            'transaction_id' => 'CASH-' . Str::upper(Str::random(6)), 'notes' => 'Synthetic: cash at camp', 'expected_balance' => (string) $booking->fresh()->balance_amount], $overrides);
    }

    private function record(Booking $booking, string $role = 'admin', array $overrides = []): Payment
    {
        return app(ManualPaymentService::class)->record($booking, $this->staffUser($role), $this->form($booking, $overrides));
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

    // ------------------------------------------------------------ authorization

    public function test_owner_and_admin_record_through_the_screen_coach_and_guest_cannot(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', self::DATE);

        $this->actingAs($this->staffUser('coach'))->post('/admin/payments', $this->form($booking))->assertForbidden();
        auth()->logout();
        $this->post('/admin/payments', $this->form($booking))->assertRedirect();
        $this->assertSame(0, Payment::count());

        $this->actingAs($this->staffUser('owner'))->post('/owner/payments', $this->form($booking, ['amount' => 1000, 'transaction_id' => 'CASH-OWNER-1']))->assertRedirect();
        $this->actingAs($this->staffUser('admin'))->post('/admin/payments', $this->form($booking, ['amount' => 1000, 'transaction_id' => 'CASH-ADMIN-1']))->assertRedirect();
        $this->assertSame(2, Payment::where('booking_id', $booking->id)->count());
        Http::assertNothingSent();
    }

    public function test_an_inactive_or_coach_account_cannot_record_through_the_service(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', self::DATE);
        $inactive = $this->staffUser('admin');
        $inactive->forceFill(['status' => 'inactive'])->save();

        $this->refused(fn () => app(ManualPaymentService::class)->record($booking, $inactive, $this->form($booking)), 'Only an active owner or admin');
        $this->refused(fn () => $this->record($booking, 'coach'), 'Only an active owner or admin');
        $this->assertSame(0, Payment::count());
    }

    public function test_csrf_is_enforced(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', self::DATE);
        $this->withMiddleware()->actingAs($this->staffUser('owner'));
        $this->app['env'] = 'production';

        $this->assertSame(419, $this->call('POST', '/owner/payments', $this->form($booking))->status());
        $this->assertSame(0, Payment::count());
    }

    // ------------------------------------------------------------ provenance and references

    public function test_a_manual_payment_keeps_its_provenance_and_never_becomes_a_provider_payment(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', self::DATE);
        $staff = $this->staffUser('admin');

        $payment = app(ManualPaymentService::class)->record($booking, $staff, $this->form($booking));

        $this->assertSame([(int) $staff->id, 'cash', 'completed', null], [(int) $payment->created_by, $payment->payment_method, $payment->status, $payment->paymongo_payment_id]);
        $this->assertSame(0, ProviderPaymentIdentity::count(), 'no provider identity for a staff attestation');
        $this->assertStringContainsString('not provider-verified', PaymentStatusLog::where('payment_id', $payment->id)->sole()->note);
        $this->assertTrue(AuditLog::where('action', 'PAYMENT_RECORDED_MANUALLY')->where('description', 'like', '%not provider-verified%')->exists());
        $this->actingAs($staff)->get("/admin/payments/{$payment->id}")->assertOk()->assertSee('Recorded offline by staff user');
    }

    public function test_references_cannot_impersonate_paymongo_or_repeat_and_are_bounded(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', self::DATE);
        $this->record($booking, 'admin', ['amount' => 500, 'transaction_id' => 'BANK-REF-1']);

        foreach (['pay_abc123', 'cs_test_1', 'ref_9', ' PAY_X'] as $fake) {
            $this->refused(fn () => $this->record($booking, 'admin', ['amount' => 500, 'transaction_id' => $fake]), 'cannot look like a PayMongo');
        }
        $this->refused(fn () => $this->record($booking, 'admin', ['amount' => 500, 'transaction_id' => 'BANK-REF-1']), 'already recorded');
        $this->refused(fn () => $this->record($booking, 'admin', ['amount' => 500, 'transaction_id' => str_repeat('X', 65)]), 'at most 64');
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());

        $this->actingAs($this->staffUser('admin'))->post('/admin/payments', $this->form($booking, ['amount' => 500, 'transaction_id' => str_repeat('Y', 80)]))
            ->assertSessionHasErrors('transaction_id'); // a validation error, never a database error
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
    }

    public function test_the_walk_in_booking_payment_is_attributed_and_its_reference_checked(): void
    {
        $admin = $this->staffUser('admin');
        $form = fn (string $reference) => [
            'class_type' => 'discovery', 'start_date' => self::DATE, 'end_date' => '2026-12-20', 'participants' => [['name' => 'Walk In A', 'age' => 30]],
            'contact_name' => 'Walk In', 'contact_email' => 'walkin@example.test', 'contact_phone' => '09170000009', 'pickup_option' => 'own',
            'payment_method' => 'cash', 'payment_stage' => 'downpayment', 'payment_reference' => $reference, 'admin_notes' => ''];

        $this->actingAs($admin)->post('/admin/bookings', $form('pay_fake_123'))->assertSessionHasErrors('payment_reference');
        $this->assertSame(0, Booking::count());
        $this->actingAs($admin)->post('/admin/bookings', $form('WALKIN-CASH-1'))->assertRedirect();
        $this->assertSame((int) $admin->id, (int) Payment::where('transaction_id', 'WALKIN-CASH-1')->sole()->created_by);
        $this->actingAs($admin)->post('/admin/bookings', $form('WALKIN-CASH-1'))->assertSessionHasErrors('payment_reference');
        $this->assertSame(1, Booking::count(), 'a repeated reference creates no second booking');
    }

    // ------------------------------------------------------------ amounts, replays, stale forms

    public function test_never_more_than_is_still_due_and_never_zero(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', self::DATE); // total 4,250 (RefundFixtures), nothing received yet
        $this->refused(fn () => $this->record($booking, 'admin', ['amount' => 4250.01]), 'more than what is still due');
        $this->refused(fn () => $this->record($booking, 'admin', ['amount' => 0]), 'more than ₱0');

        $this->record($booking, 'admin', ['amount' => 4250]);
        $this->assertSame(0.0, (float) $booking->fresh()->balance_amount);
        $this->refused(fn () => $this->record($booking, 'admin', ['amount' => 1]), 'more than what is still due');
    }

    public function test_a_replayed_or_stale_form_is_refused_instead_of_recording_twice(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', self::DATE);
        $shown = (string) $booking->balance_amount;
        $admin = $this->staffUser('admin');

        app(ManualPaymentService::class)->record($booking, $admin, $this->form($booking, ['amount' => 1000, 'expected_balance' => $shown]));
        $this->refused(fn () => app(ManualPaymentService::class)->record($booking, $admin, $this->form($booking, ['amount' => 1000, 'expected_balance' => $shown])),
            'balance changed');
        $this->refused(fn () => app(ManualPaymentService::class)->record($booking, $admin, $this->form($booking, ['expected_balance' => null])), 'missing the balance');
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->count());
    }

    // ------------------------------------------------------------ booking status, capacity, G1

    public function test_only_payable_bookings_take_a_manual_payment(): void
    {
        foreach (['cancelled_by_camp', 'cancelled_by_guest', 'completed', 'no_show', 'cancellation_requested'] as $status) {
            $booking = $this->paxBooking(1, $status, self::DATE);
            $this->refused(fn () => $this->record($booking), 'waiting for its downpayment, confirmed or rescheduled');
        }
        $this->assertSame(0, Payment::count());
    }

    public function test_a_manual_payment_never_confirms_a_booking_and_confirmation_still_checks_room_and_date(): void
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $this->record($booking, 'admin', ['amount' => 2000]);
        $this->assertSame('pending_downpayment', $booking->fresh()->status, 'recorded, not confirmed');

        $this->fillDate(5); // 7 of 6 places if it were confirmed
        $this->actingAs($this->staffUser('admin'))->patch("/admin/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'No room'));
        $this->assertSame('pending_downpayment', $booking->fresh()->status);
    }

    public function test_confirmation_after_a_manual_payment_follows_g1_and_full_batch_rules(): void
    {
        $date = '2027-01-09';
        $batch = Batch::create(['batch_code' => 'B4-' . Str::upper(Str::random(5)), 'name' => 'B4', 'start_date' => $date, 'end_date' => '2027-01-10', 'status' => 'confirmed', 'lifecycle_status' => 'confirmed']);
        $booking = $this->unpaidBooking(2, $date, $batch->id);
        $this->record($booking, 'admin', ['amount' => 2000]);
        app(BatchManagementService::class)->updateStatus($batch, 'rescheduled', $this->staffUser('owner'), 'Synthetic reschedule');
        $this->actingAs($this->staffUser('admin'))->patch("/admin/bookings/{$booking->id}/status", ['status' => 'confirmed'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not running'));
        $this->assertSame('pending_downpayment', $booking->fresh()->status, 'never confirmed onto a rescheduled camp');

        $fullDate = '2027-01-23';
        $full = Batch::create(['batch_code' => 'B4F-' . Str::upper(Str::random(5)), 'name' => 'B4 full', 'start_date' => $fullDate, 'end_date' => '2027-01-24', 'status' => 'full', 'lifecycle_status' => 'full']);
        $roomy = $this->unpaidBooking(2, $fullDate, $full->id);
        $this->record($roomy, 'admin', ['amount' => 2000]);
        $this->actingAs($this->staffUser('admin'))->patch("/admin/bookings/{$roomy->id}/status", ['status' => 'confirmed'])->assertSessionHas('success');
        $this->assertSame(['confirmed', $full->id], [$roomy->fresh()->status, (int) $roomy->fresh()->batch_id], 'a full-labelled camp with room still runs');
    }

    // ------------------------------------------------------------ held money, refunds, exceptions

    public function test_held_money_gets_no_manual_payment_and_the_exception_stays_open(): void
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $this->fillDate(5);
        $this->payByWebhook($booking, 'pay_b4_held')->assertOk(); // paid after the date filled up: held
        $exception = PaymentException::where('booking_id', $booking->id)->sole();

        $this->refused(fn () => $this->record($booking->fresh()), 'waiting for its downpayment');
        $this->assertSame(['open', 'paid_unallocated'], [$exception->fresh()->status, $booking->fresh()->status]);
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->where('status', 'paid')->count());
    }

    public function test_held_or_approved_money_is_never_forfeited_through_the_legacy_queue(): void
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $this->fillDate(5);
        $this->payByWebhook($booking, 'pay_b4_forfeit')->assertOk();
        $payment = Payment::where('booking_id', $booking->id)->where('status', 'paid')->sole();
        $request = RefundRequest::create(['booking_id' => $booking->id, 'payment_id' => $payment->id, 'requested_by' => 'customer', 'reason' => 'Synthetic request',
            'status' => 'pending', 'requested_amount' => 2000, 'eligible_amount' => 0]);

        $this->refused(fn () => app(RefundService::class)->forfeit($request, $this->staffUser('owner'), 'late_cancellation', null), 'held by a payment exception');
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(2000.0 * 100, (float) app(RefundLedger::class)->paymentRow($payment->fresh())['refundable'], 'still fully refundable');
    }

    public function test_an_online_payment_after_a_manual_downpayment_is_held_as_paid_again_not_silently_kept(): void
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $this->record($booking, 'admin', ['amount' => 2000]); // cash at camp, booking still waiting
        $this->assertSame('pending_downpayment', $booking->fresh()->status);

        $this->payByWebhook($booking, 'pay_b4_double')->assertOk(); // the guest also paid the online link

        $this->assertSame('confirmed', $booking->fresh()->status, 'placed as usual (room checked)');
        $online = Payment::where('paymongo_payment_id', 'pay_b4_double')->sole();
        $this->assertSame(Payment::REVIEW_PAID_AGAIN, $online->review_reason);
        $this->assertSame([PaymentException::KIND_PAID_AGAIN, 'open'], [PaymentException::where('primary_payment_id', $online->id)->sole()->kind, PaymentException::where('primary_payment_id', $online->id)->sole()->status]);
        $this->assertSame(2, Payment::where('booking_id', $booking->id)->whereIn('status', ['paid', 'completed'])->count(), 'both receipts kept, nothing lost');
    }

    public function test_a_bookingless_receipt_takes_no_balance_settlement(): void
    {
        $this->fillDate(5);
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->subMinutes(5)]);
        $this->finalizeIntent($intent);
        $receipt = Payment::where('checkout_intent_id', $intent->id)->sole();

        $this->actingAs($this->staffUser('admin'))->post("/admin/payments/{$receipt->id}/settle-balance", ['amount' => 500, 'payment_method' => 'cash', 'expected_balance' => 0])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not applied to any booking'));
        $this->assertSame(1, Payment::count());
    }

    public function test_balance_settlement_uses_the_same_safeguards(): void
    {
        $booking = $this->paxBooking(2, 'confirmed', self::DATE);
        $first = $this->record($booking, 'admin', ['amount' => 2000]);
        $admin = $this->staffUser('admin');
        $url = "/admin/payments/{$first->id}/settle-balance";

        $this->actingAs($admin)->post($url, ['amount' => 9999, 'payment_method' => 'cash', 'expected_balance' => (string) $booking->fresh()->balance_amount])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'more than what is still due'));
        $this->actingAs($admin)->post($url, ['amount' => 2250, 'payment_method' => 'bpi_bank_transfer', 'transaction_id' => 'pay_spoof', 'expected_balance' => (string) $booking->fresh()->balance_amount])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'PayMongo'));
        $this->actingAs($admin)->post($url, ['amount' => 2250, 'payment_method' => 'bpi_bank_transfer', 'expected_balance' => (string) $booking->fresh()->balance_amount])
            ->assertSessionHas('success');
        $settled = Payment::where('booking_id', $booking->id)->where('payment_type', 'balance_settlement')->sole();
        $this->assertSame([(int) $admin->id, 0.0], [(int) $settled->created_by, (float) $booking->fresh()->balance_amount]);
        Http::assertNothingSent();
    }
}
