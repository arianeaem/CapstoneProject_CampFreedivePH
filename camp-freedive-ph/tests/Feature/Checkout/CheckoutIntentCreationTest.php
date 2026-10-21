<?php

namespace Tests\Feature\Checkout;

use App\Models\CheckoutIntent;
use App\Models\PricingRule;
use App\Models\SystemSetting;
use App\Services\PricingRuleEngine;
use App\Services\SlotReservationService;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Stage A (tests first): starting a checkout creates a checkout intent, never an official booking.
 *
 * These tests describe FUTURE behaviour and are expected to fail until the checkout-intent
 * feature is implemented. Future contracts they rely on (not in the app yet):
 *  - config flag `checkout.intents_enabled` turns the new flow on for POST /book (route booking.store);
 *  - table `checkout_intents` with at least: reference, status, start_date, end_date, pax_count,
 *    class_type, pickup_option, currency, total_amount, downpayment_amount, payload (encrypted),
 *    hold_expires_at, paymongo_checkout_session_id;
 *  - JSON response keys `checkout_reference` (prefix CHK-) and `checkout_url` (no booking_number / pin / booking_id / manage_url).
 *    The booking page posts JSON and then redirects the browser to checkout_url itself, so the JSON contract is what is tested;
 *  - PayMongo checkout metadata carries `checkout_intent_id` and `checkout_reference`, never a booking id;
 *  - model App\Models\CheckoutIntent, with payload['pricing'] = base_price_per_pax, adjusted_price_per_pax, subtotal,
 *    adjustments[] (rule_id, delta_per_pax, ...) - the quote that was charged;
 *  - SlotReservationService::getAvailableSlots() counts pending intents only while their 15-minute hold lasts;
 *  - a failed session creation may keep a `failed` intent, but never a live hold.
 */
class CheckoutIntentCreationTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;

    /** Deliberately not the code defaults (3,000 / 2,000), so a pass proves the configured rates are used. */
    private const CARPOOL_DOWNPAYMENT_PER_HEAD = 3250.00;
    private const OWN_TRANSPORT_DOWNPAYMENT_PER_HEAD = 2150.00;
    private const LGU_FEE_PER_HEAD = 310.00;
    private const ENVIRONMENTAL_FEE_PER_HEAD = 55.00;
    private const PRICING_RULE_PERCENT = 10.00;

    private string $startDate;
    private string $endDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFakePayMongo();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));

        // Future contract: the new flow is behind this flag.
        config(['checkout.intents_enabled' => true]);

        $this->setting('program_pricing.downpayment_carpool', self::CARPOOL_DOWNPAYMENT_PER_HEAD);
        $this->setting('program_pricing.downpayment_own_transpo', self::OWN_TRANSPORT_DOWNPAYMENT_PER_HEAD);
        $this->setting('addons.lgu_tourism_pass_fee', self::LGU_FEE_PER_HEAD, 'addons');
        $this->setting('addons.environmental_fee', self::ENVIRONMENTAL_FEE_PER_HEAD, 'addons');

        // No leftover rules: each test adds the pricing rules it needs (as PricingRuleEngineTest does)
        PricingRule::query()->forceDelete();

        // Weather is not under test: a safe forecast, so no weather HTTP call is made.
        $this->mock(WeatherSafetyService::class, function ($mock): void {
            $mock->shouldReceive('getForecast')->andReturn([
                'risk_level' => 'safe',
                'overall_classification' => 'Safe',
                'is_bookable' => true,
                'day1' => ['classification' => 'Safe'],
                'day2' => ['classification' => 'Safe'],
            ]);
        });

        $saturday = Carbon::today()->addWeeks(6)->next(Carbon::SATURDAY);
        $this->startDate = $saturday->toDateString();
        $this->endDate = $saturday->copy()->addDay()->toDateString();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function setting(string $key, float $value, string $group = 'program_pricing'): void
    {
        SystemSetting::create(['group' => $group, 'key' => $key, 'value' => number_format($value, 2, '.', ''), 'type' => 'float']);
    }

    /** An active +10% seasonality rule, either for the season of the test dates (applies) or another season (does not). */
    private function seasonRule(bool $applicable): PricingRule
    {
        $season = app(PricingRuleEngine::class)->getSeasonForDate($this->startDate);
        $other = collect(['peak', 'shoulder', 'off_peak'])->first(fn ($s) => $s !== $season);

        return PricingRule::create([
            'name' => $applicable ? 'Synthetic season surcharge' : 'Synthetic other-season surcharge',
            'rule_type' => 'seasonality',
            'condition_value' => $applicable ? $season : $other,
            'applies_to' => 'all',
            'adjustment_type' => 'increase',
            'adjustment_method' => 'percentage',
            'adjustment_value' => self::PRICING_RULE_PERCENT,
            'priority' => 1,
            'status' => 'active',
        ]);
    }

    /** Every outgoing HTTP request went to the faked PayMongo API (none can be real: stray requests are blocked). */
    private function assertOnlyFakePayMongoWasCalled(): void
    {
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('api.paymongo.com', parse_url($request->url(), PHP_URL_HOST), 'Unexpected outgoing request: ' . $request->url());
        }
    }

    /** The future model; the test reads the decrypted payload through it. */
    private function intentModel(): CheckoutIntent
    {
        return CheckoutIntent::query()->sole();
    }

    /** A valid booking form with synthetic people only. */
    private function bookingForm(int $pax = 2, string $pickup = 'own', array $overrides = []): array
    {
        $participants = [];
        foreach (range(1, $pax) as $i) {
            $participants[] = [
                'first_name' => 'Synthetic',
                'last_name' => ['Alpha', 'Bravo', 'Charlie', 'Delta'][$i - 1], // names may only contain letters
                'age' => 25 + $i,
                'health_condition' => 'Synthetic mild asthma note',
                'swimmer_status' => 'swimmer',
            ];
        }

        return array_merge([
            'class_type' => 'discovery',
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'participants' => $participants,
            'contact_first_name' => 'Casey',
            'contact_last_name' => 'Fixture',
            'contact_email' => 'casey.fixture@example.test',
            'contact_phone' => '09170000001',
            'pickup_option' => $pickup,
            'pickup_location' => $pickup === 'carpool' ? 'Shell Tiendesitas - 3:00 AM' : null,
            'boat_dive' => false,
            'has_agreed_to_terms' => true,
            'confirmation_ack' => true,
            'payment_method' => 'paymongo',
        ], $overrides);
    }

    private function startCheckout(int $pax = 2, string $pickup = 'own', array $overrides = [])
    {
        $this->fakeCheckoutSessionCreated();

        return $this->postJson(route('booking.store'), $this->bookingForm($pax, $pickup, $overrides));
    }

    private function intents()
    {
        return DB::table('checkout_intents'); // future table
    }

    private function availableSlots(): int
    {
        return app(SlotReservationService::class)->getAvailableSlots($this->startDate);
    }

    // ------------------------------------------------------------------ tests

    public function test_valid_submission_creates_one_pending_checkout_intent(): void
    {
        $this->startCheckout(2)->assertOk()->assertJsonPath('success', true);

        $this->assertSame(1, $this->intents()->count());
        $intent = $this->intents()->first();
        $this->assertSame('pending', $intent->status);
        $this->assertSame($this->startDate, Carbon::parse($intent->start_date)->toDateString());
        $this->assertSame($this->endDate, Carbon::parse($intent->end_date)->toDateString());
        $this->assertSame(2, (int) $intent->pax_count);
        $this->assertSame('discovery', $intent->class_type);
        $this->assertSame('PHP', $intent->currency);
        $this->assertSame('cs_test_intent_001', $intent->paymongo_checkout_session_id);
    }

    public function test_unpaid_checkout_creates_no_official_booking_records_or_notifications(): void
    {
        $this->startCheckout(2)->assertOk();

        foreach (['bookings', 'booking_participants', 'batches', 'booking_price_adjustments', 'payments', 'participants', 'booking_status_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "An unpaid checkout must not write to {$table}.");
        }

        // No booking confirmation or "New booking received" message of any kind before payment
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_response_contains_temporary_checkout_reference_and_paymongo_url(): void
    {
        $response = $this->startCheckout(2)->assertOk();

        $reference = $response->json('checkout_reference');
        $this->assertIsString($reference);
        $this->assertStringStartsWith('CHK-', $reference, 'Temporary references use the CHK- prefix.');
        $this->assertStringStartsNotWith('CFP-', $reference, 'A temporary reference must never look like an official booking number.');
        $this->assertGreaterThan(strlen('CHK-'), strlen($reference));
        $this->assertSame($reference, $this->intents()->value('reference'));
        $this->assertSame('https://checkout.paymongo.test/cs_test_intent_001', $response->json('checkout_url'));
        // The booking page only sends the browser to checkout_url when this flag is true (create.blade.php)
        $response->assertJsonPath('is_paymongo_redirect', true);
        $this->assertOnlyFakePayMongoWasCalled();
    }

    public function test_no_booking_number_or_pin_is_issued_before_payment(): void
    {
        $response = $this->startCheckout(2)->assertOk();

        foreach (['booking_number', 'pin', 'booking_id', 'manage_url'] as $key) {
            $this->assertNull($response->json($key), "The response must not contain {$key} before payment.");
        }
        $this->assertStringNotContainsStringIgnoringCase('CFP-', $response->getContent());
        $this->assertSame(0, DB::table('bookings')->count());
    }

    #[DataProvider('downpaymentCases')]
    public function test_downpayment_is_calculated_on_the_server_from_service_and_participant_count(int $pax, string $pickup, float $perHead): void
    {
        $this->startCheckout($pax, $pickup)->assertOk();

        $expected = round($perHead * $pax, 2);
        $this->assertEquals($expected, (float) $this->intents()->value('downpayment_amount'));

        $lineItems = $this->singleCheckoutSessionAttributes()['line_items'];
        $this->assertCount(1, $lineItems);
        $this->assertSame((int) round($expected * 100), $lineItems[0]['amount']);
        $this->assertSame(1, $lineItems[0]['quantity']);
        $this->assertSame('PHP', $lineItems[0]['currency']);
    }

    public static function downpaymentCases(): array
    {
        return [
            'own transport, 2 divers' => [2, 'own', self::OWN_TRANSPORT_DOWNPAYMENT_PER_HEAD],
            'own transport, 3 divers' => [3, 'own', self::OWN_TRANSPORT_DOWNPAYMENT_PER_HEAD],
            'carpool, 2 divers' => [2, 'carpool', self::CARPOOL_DOWNPAYMENT_PER_HEAD],
        ];
    }

    public function test_client_supplied_amounts_do_not_change_the_charge(): void
    {
        $tampered = [
            'amount' => 1,
            'downpayment_amount' => 1.00,
            'downpayment_due' => 1.00,
            'total_amount' => 1.00,
            'balance_amount' => 0,
            'line_items' => [['name' => 'Tampered', 'amount' => 100, 'quantity' => 1, 'currency' => 'PHP']],
        ];

        $this->startCheckout(2, 'own', $tampered)->assertOk();

        $expected = round(self::OWN_TRANSPORT_DOWNPAYMENT_PER_HEAD * 2, 2);
        $this->assertEquals($expected, (float) $this->intents()->value('downpayment_amount'));
        $lineItems = $this->singleCheckoutSessionAttributes()['line_items'];
        $this->assertCount(1, $lineItems);
        $this->assertSame((int) round($expected * 100), $lineItems[0]['amount']);
    }

    public function test_paymongo_receives_the_contact_identity_and_no_health_details(): void
    {
        $this->startCheckout(2)->assertOk();

        $attributes = $this->singleCheckoutSessionAttributes();
        $this->assertSame([
            'name' => 'Casey Fixture',
            'email' => 'casey.fixture@example.test',
            'phone' => '09170000001',
        ], $attributes['billing']);

        // Future contract: PayMongo events can be matched back to the intent
        $this->assertSame((string) $this->intents()->value('id'), (string) ($attributes['metadata']['checkout_intent_id'] ?? null));
        $this->assertSame($this->intents()->value('reference'), $attributes['metadata']['checkout_reference'] ?? null);
        $this->assertArrayNotHasKey('booking_id', $attributes['metadata']);

        $this->assertStringNotContainsString('Synthetic mild asthma note', json_encode($attributes));
        $this->assertOnlyFakePayMongoWasCalled();
    }

    public function test_applicable_pricing_rule_is_quoted_server_side_and_snapshotted_on_the_intent(): void
    {
        $rule = $this->seasonRule(applicable: true);
        $pax = 2;

        $this->startCheckout($pax)->assertOk();

        // Independent arithmetic: base price from the engine with no rule involved, then +10%
        $base = (float) app(PricingRuleEngine::class)->getBasePrice('discovery');
        $adjusted = round($base * (1 + self::PRICING_RULE_PERCENT / 100), 2);
        $total = round($adjusted * $pax + (self::LGU_FEE_PER_HEAD + self::ENVIRONMENTAL_FEE_PER_HEAD) * $pax, 2);
        $downpayment = min($total, round(self::OWN_TRANSPORT_DOWNPAYMENT_PER_HEAD * $pax, 2));

        // Unencrypted amount columns on the intent
        $this->assertEquals($total, (float) $this->intents()->value('total_amount'));
        $this->assertEquals($downpayment, (float) $this->intents()->value('downpayment_amount'));
        $this->assertSame((int) round($downpayment * 100), $this->singleCheckoutSessionAttributes()['line_items'][0]['amount']);

        // Future contract: payload['pricing'] keeps the quote that was charged
        $pricing = $this->intentModel()->payload['pricing'];
        $this->assertEquals($base, (float) $pricing['base_price_per_pax']);
        $this->assertEquals($adjusted, (float) $pricing['adjusted_price_per_pax']);
        $this->assertEquals(round($adjusted * $pax, 2), (float) $pricing['subtotal']);
        $this->assertCount(1, $pricing['adjustments']);
        $this->assertSame($rule->id, (int) $pricing['adjustments'][0]['rule_id']);
        $this->assertEquals(round($adjusted - $base, 2), (float) $pricing['adjustments'][0]['delta_per_pax']);

        // The adjustment is only recorded on an official booking after payment
        $this->assertSame(0, DB::table('booking_price_adjustments')->count());
        $this->assertSame(0, DB::table('bookings')->count());
        $this->assertOnlyFakePayMongoWasCalled();
    }

    public function test_inapplicable_pricing_rule_is_not_applied_to_the_quote(): void
    {
        $this->seasonRule(applicable: false);
        $pax = 2;

        $this->startCheckout($pax)->assertOk();

        $base = (float) app(PricingRuleEngine::class)->getBasePrice('discovery');
        $pricing = $this->intentModel()->payload['pricing'];
        $this->assertEquals($base, (float) $pricing['adjusted_price_per_pax']);
        $this->assertSame([], $pricing['adjustments']);
        $this->assertEquals(round($base * $pax + (self::LGU_FEE_PER_HEAD + self::ENVIRONMENTAL_FEE_PER_HEAD) * $pax, 2), (float) $this->intents()->value('total_amount'));
        $this->assertSame(0, DB::table('booking_price_adjustments')->count());
    }

    public function test_pending_intent_holds_capacity_for_fifteen_minutes_only(): void
    {
        $before = $this->availableSlots();

        $this->startCheckout(3)->assertOk();
        $this->assertSame($before - 3, $this->availableSlots(), 'The checkout should hold 3 slots.');

        $holdExpiresAt = Carbon::parse($this->intents()->value('hold_expires_at'));
        $this->assertTrue($holdExpiresAt->equalTo(Carbon::now()->addMinutes(15)), 'The hold should last exactly 15 minutes.');

        Carbon::setTestNow(Carbon::now()->addMinutes(14)->addSeconds(59));
        $this->assertSame($before - 3, $this->availableSlots(), 'The hold should still count just before 15 minutes.');

        Carbon::setTestNow(Carbon::now()->addSeconds(2));
        $this->assertSame($before, $this->availableSlots(), 'An unpaid hold must stop consuming capacity after 15 minutes.');
    }

    public function test_failed_checkout_session_creation_creates_no_booking_and_no_active_hold(): void
    {
        $this->seasonRule(applicable: true); // so "no adjustment written" is meaningful
        $before = $this->availableSlots();
        $this->fakeCheckoutSessionFails();

        $response = $this->postJson(route('booking.store'), $this->bookingForm(2));

        $this->assertGreaterThanOrEqual(400, $response->status());
        $this->assertFalse((bool) $response->json('success'));
        foreach (['checkout_url', 'checkout_reference', 'booking_number', 'pin', 'booking_id'] as $key) {
            $this->assertNull($response->json($key), "A failed checkout must not return {$key}.");
        }

        // Nothing operational
        foreach (['bookings', 'booking_participants', 'batches', 'payments', 'booking_price_adjustments', 'participants', 'booking_status_logs'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "A failed checkout must not write to {$table}.");
        }

        // A failed intent may stay for diagnostics, but it can never hold capacity
        foreach ($this->intents()->get() as $intent) {
            $this->assertSame('failed', $intent->status, 'Only a failed intent may remain after session creation fails.');
            $this->assertTrue(
                $intent->hold_expires_at === null || Carbon::parse($intent->hold_expires_at)->lessThanOrEqualTo(Carbon::now()),
                'A failed intent must not have a live hold.'
            );
        }
        $this->assertSame(0, $this->intents()->whereIn('status', ['pending', 'paid', 'finalized'])->count());
        $this->assertSame($before, $this->availableSlots(), 'A failed checkout must not hold capacity.');

        // No confirmation or staff message of any kind
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertOnlyFakePayMongoWasCalled();
    }

    // ------------------------------------------- Batch 2B.1: shared capacity-locking protocol
    // SQLite has no advisory locks; these prove the protocol is followed, not PostgreSQL concurrency.

    private function capacityLockKey(): string
    {
        return "slot_allocation_lock:{$this->startDate}";
    }

    public function test_checkout_start_uses_the_same_capacity_protocol_as_finalization(): void
    {
        $base = DB::transactionLevel();
        $events = [];
        $slots = \Mockery::mock(SlotReservationService::class, [app(\App\Services\SystemSettingService::class)])->makePartial();
        $slots->shouldReceive('withCapacityLock')->once()->passthru();
        $slots->shouldNotReceive('withLock');
        $slots->shouldReceive('lockDateInTransaction')->once()->with($this->startDate)
            ->andReturnUsing(function () use (&$events) {
                $events[] = ['advisory_lock', DB::transactionLevel()];
            });
        $this->app->instance(SlotReservationService::class, $slots);
        DB::listen(function ($query) use (&$events) {
            if (str_contains($query->sql, 'checkout_intents')) {
                $events[] = [str_starts_with(strtolower($query->sql), 'insert') ? 'insert' : 'capacity_read', DB::transactionLevel()];
            }
        });

        $this->startCheckout(2)->assertOk();

        $this->assertSame(['advisory_lock', 'capacity_read', 'insert'], array_values(array_unique(array_column($events, 0))), 'Lock first, then count, then hold.');
        $read = collect($events)->firstWhere(0, 'capacity_read');
        $insert = collect($events)->firstWhere(0, 'insert');
        $this->assertSame($base + 1, $events[0][1], 'Advisory lock taken inside the capacity transaction.');
        $this->assertSame($base + 1, $read[1], 'Capacity counted inside the same transaction.');
        $this->assertGreaterThan($base, $insert[1], 'Hold inserted inside the same transaction (savepoint for reference retries).');
    }

    public function test_capacity_check_and_hold_roll_back_together_when_the_lease_is_lost(): void
    {
        $stolen = false;
        CheckoutIntent::created(function () use (&$stolen) {
            if (! $stolen) {
                $stolen = true; // another process takes the expired lease after the insert, before commit
                Cache::lock($this->capacityLockKey())->forceRelease();
                Cache::lock($this->capacityLockKey(), 60, 'another-process')->get();
            }
        });
        $this->fakeCheckoutSessionCreated();

        $response = $this->postJson(route('booking.store'), $this->bookingForm(2));

        $response->assertStatus(429);
        $this->assertSame(0, $this->intents()->count(), 'The hold was not committed.');
        Http::assertNothingSent();
    }

    public function test_no_paymongo_request_happens_inside_the_transaction_or_capacity_lock(): void
    {
        $base = DB::transactionLevel();
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $lock = Cache::lock($this->capacityLockKey(), 1);
            $seen[] = ['level' => DB::transactionLevel(), 'lock_free' => $lock->get(), 'intent_committed' => $this->intents()->count()];
            $lock->release();

            return Http::response(['data' => ['id' => 'cs_test_outside', 'type' => 'checkout_session',
                'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/cs_test_outside', 'status' => 'active', 'payments' => []]]], 200);
        });

        $this->postJson(route('booking.store'), $this->bookingForm(2))->assertOk();

        $this->assertNotEmpty($seen);
        foreach ($seen as $call) {
            $this->assertSame($base, $call['level'], 'No open transaction during the PayMongo call.');
            $this->assertTrue($call['lock_free'], 'Capacity lock released before the PayMongo call.');
            $this->assertSame(1, $call['intent_committed'], 'Hold committed before the PayMongo call.');
        }
    }

    public function test_busy_capacity_lock_creates_no_intent_and_calls_nothing(): void
    {
        $other = Cache::lock($this->capacityLockKey(), 60, 'another-process');
        $this->assertTrue($other->get());
        $this->fakeCheckoutSessionCreated();
        Carbon::setTestNow(); // the bounded wait (5 s) uses real time
        $started = microtime(true);

        $response = $this->postJson(route('booking.store'), $this->bookingForm(2));

        $response->assertStatus(429);
        $this->assertLessThan(10, microtime(true) - $started, 'Bounded wait.');
        $this->assertSame(0, $this->intents()->count());
        Http::assertNothingSent();
        $other->release();
    }

    public function test_feature_flag_off_keeps_the_legacy_booking_flow(): void
    {
        config(['checkout.intents_enabled' => false]);

        $this->startCheckout(2)->assertOk();

        $this->assertSame(0, $this->intents()->count(), 'Flag off: no checkout intent.');
        $this->assertSame(1, DB::table('bookings')->where('status', 'pending_downpayment')->count(), 'Flag off: legacy pending booking as before.');
    }
}
