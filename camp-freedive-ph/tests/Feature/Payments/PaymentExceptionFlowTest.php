<?php

namespace Tests\Feature\Payments;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\ProviderIdentityConflict;
use App\Models\ProviderPaymentIdentity;
use App\Services\Booking\LegacyPaymentConfirmer;
use App\Services\Checkout\FinalizationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B1: verified money that cannot simply be applied becomes one receipt and one payment exception, from the
 * checkout finalizer (paid, no place) and from legacy payments (date full, cancelled, different amount, paid again).
 * Retries never duplicate anything, and nothing is refunded, allocated or resolved automatically.
 */
class PaymentExceptionFlowTest extends TestCase
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
        $this->setUpCheckoutWorld(); // capacity 6 on self::DATE; mail, queue and weather faked
    }

    public function test_a_paid_checkout_without_a_place_becomes_one_unapplied_receipt_with_one_exception(): void
    {
        $this->fillDate(5);
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->subMinutes(5)]);

        $result = $this->finalizeIntent($intent);
        $this->finalizeIntent($intent); // a retried webhook / return page / reconciliation

        $this->assertSame(FinalizationResult::PAID_UNALLOCATED, $result->outcome);
        $receipt = Payment::where('checkout_intent_id', $intent->id)->sole();
        $this->assertNull($receipt->booking_id);
        $this->assertSame(['paid', 400000, 'pay_test_final_' . $intent->id], [$receipt->status, $receipt->amountCentavos(), $receipt->paymongo_payment_id]);
        $identity = ProviderPaymentIdentity::sole();
        $this->assertSame([$intent->id, null, $receipt->id, 'PHP', false], [(int) $identity->checkout_intent_id, $identity->booking_id, (int) $identity->payment_id, $identity->currency, $identity->livemode]);

        $exception = PaymentException::sole();
        $this->assertSame([PaymentException::KIND_INTENT_UNALLOCATED, 'open', $receipt->id, $intent->id, null, 400000],
            [$exception->kind, $exception->status, (int) $exception->primary_payment_id, (int) $exception->checkout_intent_id, $exception->booking_id, $exception->amount_centavos]);
        $this->assertSame([$receipt->id], $exception->receipts()->pluck('payments.id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['payments' => 1, 'provider_payment_identities' => 1, 'provider_identity_conflicts' => 0, 'payment_exceptions' => 1,
            'payment_exception_receipts' => 1, 'payment_exception_events' => 1], $this->receiptCounts());
        $this->assertSame(1, Booking::count(), 'only the other booking: never overbooked, nothing allocated');
        $this->assertSame(0, \App\Models\BookingRefund::count(), 'no refund case');
    }

    public function test_a_finalized_checkout_has_one_receipt_of_its_booking_and_intent_and_no_exception(): void
    {
        $intent = $this->checkoutIntent();

        $this->assertSame(FinalizationResult::FINALIZED, $this->finalizeIntent($intent)->outcome);
        $this->assertSame(FinalizationResult::ALREADY_FINALIZED, $this->finalizeIntent($intent)->outcome);

        $booking = $intent->fresh()->booking;
        $receipt = Payment::sole();
        $this->assertSame([$booking->id, $intent->id], [(int) $receipt->booking_id, (int) $receipt->checkout_intent_id]);
        $this->assertSame([$intent->id, $booking->id], [(int) ProviderPaymentIdentity::sole()->checkout_intent_id, (int) ProviderPaymentIdentity::sole()->booking_id]);
        $this->assertSame(0, PaymentException::count());
    }

    public function test_a_replay_whose_facts_differ_from_the_recorded_receipt_is_reviewed_and_changes_nothing(): void
    {
        $intent = $this->checkoutIntent();
        $this->finalizeIntent($intent);
        $before = [Payment::sole()->toArray(), ProviderPaymentIdentity::sole()->toArray(), $intent->fresh()->status];

        $result = $this->finalizeIntent($intent, ['livemode' => true]); // same id and amount, other mode

        $this->assertSame([FinalizationResult::PAYMENT_REVIEW, 'receipt_conflict_provider_mismatch'], [$result->outcome, $result->reason]);
        $this->assertSame($before, [Payment::sole()->toArray(), ProviderPaymentIdentity::sole()->toArray(), $intent->fresh()->status]);
        $this->assertSame(1, ProviderIdentityConflict::where('reason', 'livemode')->count());
    }

    public function test_a_payment_id_already_recorded_for_a_legacy_booking_never_finalizes_a_checkout(): void
    {
        $booking = $this->paxBooking(1, 'confirmed', self::DATE);
        $intent = $this->checkoutIntent();
        $this->receipt($booking, 4000, 'paid', 'pay_test_final_' . $intent->id);

        $result = $this->finalizeIntent($intent);

        $this->assertSame([FinalizationResult::PAYMENT_REVIEW, 'payment_id_used_by_payment_record'], [$result->outcome, $result->reason]);
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, ProviderPaymentIdentity::count());
    }

    public static function legacyExceptions(): array
    {
        return [
            'date filled up' => ['full', Payment::REVIEW_CAPACITY_UNAVAILABLE, PaymentException::KIND_LEGACY_UNALLOCATED, 'paid_unallocated'],
            'paid after cancellation' => ['cancelled', Payment::REVIEW_PAID_AFTER_CANCELLATION, PaymentException::KIND_PAID_AFTER_CANCELLATION, 'cancelled_by_guest'],
            'different amount' => ['amount', Payment::REVIEW_AMOUNT_MISMATCH, PaymentException::KIND_AMOUNT_MISMATCH, 'pending_downpayment'],
            'paid again' => ['again', Payment::REVIEW_PAID_AGAIN, PaymentException::KIND_PAID_AGAIN, 'confirmed'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('legacyExceptions')]
    public function test_each_legacy_review_flag_opens_one_exception_for_its_receipt_even_when_retried(string $case, string $flag, string $kind, string $status): void
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $attributes = $this->paidAttributes($case === 'amount' ? 150000 : 200000);
        match ($case) {
            'full' => $this->fillDate(5),
            'cancelled' => $booking->update(['status' => 'cancelled_by_guest']),
            'again' => $this->payByWebhook($booking, 'pay_flow_first')->assertOk(),
            default => null,
        };

        $this->payByWebhook($booking, 'pay_flow_x', $attributes)->assertOk();
        $this->payByWebhook($booking, 'pay_flow_x', $attributes)->assertOk(); // duplicate delivery

        $receipt = Payment::where('paymongo_payment_id', 'pay_flow_x')->sole();
        $exception = PaymentException::sole();
        $this->assertSame($flag, $receipt->review_reason);
        $this->assertSame([$kind, 'open', $receipt->id, $booking->id, null], [$exception->kind, $exception->status, (int) $exception->primary_payment_id, (int) $exception->booking_id, $exception->checkout_intent_id]);
        $this->assertSame($status, $booking->fresh()->status);
        $this->assertSame(1, ProviderPaymentIdentity::where('provider_payment_id', 'pay_flow_x')->count());
        $this->assertSame(0, \App\Models\BookingRefund::count());
        $this->assertSame(1, AuditLog::where('action', 'PAYMENT_EXCEPTION_OPENED')->count());
    }

    public function test_a_legacy_replay_with_a_different_amount_is_a_conflict_and_never_reapplied(): void
    {
        $booking = $this->unpaidBooking(2, self::DATE);
        $this->payByWebhook($booking, 'pay_flow_amount')->assertOk();
        $confirmer = app(LegacyPaymentConfirmer::class);

        $outcome = $confirmer->apply($booking->fresh(), 'pay_flow_amount', $this->paidAttributes(250000), "cs_{$booking->id}", 'return');

        $this->assertSame(LegacyPaymentConfirmer::CONFLICT, $outcome);
        $this->assertSame('2000.00', (string) Payment::where('paymongo_payment_id', 'pay_flow_amount')->sole()->amount);
        $this->assertSame(1, ProviderIdentityConflict::where('reason', 'amount')->count());
        $this->assertSame(LegacyPaymentConfirmer::ALREADY_RECORDED, $confirmer->apply($booking->fresh(), 'pay_flow_amount', $this->paidAttributes(), "cs_{$booking->id}", 'return'));
    }

    public function test_exceptions_are_listed_read_only_in_the_staff_queue_including_checkouts_without_a_booking(): void
    {
        $this->fillDate(5);
        $intent = $this->checkoutIntent(30, ['hold_expires_at' => now()->subMinutes(5)]);
        $this->finalizeIntent($intent);
        $late = $this->unpaidBooking(2, self::DATE);
        $this->payByWebhook($late, 'pay_flow_queue')->assertOk();

        $page = $this->actingAs($this->staff())->get('/admin/payments/exceptions')->assertOk();

        $page->assertSee('Payment exceptions (2 open)')->assertSee($intent->reference)->assertSee('no booking')->assertSee($late->booking_number)
            ->assertSee('Paid checkout, no place was left')->assertSee('Paid after the date filled up')->assertDontSee('Change status');
        Mail::assertNothingSent();
    }
}
