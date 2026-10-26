<?php

namespace Tests\Feature\Payments;

use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PaymentException;
use App\Models\ProviderIdentityConflict;
use App\Models\ProviderPaymentIdentity;
use App\Models\RefundAttempt;
use App\Services\Payment\PaymentExceptionService;
use App\Services\Payment\ProviderPayment;
use App\Services\Payment\ReceiptOutcome;
use App\Services\Payment\ReceiptRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B1: ReceiptRecorder outcomes. A provider payment is recorded once; a replay is accepted only when amount,
 * currency, mode and owner match the verified facts; everything else is a conflict that records nothing and moves
 * nothing. Database triggers keep verified facts, owners and exceptions from being changed afterwards.
 */
class ReceiptRecorderTest extends TestCase
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
    }

    private function recorder(): ReceiptRecorder
    {
        return app(ReceiptRecorder::class);
    }

    private function paymongo(string $id, int $centavos = 200000, string $currency = 'PHP', bool $live = false): ProviderPayment
    {
        return new ProviderPayment('paymongo', $id, $centavos, $currency, $live);
    }

    /** Record $payment as a new receipt of $booking (legacy owner). */
    private function recordFor(Booking $booking, ProviderPayment $payment): ReceiptOutcome
    {
        return DB::transaction(fn () => $this->recorder()->record($payment, null, $booking->id, 'test', fn () => Payment::create([
            'booking_id' => $booking->id, 'payment_method' => 'gcash', 'transaction_id' => 'TXN-' . Str::upper(Str::random(8)),
            'paymongo_payment_id' => $payment->id, 'amount' => round($payment->amountCentavos / 100, 2), 'payment_type' => 'downpayment', 'status' => 'paid', 'paid_at' => now(),
        ])));
    }

    public function test_a_new_payment_is_recorded_once_with_its_verified_facts_in_integer_centavos(): void
    {
        $booking = $this->paxBooking(1);

        $outcome = $this->recordFor($booking, $this->paymongo('pay_rec_new', 199999));

        $this->assertSame(ReceiptOutcome::RECORDED, $outcome->status);
        $identity = ProviderPaymentIdentity::sole();
        $this->assertSame(['paymongo', 'pay_rec_new', 199999, 'PHP', false, null, $booking->id, $outcome->payment->id, 'provider_verified'],
            [$identity->provider, $identity->provider_payment_id, $identity->amount_centavos, $identity->currency, $identity->livemode,
                $identity->checkout_intent_id, (int) $identity->booking_id, (int) $identity->payment_id, $identity->verification]);
        $this->assertSame('1999.99', (string) $outcome->payment->fresh()->amount);
        $this->assertSame(199999, $outcome->payment->fresh()->amountCentavos());
    }

    public function test_an_exact_replay_is_already_recorded_and_writes_nothing(): void
    {
        $booking = $this->paxBooking(1);
        $first = $this->recordFor($booking, $this->paymongo('pay_rec_replay'));
        $before = $this->receiptCounts();

        $again = $this->recordFor($booking, $this->paymongo('pay_rec_replay'));

        $this->assertSame(ReceiptOutcome::ALREADY_RECORDED, $again->status);
        $this->assertTrue($again->isReplay());
        $this->assertSame($first->payment->id, $again->payment->id);
        $this->assertSame($before, $this->receiptCounts());
    }

    public static function providerMismatches(): array
    {
        return [
            'different amount' => [['centavos' => 150000], 'amount'],
            'different currency' => [['currency' => 'USD'], 'currency'],
            'different mode' => [['live' => true], 'livemode'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('providerMismatches')]
    public function test_a_seen_payment_id_with_different_provider_facts_is_a_conflict_never_a_duplicate(array $changed, string $reason): void
    {
        $booking = $this->paxBooking(1);
        $this->recordFor($booking, $this->paymongo('pay_rec_facts'));
        $identityBefore = ProviderPaymentIdentity::sole()->toArray();

        $p = $changed + ['centavos' => 200000, 'currency' => 'PHP', 'live' => false];
        $outcome = $this->recordFor($booking, $this->paymongo('pay_rec_facts', $p['centavos'], $p['currency'], $p['live']));
        $this->recordFor($booking, $this->paymongo('pay_rec_facts', $p['centavos'], $p['currency'], $p['live'])); // retried event

        $this->assertSame([ReceiptOutcome::PROVIDER_MISMATCH, $reason], [$outcome->status, $outcome->reason]);
        $this->assertFalse($outcome->isReplay());
        $this->assertSame(1, Payment::count(), 'no second receipt');
        $this->assertSame($identityBefore, ProviderPaymentIdentity::sole()->toArray(), 'verified facts unchanged');
        $this->assertSame(1, ProviderIdentityConflict::where('reason', $reason)->count(), 'kept once per distinct attempt');
        $this->assertDatabaseHas('audit_logs', ['action' => 'PAYMENT_IDENTITY_CONFLICT']);
    }

    public function test_the_same_payment_id_for_another_owner_is_an_owner_conflict_and_the_receipt_never_moves(): void
    {
        $booking = $this->paxBooking(1);
        $other = $this->paxBooking(1);
        $first = $this->recordFor($booking, $this->paymongo('pay_rec_owner'));

        $outcome = $this->recordFor($other, $this->paymongo('pay_rec_owner'));

        $this->assertSame([ReceiptOutcome::OWNER_MISMATCH, 'owner'], [$outcome->status, $outcome->reason]);
        $this->assertSame($booking->id, (int) $first->payment->fresh()->booking_id);
        $this->assertSame($booking->id, (int) ProviderPaymentIdentity::sole()->booking_id);
        $this->assertSame(0, Payment::where('booking_id', $other->id)->count());
        $this->assertSame(['checkout_intent_id' => null, 'booking_id' => $other->id, 'amount_centavos' => 200000, 'currency' => 'PHP', 'livemode' => false],
            ProviderIdentityConflict::sole()->attempted);
    }

    public function test_historical_duplicate_payment_ids_are_never_merged_or_given_a_winner(): void
    {
        $a = $this->paxBooking(1);
        $b = $this->paxBooking(1);
        $this->receipt($a, 2000, 'paid', 'pay_rec_dup');
        $this->receipt($b, 2000, 'paid', 'pay_rec_dup');

        $outcome = $this->recordFor($a, $this->paymongo('pay_rec_dup'));

        $this->assertSame([ReceiptOutcome::HISTORICAL, 'historical_duplicate'], [$outcome->status, $outcome->reason]);
        $this->assertSame(2, Payment::where('paymongo_payment_id', 'pay_rec_dup')->count());
        $this->assertSame(0, ProviderPaymentIdentity::count(), 'nothing claimed');
    }

    public function test_a_single_historical_receipt_is_a_read_only_replay_only_for_the_same_owner_and_amount(): void
    {
        $booking = $this->paxBooking(1);
        $other = $this->paxBooking(1);
        $legacy = $this->receipt($booking, 2000, 'paid', 'pay_rec_legacy');

        $same = $this->recordFor($booking, $this->paymongo('pay_rec_legacy'));
        $otherAmount = $this->recordFor($booking, $this->paymongo('pay_rec_legacy', 250000));
        $otherOwner = $this->recordFor($other, $this->paymongo('pay_rec_legacy'));

        $this->assertSame([ReceiptOutcome::ALREADY_RECORDED_LEGACY, $legacy->id], [$same->status, $same->payment->id]);
        $this->assertSame([ReceiptOutcome::PROVIDER_MISMATCH, 'amount'], [$otherAmount->status, $otherAmount->reason]);
        $this->assertSame([ReceiptOutcome::OWNER_MISMATCH, 'owner'], [$otherOwner->status, $otherOwner->reason]);
        $this->assertSame(0, ProviderPaymentIdentity::count(), 'a historical receipt is never claimed at runtime');
        $this->assertSame(1, Payment::count());
    }

    public function test_unverified_identity_or_missing_receipt_is_never_a_replay(): void
    {
        $booking = $this->paxBooking(1);
        $failed = $this->receipt($booking, 2000, 'failed', 'pay_rec_missing');
        DB::table('provider_payment_identities')->insert([
            ['provider' => 'paymongo', 'provider_payment_id' => 'pay_rec_unverified', 'amount_centavos' => 200000, 'currency' => null, 'livemode' => null,
                'booking_id' => $booking->id, 'payment_id' => null, 'status' => 'claimed', 'verification' => 'historical_unverified', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
            ['provider' => 'paymongo', 'provider_payment_id' => 'pay_rec_missing', 'amount_centavos' => 200000, 'currency' => 'PHP', 'livemode' => false,
                'booking_id' => $booking->id, 'payment_id' => $failed->id, 'status' => 'claimed', 'verification' => 'provider_verified', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $unverified = $this->recordFor($booking, $this->paymongo('pay_rec_unverified'));
        $missing = $this->recordFor($booking, $this->paymongo('pay_rec_missing'));

        $this->assertSame([ReceiptOutcome::HISTORICAL, 'historical_unverified'], [$unverified->status, $unverified->reason]);
        $this->assertSame([ReceiptOutcome::HISTORICAL, 'receipt_missing'], [$missing->status, $missing->reason]);
        $this->assertSame(1, Payment::count());
    }

    public function test_a_receipt_that_does_not_match_the_verified_payment_rolls_everything_back(): void
    {
        $booking = $this->paxBooking(1);

        $this->expectException(\LogicException::class);
        try {
            DB::transaction(fn () => $this->recorder()->record($this->paymongo('pay_rec_bad', 200000), null, $booking->id, 'test', fn () => Payment::create([
                'booking_id' => $booking->id, 'payment_method' => 'gcash', 'transaction_id' => 'TXN-BAD', 'paymongo_payment_id' => 'pay_rec_bad',
                'amount' => 1999.99, 'payment_type' => 'downpayment', 'status' => 'paid',
            ])));
        } finally {
            $this->assertSame(['payments' => 0, 'provider_payment_identities' => 0], array_intersect_key($this->receiptCounts(), array_flip(['payments', 'provider_payment_identities'])));
        }
    }

    /** An unapplied receipt of a checkout intent (no booking), recorded as the finalizer would. */
    private function unappliedReceipt(CheckoutIntent $intent, string $payId): Payment
    {
        return DB::transaction(fn () => $this->recorder()->record($this->paymongo($payId, 400000), $intent->id, null, 'test', fn () => Payment::create([
            'booking_id' => null, 'checkout_intent_id' => $intent->id, 'payment_method' => 'gcash', 'transaction_id' => 'PAY-' . Str::upper(Str::random(8)),
            'paymongo_payment_id' => $payId, 'amount' => 4000, 'payment_type' => 'downpayment', 'status' => 'paid',
        ])))->payment;
    }

    private function bookingFromIntent(CheckoutIntent $intent): Booking
    {
        $booking = $this->paxBooking(2);
        $booking->forceFill(['checkout_intent_id' => $intent->id])->save();

        return $booking;
    }

    public function test_attach_gives_an_unapplied_receipt_only_its_own_intents_booking(): void
    {
        $intent = $this->checkoutIntent();
        $receipt = $this->unappliedReceipt($intent, 'pay_rec_attach');
        $stranger = $this->paxBooking(1);
        $booking = $this->bookingFromIntent($intent);

        $refused = DB::transaction(fn () => $this->recorder()->attach($receipt, $stranger, 'test'));
        $attached = DB::transaction(fn () => $this->recorder()->attach($receipt, $booking, 'test'));
        $again = DB::transaction(fn () => $this->recorder()->attach($receipt, $booking, 'test'));

        $this->assertSame([ReceiptOutcome::ATTACH_BLOCKED, 'different_owner'], [$refused->status, $refused->reason]);
        $this->assertSame(ReceiptOutcome::ATTACHED, $attached->status);
        $this->assertSame([ReceiptOutcome::ATTACH_BLOCKED, 'already_applied'], [$again->status, $again->reason]);
        $this->assertSame([$booking->id, $intent->id], [(int) $receipt->fresh()->booking_id, (int) $receipt->fresh()->checkout_intent_id]);
        $this->assertSame($booking->id, (int) ProviderPaymentIdentity::sole()->booking_id);
    }

    public function test_attach_is_blocked_by_refund_activity_or_an_open_payment_exception(): void
    {
        $refundIntent = $this->checkoutIntent();
        $refunding = $this->unappliedReceipt($refundIntent, 'pay_rec_refunding');
        RefundAttempt::create(['payment_id' => $refunding->id, 'operation_id' => (string) Str::uuid(), 'method' => 'paymongo', 'amount_centavos' => 1000,
            'currency' => 'PHP', 'status' => RefundAttempt::UNKNOWN]);
        $heldIntent = $this->checkoutIntent();
        $held = $this->unappliedReceipt($heldIntent, 'pay_rec_held');
        DB::transaction(fn () => app(PaymentExceptionService::class)->open(PaymentException::KIND_INTENT_UNALLOCATED, $held, 'test'));

        $a = DB::transaction(fn () => $this->recorder()->attach($refunding, $this->bookingFromIntent($refundIntent), 'test'));
        $b = DB::transaction(fn () => $this->recorder()->attach($held, $this->bookingFromIntent($heldIntent), 'test'));

        $this->assertSame([ReceiptOutcome::ATTACH_BLOCKED, 'refund_activity'], [$a->status, $a->reason]);
        $this->assertSame([ReceiptOutcome::ATTACH_BLOCKED, 'payment_exception_open'], [$b->status, $b->reason]);
        $this->assertSame(0, Payment::whereNotNull('booking_id')->whereIn('id', [$refunding->id, $held->id])->count());
    }

    public function test_database_keeps_verified_facts_owners_and_exceptions_unchanged(): void
    {
        $booking = $this->paxBooking(1);
        $other = $this->paxBooking(1);
        $receipt = $this->recordFor($booking, $this->paymongo('pay_rec_guard'))->payment;
        $exception = DB::transaction(fn () => app(PaymentExceptionService::class)->open(PaymentException::KIND_PAID_AGAIN, $receipt, 'test'));
        $intent = $this->checkoutIntent();
        $unapplied = $this->unappliedReceipt($intent, 'pay_rec_guard_intent');

        $refusals = [
            'identity amount' => fn () => DB::table('provider_payment_identities')->update(['amount_centavos' => 1]),
            'identity owner' => fn () => DB::table('provider_payment_identities')->where('provider_payment_id', 'pay_rec_guard')->update(['booking_id' => $other->id]),
            'identity delete' => fn () => DB::table('provider_payment_identities')->delete(),
            'receipt moved' => fn () => DB::table('payments')->where('id', $receipt->id)->update(['booking_id' => $other->id]),
            'receipt intent changed' => fn () => DB::table('payments')->where('id', $unapplied->id)->update(['checkout_intent_id' => $this->checkoutIntent()->id]),
            'receipt without owner' => fn () => DB::table('payments')->insert(['booking_id' => null, 'checkout_intent_id' => null, 'payment_method' => 'cash',
                'transaction_id' => 'TXN-NO-OWNER', 'amount' => 1, 'payment_type' => 'full', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]),
            'exception resolved' => fn () => DB::table('payment_exceptions')->update(['status' => 'resolved']),
            'exception deleted' => fn () => DB::table('payment_exceptions')->delete(),
            'event changed' => fn () => DB::table('payment_exception_events')->update(['type' => 'x']),
            'link removed' => fn () => DB::table('payment_exception_receipts')->delete(),
            'link to another owner' => fn () => DB::table('payment_exception_receipts')->insert(['payment_exception_id' => $exception->id, 'payment_id' => $unapplied->id,
                'owner_type' => 'booking', 'owner_id' => $booking->id, 'created_at' => now()]),
        ];
        foreach ($refusals as $label => $write) {
            $this->assertNotNull($this->databaseRefusal($write), "{$label} must be refused by the database");
        }

        // Allowed: an unapplied receipt gets its booking once (empty -> value)
        $this->assertNull($this->databaseRefusal(fn () => DB::table('payments')->where('id', $unapplied->id)->update(['booking_id' => $this->bookingFromIntent($intent)->id])));
        $this->assertSame('open', $exception->fresh()->status);
        $this->assertSame($booking->id, (int) $receipt->fresh()->booking_id);
    }
}
