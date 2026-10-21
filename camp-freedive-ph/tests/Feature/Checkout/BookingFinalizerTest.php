<?php

namespace Tests\Feature\Checkout;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\PricingRule;
use App\Models\SystemSetting;
use App\Services\Checkout\BookingFinalizer;
use App\Services\Checkout\FinalizationResult;
use App\Services\Checkout\PaymentVerificationResult;
use App\Services\SlotReservationService;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2B: BookingFinalizer turns a verified, paid checkout intent into exactly one official booking.
 * Synthetic data only; no HTTP (stray requests blocked); the weather service is mocked.
 */
class BookingFinalizerTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;

    private const DATE = '2026-12-19';
    private const LOCK_KEY = 'slot_allocation_lock:2026-12-19';

    private int $seq = 0;
    private int $baseTransactionLevel = 0;
    private array $weatherCallLevels = [];
    private bool $failPaymentInsert = false;
    private bool $stealLockOnParticipant = false;
    private PricingRule $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Mail::fake();
        Queue::fake();
        Notification::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));
        config(['checkout.finalization_lock_wait_seconds' => 0]); // lock-busy tests should not wait

        // Small weekend capacity so capacity cases are easy to read
        SystemSetting::create(['group' => 'camp_operations', 'key' => 'camp_operations.max_batch_capacity', 'value' => '6', 'type' => 'integer']);

        $this->rule = PricingRule::create([
            'name' => 'Synthetic season surcharge', 'rule_type' => 'seasonality', 'condition_value' => 'shoulder', 'applies_to' => 'all',
            'adjustment_type' => 'increase', 'adjustment_method' => 'percentage', 'adjustment_value' => 10.00, 'priority' => 1, 'status' => 'active',
        ]);

        // The test's own RefreshDatabase transaction is the baseline: "outside the finalizer's transactions"
        $this->baseTransactionLevel = DB::transactionLevel();
        $this->mock(WeatherForecastService::class, function ($mock) {
            $mock->shouldReceive('assessBatch')->andReturnUsing(function () {
                $this->weatherCallLevels[] = DB::transactionLevel();

                return [];
            });
        });

        // Failure injection (listeners live on this test's app only)
        Event::listen('eloquent.creating: ' . Payment::class, function () {
            if ($this->failPaymentInsert) {
                throw new \RuntimeException('Synthetic failure while recording the booking payment.');
            }
        });
        Event::listen('eloquent.creating: ' . BookingParticipant::class, function () {
            if ($this->stealLockOnParticipant) {
                $this->stealLockOnParticipant = false;
                Cache::lock(self::LOCK_KEY)->forceRelease();
                Cache::lock(self::LOCK_KEY, 60, 'another-process')->get();
            }
        });
    }

    protected function tearDown(): void
    {
        Str::createRandomStringsNormally();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** A checkout intent exactly like Batch 1 stores it (encrypted payload with pricing snapshot). */
    private function intent(array $overrides = [], int $pax = 2): CheckoutIntent
    {
        $n = ++$this->seq;
        $participants = [];
        foreach (array_slice(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot'], 0, $pax) as $i => $last) {
            $participants[] = ['name' => "Synthetic {$last}", 'birthdate' => null, 'gender' => 'prefer_not_to_say', 'age' => 26 + $i,
                'health_condition' => 'Synthetic note ' . $last, 'swimmer_status' => 'swimmer', 'price_per_person' => 4675.0];
        }

        return CheckoutIntent::create(array_merge([
            'reference' => sprintf('CHK-FINAL%07d', $n),
            'status' => CheckoutIntent::STATUS_PENDING,
            'start_date' => self::DATE,
            'end_date' => '2026-12-20',
            'pax_count' => $pax,
            'class_type' => 'discovery',
            'pickup_option' => 'own',
            'currency' => 'PHP',
            'total_amount' => 4675 * $pax + 350 * $pax,
            'downpayment_amount' => 2000 * $pax,
            'paymongo_checkout_session_id' => "cs_test_final_{$n}",
            'hold_expires_at' => now()->addMinutes(15),
            'payload' => [
                'booking' => ['class_type' => 'discovery', 'is_certified_diver' => false, 'start_date' => self::DATE, 'end_date' => '2026-12-20',
                    'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false],
                'contact' => ['name' => 'Casey Fixture', 'first_name' => 'Casey', 'middle_name' => '', 'last_name' => 'Fixture', 'suffix' => '',
                    'email' => 'casey.fixture@example.test', 'phone' => '09170000001', 'facebook' => null],
                'guardian' => ['contact_birthdate' => null, 'guardian_name' => null, 'guardian_relationship' => null, 'guardian_phone' => null, 'guardian_consent_at' => null],
                'participants' => $participants,
                'pricing' => [
                    'pricing_version' => 1, 'quote_source' => 'live_evaluation', 'base_price_per_pax' => 4250.0, 'adjusted_price_per_pax' => 4675.0,
                    'delta_per_pax' => 425.0, 'subtotal' => 4675.0 * $pax, 'was_clamped' => false,
                    'adjustments' => [['rule_id' => $this->rule->id, 'rule_name' => $this->rule->name, 'rule_type' => 'seasonality',
                        'condition_summary' => 'Season: shoulder', 'delta_per_pax' => 425.0, 'base_price' => 4250.0, 'adjusted_price' => 4675.0]],
                    'fees' => ['lgu' => 300.0 * $pax, 'environmental' => 50.0 * $pax, 'carpool' => 0.0, 'boat_dive' => 0.0],
                    'total_amount' => 4675 * $pax + 350 * $pax, 'downpayment_amount' => 2000 * $pax, 'balance_amount' => 2675 * $pax + 350 * $pax, 'currency' => 'PHP',
                ],
            ],
        ], $overrides));
    }

    private function finalize(CheckoutIntent $intent, ?PaymentVerificationResult $result = null): FinalizationResult
    {
        return app(BookingFinalizer::class)->finalize($intent->fresh(), $result ?? $this->verifiedResultFor($intent), 'test');
    }

    /** A confirmed booking that already uses $pax slots on the date. */
    private function confirmedBookingWith(int $pax): Booking
    {
        $booking = Booking::create([
            'booking_number' => 'CFP-2026-OTH' . (++$this->seq), 'pin' => '0000', 'contact_name' => 'Other Guest', 'contact_email' => 'other@example.test',
            'contact_phone' => '09170000002', 'class_type' => 'discovery', 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'status' => 'confirmed', 'total_amount' => 1000, 'downpayment_amount' => 500, 'balance_amount' => 500,
        ]);
        foreach (range(1, $pax) as $i) {
            $booking->participants()->create(['name' => "Other Diver {$i}", 'age' => 30, 'price_per_person' => 4250]);
        }

        return $booking;
    }

    private function counts(): array
    {
        return collect(['bookings', 'booking_participants', 'booking_price_adjustments', 'payments', 'batches'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function assertNothingSentOrCalled(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    // -------------------------------------------------------- successful finalization

    public function test_verified_payment_creates_exactly_one_confirmed_booking_with_all_records(): void
    {
        $intent = $this->intent();

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::FINALIZED, $result->outcome, $result->reason);
        $this->assertSame(['bookings' => 1, 'booking_participants' => 2, 'booking_price_adjustments' => 1, 'payments' => 1, 'batches' => 1], $this->counts());

        $booking = $result->booking->fresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertMatchesRegularExpression('/^CFP-2026-[A-Z0-9]{5}$/', $booking->booking_number);
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $booking->pin);
        $this->assertSame($intent->id, (int) $booking->checkout_intent_id);
        $this->assertSame(self::DATE, $booking->start_date->toDateString());
        $this->assertSame('2026-12-20', $booking->end_date->toDateString());
        $this->assertSame('discovery', $booking->class_type);
        $this->assertSame('own', $booking->pickup_option);
        $this->assertSame('Casey Fixture', $booking->contact_name);
        $this->assertSame('casey.fixture@example.test', $booking->contact_email);
        $this->assertSame('09170000001', $booking->contact_phone);
        $this->assertNotNull($booking->batch_id);

        // Participants exactly as snapshotted
        $participants = $booking->participants()->orderBy('id')->get();
        $this->assertSame(['Synthetic Alpha', 'Synthetic Bravo'], $participants->pluck('name')->all());
        $this->assertSame([26, 27], $participants->pluck('age')->all());
        $this->assertSame('Synthetic note Alpha', $participants[0]->health_condition);
        $this->assertEquals(4675.0, (float) $participants[0]->price_per_person);

        // Pricing adjustment from the snapshot
        $adjustment = $booking->priceAdjustments()->sole();
        $this->assertSame($this->rule->id, (int) $adjustment->pricing_rule_id);
        $this->assertEquals(425.0, (float) $adjustment->adjustment_amount);
        $this->assertEquals(4250.0, (float) $adjustment->base_price);
        $this->assertEquals(4675.0, (float) $adjustment->adjusted_price);

        // Money: total, verified downpayment, balance
        $this->assertEquals(10050.0, (float) $booking->total_amount);
        $this->assertEquals(9350.0, (float) $booking->subtotal);
        $this->assertEquals(600.0, (float) $booking->lgu_fee);
        $this->assertEquals(100.0, (float) $booking->environmental_fee);
        $this->assertEquals(4000.0, (float) $booking->downpayment_amount);
        $this->assertEquals(6050.0, (float) $booking->balance_amount);

        // One paid downpayment, pesos (PayMongo sent centavos)
        $payment = Payment::sole();
        $this->assertSame($booking->id, $payment->booking_id);
        $this->assertSame('paid', $payment->status);
        $this->assertSame('downpayment', $payment->payment_type);
        $this->assertSame('gcash', $payment->payment_method);
        $this->assertSame('pay_test_final_' . $intent->id, $payment->paymongo_payment_id);
        $this->assertSame($intent->paymongo_checkout_session_id, $payment->paymongo_resource_id);
        $this->assertEquals(4000.00, (float) $payment->amount);
        $this->assertEquals(75.00, (float) $payment->fee_amount);
        $this->assertEquals(3925.00, (float) $payment->net_amount);
        $this->assertNotNull($payment->paid_at);

        // Intent finalized, linked, evidence kept, hold released (the booking counts instead)
        $intent->refresh();
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->status);
        $this->assertSame($booking->id, $intent->booking_id);
        $this->assertSame($booking->batch_id, $intent->batch_id);
        $this->assertNotNull($intent->finalized_at);
        $this->assertFalse($intent->hasLiveHold());
        $this->assertSame('pay_test_final_' . $intent->id, $intent->paymongo_payment_id);
        $this->assertEquals(4000.00, (float) $intent->paid_amount);
        $this->assertEquals(75.00, (float) $intent->fee_amount);
        $this->assertSame('test', $intent->verification['source']);
        $this->assertSame(2, app(SlotReservationService::class)->getConfirmedPaxCount(self::DATE));

        // Logs without personal data; nothing sent or called
        $this->assertSame(1, DB::table('booking_status_logs')->where('booking_id', $booking->id)->where('new_status', 'confirmed')->count());
        $audit = AuditLog::where('action', 'CHECKOUT_FINALIZED')->sole();
        $this->assertStringContainsString($intent->reference, $audit->description);
        foreach (['casey', '09170000001', 'Synthetic note'] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $audit->description);
        }
        $this->assertNothingSentOrCalled();
    }

    public function test_centavos_are_converted_to_pesos_exactly(): void
    {
        $intent = $this->intent(['downpayment_amount' => 2999.99, 'total_amount' => 10050.00]);

        $this->finalize($intent, $this->verifiedResultFor($intent, ['amountCentavos' => 299999, 'feeCentavos' => 1234]));

        $payment = Payment::sole();
        $this->assertEquals(2999.99, (float) $payment->amount);
        $this->assertEquals(12.34, (float) $payment->fee_amount);
        $this->assertEquals(2987.65, (float) $payment->net_amount);
        $this->assertEquals(7050.01, (float) Booking::sole()->balance_amount);
        $this->assertEquals(2999.99, (float) $intent->fresh()->paid_amount);
    }

    public function test_existing_batch_for_the_date_is_reused_without_weather_calls(): void
    {
        $batch = Batch::create(['batch_code' => 'Batch 1', 'name' => 'Batch 1', 'start_date' => self::DATE, 'end_date' => '2026-12-20', 'status' => 'confirmed', 'max_capacity' => 6]);
        $intent = $this->intent();

        $this->finalize($intent);

        $this->assertSame($batch->id, Booking::sole()->batch_id);
        $this->assertSame(1, Batch::count());
        $this->assertSame([], $this->weatherCallLevels);
    }

    public function test_new_batch_is_created_inside_the_transaction_and_weather_runs_only_after_commit(): void
    {
        $intent = $this->intent();

        $this->finalize($intent);

        $batch = Batch::sole();
        $this->assertSame($batch->id, Booking::sole()->batch_id);
        $this->assertSame('Batch 1', $batch->fresh()->batch_code, 'Temporary batch code is renumbered after commit.');
        $this->assertSame(1, DB::table('batch_status_logs')->where('batch_id', $batch->id)->count());
        $this->assertSame([$this->baseTransactionLevel], $this->weatherCallLevels, 'Weather check runs once, outside the finalizer transactions.');
    }

    // ------------------------------------------------------------- idempotency

    public function test_repeated_finalization_returns_the_same_booking_and_writes_nothing(): void
    {
        $intent = $this->intent();
        $first = $this->finalize($intent);
        $counts = $this->counts();

        $second = $this->finalize($intent);
        $third = $this->finalize($intent);

        $this->assertSame(FinalizationResult::ALREADY_FINALIZED, $second->outcome);
        $this->assertSame(FinalizationResult::ALREADY_FINALIZED, $third->outcome);
        $this->assertSame($first->booking->id, $second->booking->id);
        $this->assertSame($counts, $this->counts());
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_FINALIZED')->count());
    }

    public function test_crash_after_commit_is_recovered_by_retrying(): void
    {
        $intent = $this->intent();
        $this->finalize($intent); // committed; pretend the caller never saw the response
        $bookingId = Booking::sole()->id;

        $retry = app(BookingFinalizer::class)->finalize(CheckoutIntent::findOrFail($intent->id), $this->verifiedResultFor($intent), 'reconcile');

        $this->assertSame(FinalizationResult::ALREADY_FINALIZED, $retry->outcome);
        $this->assertSame($bookingId, $retry->booking->id);
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_a_different_payment_for_a_finalized_intent_never_changes_the_booking(): void
    {
        $intent = $this->intent();
        $this->finalize($intent);
        $booking = Booking::sole()->toArray();

        $result = $this->finalize($intent, $this->verifiedResultFor($intent, ['paymentId' => 'pay_test_second_payment']));

        $this->assertSame(FinalizationResult::PAYMENT_REVIEW, $result->outcome);
        $this->assertSame('different_payment_for_finalized_intent', $result->reason);
        $this->assertSame($booking, Booking::sole()->toArray());
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(1, Payment::count());
    }

    // -------------------------------------------------------- duplicate payment identity

    public function test_payment_id_already_saved_on_another_intent_leads_to_review(): void
    {
        $intent = $this->intent();
        $this->intent(['paymongo_payment_id' => 'pay_test_final_' . $intent->id, 'status' => CheckoutIntent::STATUS_FAILED]);

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::PAYMENT_REVIEW, $result->outcome);
        $this->assertSame('payment_id_used_by_other_intent', $result->reason);
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $intent->fresh()->status);
        $this->assertTrue($intent->fresh()->hold_expires_at->equalTo(now()->addMinutes(15)), 'Review keeps the original hold (Batch 2B.1).');
        $this->assertSame(0, Booking::count());
    }

    public function test_payment_id_already_recorded_on_a_booking_payment_leads_to_review(): void
    {
        $intent = $this->intent();
        $other = $this->confirmedBookingWith(1);
        Payment::create(['booking_id' => $other->id, 'transaction_id' => 'TXN-OTHER', 'payment_type' => 'downpayment', 'payment_method' => 'gcash',
            'amount' => 4000, 'status' => 'paid', 'paymongo_payment_id' => 'pay_test_final_' . $intent->id]);

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::PAYMENT_REVIEW, $result->outcome);
        $this->assertSame('payment_id_used_by_payment_record', $result->reason);
        $this->assertSame(1, Booking::count()); // only the other booking
    }

    public function test_verification_for_another_intent_is_refused_without_changes(): void
    {
        $intent = $this->intent();
        $other = $this->intent();

        $result = $this->finalize($intent, $this->verifiedResultFor($other));

        $this->assertSame(FinalizationResult::PAYMENT_REVIEW, $result->outcome);
        $this->assertSame('verification_for_another_intent', $result->reason);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame(0, Booking::count());
    }

    public function test_amount_or_currency_different_from_the_intent_leads_to_review(): void
    {
        $short = $this->intent();
        $this->assertSame('amount_mismatch', $this->finalize($short, $this->verifiedResultFor($short, ['amountCentavos' => 100]))->reason);
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $short->fresh()->status);

        $usd = $this->intent();
        $this->assertSame('currency_mismatch', $this->finalize($usd, $this->verifiedResultFor($usd, ['currency' => 'USD']))->reason);
        $this->assertSame(0, Booking::count());
    }

    // ------------------------------------------------------------ verification outcomes

    public function test_no_booking_exists_before_a_verified_payment(): void
    {
        $intent = $this->intent();

        foreach ([PaymentVerificationResult::notPaid('payment_pending'), PaymentVerificationResult::retryable('provider_unavailable')] as $verification) {
            $this->finalize($intent, $verification);
        }

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Booking::where('booking_number', 'like', 'CFP-%')->count());
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_not_paid_changes_nothing(): void
    {
        $intent = $this->intent();
        $before = $intent->fresh()->toArray();

        $result = $this->finalize($intent, PaymentVerificationResult::notPaid('payment_pending'));

        $this->assertSame(FinalizationResult::NOT_PAID, $result->outcome);
        $this->assertSame($before, $intent->fresh()->toArray());
        $this->assertSame(['bookings' => 0, 'booking_participants' => 0, 'booking_price_adjustments' => 0, 'payments' => 0, 'batches' => 0], $this->counts());
    }

    public function test_retryable_verification_keeps_the_intent_for_a_later_attempt(): void
    {
        $intent = $this->intent();
        $before = $intent->fresh()->toArray();

        $result = $this->finalize($intent, PaymentVerificationResult::retryable('provider_unavailable'));

        $this->assertSame(FinalizationResult::RETRYABLE, $result->outcome);
        $this->assertSame('provider_unavailable', $result->reason);
        $this->assertSame($before, $intent->fresh()->toArray());
    }

    public function test_needs_review_records_payment_review_and_keeps_the_original_hold(): void
    {
        $intent = $this->intent();
        $slots = app(SlotReservationService::class);
        $heldBefore = $slots->getCheckoutIntentHoldPaxCount(self::DATE);

        $result = $this->finalize($intent, PaymentVerificationResult::needsReview('amount_mismatch', ['expected_centavos' => 400000, 'actual_centavos' => 100]));

        $this->assertSame(FinalizationResult::PAYMENT_REVIEW, $result->outcome);
        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $fresh->status);
        $this->assertSame('amount_mismatch', $fresh->failure_reason);
        $this->assertSame(['expected_centavos' => 400000, 'actual_centavos' => 100], $fresh->verification['review_history'][0]['details']);
        $this->assertTrue($fresh->hold_expires_at->equalTo(now()->addMinutes(15)), 'Original hold kept, not extended.');
        $this->assertSame($heldBefore, $slots->getCheckoutIntentHoldPaxCount(self::DATE), 'The customer may have paid: still holds capacity.');
        $this->assertSame(0, Booking::count());
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_PAYMENT_REVIEW')->count());
    }

    public function test_needs_review_never_downgrades_a_finalized_intent(): void
    {
        $intent = $this->intent();
        $this->finalize($intent);

        $result = $this->finalize($intent, PaymentVerificationResult::needsReview('mode_mismatch'));

        $this->assertSame(FinalizationResult::PAYMENT_REVIEW, $result->outcome);
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(1, Booking::count());
    }

    // -------------------------------------------------------------- capacity and grace

    public function test_own_hold_is_left_out_when_finalizing_the_last_slots(): void
    {
        // Capacity 6: 2 confirmed + another pending hold of 2 + this intent's hold of 2 = full
        $this->confirmedBookingWith(2);
        $this->intent([], 2); // another pending hold
        $intent = $this->intent([], 2);
        $this->assertSame(0, app(SlotReservationService::class)->getAvailableSlots(self::DATE));

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::FINALIZED, $result->outcome, $result->reason);
        $this->assertSame(0, app(SlotReservationService::class)->getAvailableSlots(self::DATE), 'Still full: the booking replaced the hold.');
    }

    public function test_late_payment_with_capacity_is_finalized(): void
    {
        $intent = $this->intent(['hold_expires_at' => now()->subMinutes(30)]);

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::FINALIZED, $result->outcome, $result->reason);
    }

    public function test_late_payment_without_capacity_becomes_paid_unallocated_and_keeps_the_evidence(): void
    {
        $this->confirmedBookingWith(5);
        $intent = $this->intent(['hold_expires_at' => now()->subMinutes(30)], 2);

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::PAID_UNALLOCATED, $result->outcome);
        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $fresh->status);
        $this->assertSame('capacity_unavailable', $fresh->unallocated_reason);
        $this->assertSame('pay_test_final_' . $intent->id, $fresh->paymongo_payment_id);
        $this->assertEquals(4000.00, (float) $fresh->paid_amount);
        $this->assertSame('pay_test_final_' . $intent->id, $fresh->verification['payment_id']);
        $this->assertFalse($fresh->hasLiveHold());
        $this->assertNull($fresh->booking_id);
        $this->assertSame(1, Booking::count()); // only the other booking: never overbooked
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_PAID_UNALLOCATED')->count());

        // Stays visible for staff and repeated calls do not change it
        $this->assertSame(1, CheckoutIntent::where('status', CheckoutIntent::STATUS_PAID_UNALLOCATED)->count());
        $this->assertSame(FinalizationResult::PAID_UNALLOCATED, $this->finalize($intent)->outcome);
        $this->assertSame(1, Booking::count());
    }

    public function test_grace_extends_a_live_hold_after_verification_and_is_bounded(): void
    {
        $this->failPaymentInsert = true; // stop after Phase A so the paid state can be inspected
        $soon = $this->intent(['hold_expires_at' => now()->addMinutes(2)]);
        $this->finalize($soon);
        $this->assertSame(CheckoutIntent::STATUS_PAID, $soon->fresh()->status);
        $this->assertTrue($soon->fresh()->hold_expires_at->equalTo(now()->addMinutes(10)), 'A short remaining hold is extended to verification + 10 minutes.');

        $later = $this->intent(['hold_expires_at' => now()->addMinutes(14)]);
        $this->finalize($later);
        $this->assertTrue($later->fresh()->hold_expires_at->equalTo(now()->addMinutes(14)), 'A longer remaining hold is never shortened.');

        $expired = $this->intent(['hold_expires_at' => now()->subMinute()]);
        $this->finalize($expired);
        $this->assertFalse($expired->fresh()->hasLiveHold(), 'A late payment gets no new hold.');
    }

    public function test_paid_intent_whose_grace_expired_competes_fairly_on_retry(): void
    {
        $this->failPaymentInsert = true;
        $intent = $this->intent([], 2);
        $this->finalize($intent); // Phase A done, Phase B failed: paid with grace hold
        $this->assertSame(CheckoutIntent::STATUS_PAID, $intent->fresh()->status);

        // Grace passes; meanwhile others take every remaining slot
        Carbon::setTestNow(now()->addMinutes(16));
        $this->confirmedBookingWith(6);
        $this->failPaymentInsert = false;

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::PAID_UNALLOCATED, $result->outcome, 'No overbooking once the grace has expired.');
        $this->assertSame(1, Booking::count());
    }

    // --------------------------------------------------------------- locks and failures

    public function test_busy_capacity_lock_is_retryable_and_changes_nothing(): void
    {
        $intent = $this->intent();
        $other = Cache::lock(self::LOCK_KEY, 60, 'another-process');
        $this->assertTrue($other->get());

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::RETRYABLE, $result->outcome);
        $this->assertSame('capacity_lock_unavailable', $result->reason);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertNull($intent->fresh()->paymongo_payment_id);

        $other->release();
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
    }

    public function test_lost_lock_lease_rolls_back_the_booking_and_a_retry_completes(): void
    {
        $intent = $this->intent();
        $this->stealLockOnParticipant = true; // the lease is taken over while the booking is being written

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::RETRYABLE, $result->outcome);
        $this->assertSame('capacity_lock_lost', $result->reason);
        $this->assertSame(0, Booking::count());
        $this->assertSame(CheckoutIntent::STATUS_PAID, $intent->fresh()->status, 'Phase A evidence survives.');

        Cache::lock(self::LOCK_KEY)->forceRelease();
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
        $this->assertSame(1, Booking::count());
    }

    public function test_failure_halfway_through_rolls_back_every_booking_record_but_keeps_the_payment(): void
    {
        $intent = $this->intent();
        $this->failPaymentInsert = true;

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::RETRYABLE, $result->outcome);
        $this->assertSame('booking_creation_failed', $result->reason);
        $this->assertSame(['bookings' => 0, 'booking_participants' => 0, 'booking_price_adjustments' => 0, 'payments' => 0, 'batches' => 0], $this->counts());
        $this->assertSame(0, DB::table('participants')->count(), 'Directory links roll back too.');
        $this->assertSame(0, DB::table('batch_status_logs')->count());
        $this->assertSame([], $this->weatherCallLevels);

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAID, $fresh->status);
        $this->assertSame('pay_test_final_' . $intent->id, $fresh->paymongo_payment_id);
        $this->assertNull($fresh->booking_id);

        // Retry after the fault is gone completes exactly once
        $this->failPaymentInsert = false;
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
        $this->assertSame(['bookings' => 1, 'booking_participants' => 2, 'booking_price_adjustments' => 1, 'payments' => 1, 'batches' => 1], $this->counts());
    }

    public function test_booking_number_collision_is_retried_in_a_savepoint(): void
    {
        $existing = $this->confirmedBookingWith(1);
        $existing->update(['booking_number' => 'CFP-2026-AAAAA']);
        // Only 5-character strings are booking-number suffixes (lock owners etc. use other lengths)
        $suffixes = ['AAAAA', 'BBBBB'];
        Str::createRandomStringsUsing(function (int $length) use (&$suffixes) {
            return $length === 5 && $suffixes ? array_shift($suffixes) : substr(bin2hex(random_bytes($length)), 0, $length);
        });
        $intent = $this->intent();

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::FINALIZED, $result->outcome, $result->reason);
        $this->assertSame('CFP-2026-BBBBB', $result->booking->booking_number);
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(2, Booking::count());
    }

    // ------------------------------------------------- Batch 2B.1: review holds, recovery, evidence

    /** Phase A done, Phase B failed once: a `paid` intent with its grace hold. */
    private function paidButNotFinalized(int $pax = 2): CheckoutIntent
    {
        $this->failPaymentInsert = true;
        $intent = $this->intent([], $pax);
        $this->finalize($intent);
        $this->failPaymentInsert = false;
        $this->assertSame(CheckoutIntent::STATUS_PAID, $intent->fresh()->status);

        return $intent->fresh();
    }

    private function auditCount(string $action): int
    {
        return AuditLog::where('action', $action)->count();
    }

    public function test_review_hold_expires_naturally_at_its_original_time(): void
    {
        $slots = app(SlotReservationService::class);
        $max = $slots->getAvailableSlots(self::DATE);
        $intent = $this->intent([], 3);
        $this->finalize($intent, PaymentVerificationResult::needsReview('session_not_found'));

        Carbon::setTestNow(now()->addMinutes(14)->addSeconds(59));
        $this->assertSame($max - 3, $slots->getAvailableSlots(self::DATE), 'Review hold still counts before the original expiry.');

        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertSame($max, $slots->getAvailableSlots(self::DATE), 'Review hold stops counting at the original expiry.');
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $intent->fresh()->status, 'Still visible for staff.');
    }

    public function test_later_fresh_verified_payment_recovers_a_review_intent(): void
    {
        $intent = $this->intent();
        $this->finalize($intent, PaymentVerificationResult::needsReview('session_not_found', ['checkout_session_id' => $intent->paymongo_checkout_session_id]));
        Carbon::setTestNow(now()->addMinutes(5));

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::FINALIZED, $result->outcome, $result->reason);
        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $fresh->status);
        $this->assertNull($fresh->failure_reason);
        $this->assertSame('pay_test_final_' . $intent->id, $fresh->verification['payment_id'], 'Fresh verified evidence saved.');
        $this->assertSame('session_not_found', $fresh->verification['review_history'][0]['reason'], 'Review history kept.');
        $this->assertSame(1, $this->auditCount('CHECKOUT_REVIEW_RECOVERED'));
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_recovery_excludes_its_own_review_hold_when_the_date_is_otherwise_full(): void
    {
        $this->confirmedBookingWith(4);
        $intent = $this->intent([], 2);
        $this->finalize($intent, PaymentVerificationResult::needsReview('session_not_found'));
        $this->assertSame(0, app(SlotReservationService::class)->getAvailableSlots(self::DATE));

        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
        $this->assertSame(6, app(SlotReservationService::class)->getConfirmedPaxCount(self::DATE));
    }

    public function test_recovery_after_the_review_hold_expired_without_capacity_becomes_paid_unallocated(): void
    {
        $intent = $this->intent([], 2);
        $this->finalize($intent, PaymentVerificationResult::needsReview('session_not_found'));
        Carbon::setTestNow(now()->addMinutes(20)); // review hold gone
        $this->confirmedBookingWith(5);

        $result = $this->finalize($intent);

        $this->assertSame(FinalizationResult::PAID_UNALLOCATED, $result->outcome);
        $fresh = $intent->fresh();
        $this->assertSame('pay_test_final_' . $intent->id, $fresh->paymongo_payment_id, 'Verified payment kept, never discarded.');
        $this->assertEquals(4000.00, (float) $fresh->paid_amount);
        $this->assertNull($fresh->refund_status, 'Never refunded automatically.');
        $this->assertSame(1, Booking::count());
    }

    public function test_recovery_rechecks_payment_identity_amount_and_currency(): void
    {
        $intent = $this->intent();
        $this->finalize($intent, PaymentVerificationResult::needsReview('session_not_found'));

        // Payment id already used elsewhere: stays in review
        $holder = $this->intent(['paymongo_payment_id' => 'pay_test_final_' . $intent->id, 'status' => CheckoutIntent::STATUS_FAILED]);
        $this->assertSame('payment_id_used_by_other_intent', $this->finalize($intent)->reason);
        $holder->update(['paymongo_payment_id' => null]);

        // Wrong amount, wrong currency, wrong session: stays in review
        $this->assertSame('amount_mismatch', $this->finalize($intent, $this->verifiedResultFor($intent, ['amountCentavos' => 100]))->reason);
        $this->assertSame('currency_mismatch', $this->finalize($intent, $this->verifiedResultFor($intent, ['currency' => 'USD']))->reason);
        $this->assertSame('session_mismatch', $this->finalize($intent, $this->verifiedResultFor($intent, ['checkoutSessionId' => 'cs_test_other']))->reason);

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $fresh->status);
        $this->assertNull($fresh->paymongo_payment_id);
        $this->assertSame(['session_not_found', 'payment_id_used_by_other_intent', 'amount_mismatch', 'currency_mismatch', 'session_mismatch'],
            array_column($fresh->verification['review_history'], 'reason'));
        $this->assertSame(0, Booking::count());

        // A clean verification afterwards still recovers it
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
    }

    public function test_paid_intent_is_not_downgraded_by_a_later_inconclusive_verification(): void
    {
        $intent = $this->paidButNotFinalized();
        $evidence = $intent->verification;
        $hold = $intent->hold_expires_at;

        $result = $this->finalize($intent, PaymentVerificationResult::needsReview('session_not_found'));

        $this->assertSame(FinalizationResult::RETRYABLE, $result->outcome);
        $this->assertSame('session_not_found', $result->reason);
        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAID, $fresh->status);
        $this->assertTrue($fresh->hold_expires_at->equalTo($hold), 'Grace hold untouched.');
        $this->assertSame($evidence, array_diff_key($fresh->verification, ['review_history' => true]), 'Verified evidence not overwritten.');
        $this->assertSame('session_not_found', $fresh->verification['review_history'][0]['reason']);
        $this->assertSame('paid', $fresh->verification['review_history'][0]['intent_status']);
        $this->assertSame(1, $this->auditCount('CHECKOUT_PAYMENT_REVIEW'));

        // The paid intent still finalizes on the next verified attempt
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($intent)->outcome);
    }

    public function test_second_payment_on_a_paid_intent_is_recorded_and_never_replaces_the_first(): void
    {
        $intent = $this->paidButNotFinalized();

        $result = $this->finalize($intent, $this->verifiedResultFor($intent, ['paymentId' => 'pay_test_second_payment']));

        $this->assertSame('different_payment_for_paid_intent', $result->reason);
        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAID, $fresh->status);
        $this->assertSame('pay_test_final_' . $intent->id, $fresh->paymongo_payment_id);
        $this->assertSame('pay_test_second_payment', $fresh->verification['review_history'][0]['payment_id']);
    }

    public function test_finalized_intent_keeps_its_booking_and_records_suspicious_later_results(): void
    {
        $intent = $this->intent();
        $this->finalize($intent);
        $booking = Booking::sole()->toArray();
        $payment = Payment::sole()->toArray();

        $this->finalize($intent, PaymentVerificationResult::needsReview('mode_mismatch', ['checkout_session_id' => $intent->paymongo_checkout_session_id]));
        $result = $this->finalize($intent, $this->verifiedResultFor($intent, ['paymentId' => 'pay_test_second_payment']));

        $this->assertSame(FinalizationResult::PAYMENT_REVIEW, $result->outcome);
        $this->assertSame('different_payment_for_finalized_intent', $result->reason);
        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $fresh->status);
        $this->assertSame('pay_test_final_' . $intent->id, $fresh->paymongo_payment_id, 'Legitimate payment id never overwritten.');
        $this->assertSame($booking, Booking::sole()->toArray());
        $this->assertSame($payment, Payment::sole()->toArray());

        $events = $fresh->verification['review_history'];
        $this->assertSame(['mode_mismatch', 'different_payment_for_finalized_intent'], array_column($events, 'reason'));
        $second = $events[1];
        $this->assertSame('pay_test_second_payment', $second['payment_id']);
        $this->assertSame('finalized', $second['intent_status']);
        $this->assertTrue($second['booking_finalized']);
        $this->assertNotNull($second['verified_at']);
        $this->assertNotNull($second['recorded_at']);

        $audit = AuditLog::where('action', 'CHECKOUT_PAYMENT_REVIEW')->latest('id')->first();
        foreach ([$intent->reference, 'different_payment_for_finalized_intent', 'pay_test_second_payment', 'booking finalized: yes', 'State: finalized'] as $expected) {
            $this->assertStringContainsString($expected, $audit->description);
        }
        foreach (['casey', '09170000001', 'Synthetic note', self::FAKE_SECRET_KEY] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $audit->description);
        }
    }

    public function test_repeated_review_events_are_counted_not_duplicated(): void
    {
        $intent = $this->intent();
        $this->finalize($intent);

        foreach (range(1, 3) as $ignored) {
            $this->finalize($intent, $this->verifiedResultFor($intent, ['paymentId' => 'pay_test_second_payment']));
        }
        $this->finalize($intent, $this->verifiedResultFor($intent, ['paymentId' => 'pay_test_third_payment']));

        $history = $intent->fresh()->verification['review_history'];
        $this->assertCount(2, $history);
        $this->assertSame(3, $history[0]['occurrences']);
        $this->assertSame(1, $history[1]['occurrences']);
        $this->assertSame(2, $this->auditCount('CHECKOUT_PAYMENT_REVIEW'), 'One audit row per distinct event.');
    }

    public function test_review_details_are_sanitized(): void
    {
        $intent = $this->intent();

        $this->finalize($intent, PaymentVerificationResult::needsReview('unexpected_payment_status', [
            'checkout_session_id' => 'cs_test_safe',
            'status' => 'refunded',
            'authorization' => 'Basic ' . base64_encode(self::FAKE_SECRET_KEY . ':'),
            'raw_response' => ['data' => ['attributes' => ['billing' => ['email' => 'casey.fixture@example.test']]]],
            'card_last4' => '4242',
        ]));

        $event = $intent->fresh()->verification['review_history'][0];
        $this->assertSame(['checkout_session_id' => 'cs_test_safe', 'status' => 'refunded'], $event['details']);
        $stored = json_encode($intent->fresh()->verification);
        foreach ([self::FAKE_SECRET_KEY, 'Basic ', 'casey', '4242'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    public function test_live_review_hold_prevents_overbooking_by_a_late_payment(): void
    {
        $this->confirmedBookingWith(2);
        $review = $this->intent([], 2);
        $this->finalize($review, PaymentVerificationResult::needsReview('session_not_found'));
        $late = $this->intent(['hold_expires_at' => now()->subMinutes(30)], 2);
        $this->confirmedBookingWith(1); // 2 + 1 confirmed + 2 review hold = 5 of 6

        $this->assertSame(FinalizationResult::PAID_UNALLOCATED, $this->finalize($late)->outcome, 'The review customer keeps their slots.');
        $this->assertSame(FinalizationResult::FINALIZED, $this->finalize($review)->outcome);
        $this->assertLessThanOrEqual(6, app(SlotReservationService::class)->getConfirmedPaxCount(self::DATE));
    }

    // ------------------------------------------------------------------ boundaries

    public function test_feature_flag_stays_off_by_default(): void
    {
        $this->assertFalse((bool) config('checkout.intents_enabled'));
    }
}
