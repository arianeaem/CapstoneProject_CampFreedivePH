<?php

namespace Tests\Feature\Checkout;

use App\Models\CheckoutIntent;
use App\Services\SlotReservationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Hold rule: pending, paid and payment_review intents with a live hold take capacity; nothing else does
 * (payment_review added in Batch 2B.1: the customer may have paid, so the original hold is kept).
 */
class CheckoutHoldRuleTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-12-19';
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function intent(string $status, ?Carbon $holdUntil, int $pax = 3, string $date = self::DATE): CheckoutIntent
    {
        $n = ++$this->seq;

        return CheckoutIntent::create([
            'reference' => sprintf('CHK-HOLD%08d', $n),
            'status' => $status,
            'start_date' => $date,
            'end_date' => Carbon::parse($date)->addDay()->toDateString(),
            'pax_count' => $pax,
            'class_type' => 'discovery',
            'pickup_option' => 'own',
            'currency' => 'PHP',
            'total_amount' => 10050.00,
            'downpayment_amount' => 4000.00,
            'payload' => ['contact' => ['email' => 'hold@example.test']],
            'hold_expires_at' => $holdUntil,
        ]);
    }

    private function slots(): SlotReservationService
    {
        return app(SlotReservationService::class);
    }

    public function test_pending_and_paid_intents_with_live_holds_take_capacity(): void
    {
        $max = $this->slots()->getAvailableSlots(self::DATE);

        $this->intent(CheckoutIntent::STATUS_PENDING, now()->addMinutes(15), 3);
        $this->intent(CheckoutIntent::STATUS_PAID, now()->addMinutes(10), 2);

        $this->assertSame(5, $this->slots()->getCheckoutIntentHoldPaxCount(self::DATE));
        $this->assertSame($max - 5, $this->slots()->getAvailableSlots(self::DATE));
    }

    public function test_expired_holds_and_other_statuses_never_take_capacity(): void
    {
        $max = $this->slots()->getAvailableSlots(self::DATE);

        $this->intent(CheckoutIntent::STATUS_PENDING, now()->subSecond());
        $this->intent(CheckoutIntent::STATUS_PAID, now()->subSecond());
        $this->intent(CheckoutIntent::STATUS_PENDING, null);
        $this->intent(CheckoutIntent::STATUS_PAYMENT_REVIEW, now()->subSecond());
        foreach ([CheckoutIntent::STATUS_FINALIZED, CheckoutIntent::STATUS_PAID_UNALLOCATED, CheckoutIntent::STATUS_FAILED,
                  CheckoutIntent::STATUS_EXPIRED] as $status) {
            $this->intent($status, now()->addMinutes(15)); // even a stray future hold must not count
        }

        $this->assertSame(0, $this->slots()->getCheckoutIntentHoldPaxCount(self::DATE));
        $this->assertSame($max, $this->slots()->getAvailableSlots(self::DATE));
    }

    public function test_payment_review_intent_holds_capacity_only_until_its_original_hold_expires(): void
    {
        $max = $this->slots()->getAvailableSlots(self::DATE);
        $this->intent(CheckoutIntent::STATUS_PAYMENT_REVIEW, now()->addMinutes(7), 2);
        $this->intent(CheckoutIntent::STATUS_PAYMENT_REVIEW, null, 4); // no hold at all: never counts

        $this->assertSame(2, $this->slots()->getCheckoutIntentHoldPaxCount(self::DATE));
        $this->assertSame($max - 2, $this->slots()->getAvailableSlots(self::DATE));

        Carbon::setTestNow(now()->addMinutes(7)->addSecond());
        $this->assertSame($max, $this->slots()->getAvailableSlots(self::DATE));
    }

    public function test_pending_paid_and_review_holds_add_up_and_expired_ones_do_not(): void
    {
        $max = $this->slots()->getAvailableSlots(self::DATE);
        $this->intent(CheckoutIntent::STATUS_PENDING, now()->addMinutes(15), 1);
        $this->intent(CheckoutIntent::STATUS_PAID, now()->addMinutes(10), 2);
        $this->intent(CheckoutIntent::STATUS_PAYMENT_REVIEW, now()->addMinutes(5), 3);
        foreach ([CheckoutIntent::STATUS_PENDING, CheckoutIntent::STATUS_PAID, CheckoutIntent::STATUS_PAYMENT_REVIEW] as $status) {
            $this->intent($status, now()->subMinute(), 4);
        }

        $this->assertSame(6, $this->slots()->getCheckoutIntentHoldPaxCount(self::DATE));
        $this->assertSame($max - 6, $this->slots()->getAvailableSlots(self::DATE));
    }

    public function test_hold_stops_counting_exactly_when_it_expires_without_any_cleanup_job(): void
    {
        $max = $this->slots()->getAvailableSlots(self::DATE);
        $this->intent(CheckoutIntent::STATUS_PAID, now()->addMinutes(10), 4);

        Carbon::setTestNow(now()->addMinutes(9)->addSeconds(59));
        $this->assertSame($max - 4, $this->slots()->getAvailableSlots(self::DATE));

        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertSame($max, $this->slots()->getAvailableSlots(self::DATE));
    }

    public function test_an_intent_can_leave_its_own_hold_out_of_the_count(): void
    {
        $max = $this->slots()->getAvailableSlots(self::DATE);
        $own = $this->intent(CheckoutIntent::STATUS_PAID, now()->addMinutes(10), 3);
        $this->intent(CheckoutIntent::STATUS_PENDING, now()->addMinutes(15), 2);

        $this->assertSame($max - 5, $this->slots()->getAvailableSlots(self::DATE));
        $this->assertSame($max - 2, $this->slots()->getAvailableSlots(self::DATE, $own->id));
    }

    public function test_holds_only_count_on_their_own_date(): void
    {
        $max = $this->slots()->getAvailableSlots(self::DATE);
        $this->intent(CheckoutIntent::STATUS_PENDING, now()->addMinutes(15), 6, '2026-12-26');

        $this->assertSame($max, $this->slots()->getAvailableSlots(self::DATE));
    }

    public function test_has_live_hold_matches_the_capacity_rule(): void
    {
        $this->assertTrue($this->intent(CheckoutIntent::STATUS_PENDING, now()->addMinute())->hasLiveHold());
        $this->assertTrue($this->intent(CheckoutIntent::STATUS_PAID, now()->addMinute())->hasLiveHold());
        $this->assertFalse($this->intent(CheckoutIntent::STATUS_PAID, now()->subMinute())->hasLiveHold());
        $this->assertTrue($this->intent(CheckoutIntent::STATUS_PAYMENT_REVIEW, now()->addMinute())->hasLiveHold());
        $this->assertFalse($this->intent(CheckoutIntent::STATUS_PAYMENT_REVIEW, now()->subMinute())->hasLiveHold());
        $this->assertFalse($this->intent(CheckoutIntent::STATUS_FINALIZED, now()->addMinute())->hasLiveHold());
        $this->assertFalse($this->intent(CheckoutIntent::STATUS_PAID_UNALLOCATED, now()->addMinute())->hasLiveHold());
        $this->assertFalse($this->intent(CheckoutIntent::STATUS_FAILED, now()->addMinute())->hasLiveHold());
        $this->assertFalse($this->intent(CheckoutIntent::STATUS_PENDING, null)->hasLiveHold());
    }
}
