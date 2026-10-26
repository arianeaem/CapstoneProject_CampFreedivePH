<?php

namespace Tests\Feature\Payments;

use App\Models\CheckoutIntent;
use App\Services\Payment\ReceiptBackfillPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\Feature\Payments\Concerns\ReceiptFixtures;
use Tests\Feature\Refunds\Concerns\RefundFixtures;
use Tests\TestCase;

/**
 * Batch 3-II B1 pre-commit fix: a verified PayMongo payment with a different amount (or currency) stays in checkout
 * payment review without a receipt; its id and amount live only in the intent's review history. The dry-run planner
 * now lists it (even with intent.paymongo_payment_id empty) and staff see the reported amount on Checkout Review,
 * labelled as evidence, not a receipt or cash. Nothing is written or moved.
 */
class ReviewEvidenceVisibilityTest extends TestCase
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
        $this->setUpCheckoutWorld();
    }

    private function event(string $reason, ?string $payId, array $details = [], int $occurrences = 1): array
    {
        return ['reason' => $reason, 'payment_id' => $payId, 'intent_status' => 'pending', 'booking_finalized' => false, 'source' => 'webhook',
            'verified_at' => null, 'recorded_at' => now()->toIso8601String(), 'details' => $details, 'occurrences' => $occurrences, 'last_seen_at' => now()->toIso8601String()];
    }

    private function reviewIntent(array $history): CheckoutIntent
    {
        return $this->checkoutIntent(30, ['status' => CheckoutIntent::STATUS_PAYMENT_REVIEW, 'failure_reason' => 'amount_mismatch', 'verification' => ['review_history' => $history]]);
    }

    private function plan(): array
    {
        return collect(app(ReceiptBackfillPlanner::class)->plan()['items'])->keyBy(fn ($i) => "{$i['action']}|{$i['key']}")->all();
    }

    public function test_wrong_amount_payments_from_review_history_are_listed_once_per_provider_payment(): void
    {
        $a = $this->reviewIntent([$this->event('amount_mismatch', 'pay_ev_shared', ['payment_id' => 'pay_ev_shared', 'expected_centavos' => 400000, 'actual_centavos' => 150000], 3)]);
        $b = $this->reviewIntent([$this->event('amount_mismatch', 'pay_ev_shared', ['payment_id' => 'pay_ev_shared', 'expected_centavos' => 400000, 'actual_centavos' => 160000])]);
        $c = $this->reviewIntent([$this->event('currency_mismatch', 'pay_ev_usd', ['payment_id' => 'pay_ev_usd', 'expected_currency' => 'PHP', 'actual_currency' => 'USD'])]);
        $d = $this->reviewIntent([$this->event('amount_mismatch', 'pay_ev_single', ['actual_centavos' => 399999, 'expected_centavos' => 400000], 2)]);
        $this->assertNull($a->paymongo_payment_id);

        $items = $this->plan();

        $shared = $items['review_payment_evidence|paymongo:pay_ev_shared'];
        $this->assertSame([[$a->id, $b->id], null, true, 4], [$shared['checkout_intent_ids'], $shared['amount_centavos'], $shared['amounts_differ'], $shared['occurrences']],
            'duplicate references across checkouts: both listed, conflicting amounts not invented');
        $this->assertSame([null, 'USD', ['currency_mismatch']], [$items['review_payment_evidence|paymongo:pay_ev_usd']['amount_centavos'],
            $items['review_payment_evidence|paymongo:pay_ev_usd']['currency'], $items['review_payment_evidence|paymongo:pay_ev_usd']['reasons']]);
        $single = $items['review_payment_evidence|paymongo:pay_ev_single'];
        $this->assertSame([[$d->id], 399999, false, null, 2], [$single['checkout_intent_ids'], $single['amount_centavos'], $single['amounts_differ'], $single['livemode'], $single['occurrences']]);
        $this->assertStringContainsString('not a receipt', $single['evidence']);
        $this->assertSame([$c->id], $items['review_payment_evidence|paymongo:pay_ev_usd']['checkout_intent_ids']);
    }

    public function test_missing_ids_malformed_history_and_already_recorded_payments_are_handled(): void
    {
        $missing = $this->reviewIntent([$this->event('amount_mismatch', null, ['actual_centavos' => 150000])]);
        $garbage = $this->reviewIntent([]);
        DB::table('checkout_intents')->where('id', $garbage->id)->update(['verification' => json_encode(['review_history' => 'not a list'])]);
        $badEvent = $this->reviewIntent(['not an event', $this->event('session_not_found', null)]);
        $booking = $this->paxBooking(1);
        $this->receipt($booking, 1500, 'paid', 'pay_ev_recorded');
        $this->reviewIntent([$this->event('amount_mismatch', 'pay_ev_recorded', ['actual_centavos' => 150000])]);

        $items = $this->plan();

        $this->assertArrayHasKey("review_history_unreadable|intent:{$missing->id}:0", $items, 'an evidence event without a payment id');
        $this->assertArrayHasKey("review_history_unreadable|intent:{$garbage->id}", $items, 'a review history that is not a list');
        $this->assertArrayHasKey("review_history_unreadable|intent:{$badEvent->id}:0", $items, 'an event that is not an object');
        $this->assertArrayNotHasKey("review_history_unreadable|intent:{$badEvent->id}:1", $items, 'other review reasons are not evidence of a paid payment');
        $this->assertArrayNotHasKey('review_payment_evidence|paymongo:pay_ev_recorded', $items, 'already a receipt');
    }

    public function test_listing_review_evidence_writes_nothing(): void
    {
        $this->reviewIntent([$this->event('amount_mismatch', 'pay_ev_ro', ['actual_centavos' => 150000])]);
        $before = collect(Schema::getTableListing(schemaQualified: false))->reject(fn ($t) => $t === 'migrations')->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $writes = [];
        DB::listen(function ($q) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $q->sql)) {
                $writes[] = $q->sql;
            }
        });

        app(ReceiptBackfillPlanner::class)->plan();

        $this->assertSame([], $writes);
        $this->assertSame($before, collect(Schema::getTableListing(schemaQualified: false))->reject(fn ($t) => $t === 'migrations')->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all());
    }

    public function test_staff_see_the_reported_amount_as_evidence_and_others_do_not(): void
    {
        $intent = $this->reviewIntent([$this->event('amount_mismatch', 'pay_ev_page', ['payment_id' => 'pay_ev_page', 'expected_centavos' => 400000, 'actual_centavos' => 150000], 2)]);

        $this->actingAs($this->staffUser('admin'))->get("/admin/payments/checkout-review/{$intent->id}")->assertOk()
            ->assertSee('Payment-review evidence')->assertSee('not a recorded receipt, is not counted as cash')
            ->assertSee('PayMongo reported: ₱1,500.00 (expected ₱4,000.00)', false)->assertSee('pay_ev_page')->assertSee('seen 2 times');

        $this->actingAs($this->staffUser('coach'))->get("/admin/payments/checkout-review/{$intent->id}")->assertDontSee('pay_ev_page');
        $this->app['auth']->guard('web')->logout();
        $this->get("/admin/payments/checkout-review/{$intent->id}")->assertRedirect();
    }
}
