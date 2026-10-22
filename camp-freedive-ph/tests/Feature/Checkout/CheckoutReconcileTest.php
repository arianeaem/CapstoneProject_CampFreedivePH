<?php

namespace Tests\Feature\Checkout;

use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\Checkout\BookingFinalizer;
use App\Services\Checkout\CheckoutReconciler;
use App\Services\Checkout\PaymentVerificationResult;
use App\Services\Gateways\PayMongoGateway;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2C.2: checkout:reconcile recovers payments whose return and webhook were missed, and expires abandoned
 * unpaid checkouts without ever losing a payment. Synthetic data; PayMongo faked; stray HTTP throws.
 */
class CheckoutReconcileTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;

    private const DATE = '2026-12-19';
    private const API = 'api.paymongo.com/v1/checkout_sessions/';

    private int $seq = 0;
    private bool $failPaymentInsert = false;
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));
        config(['checkout.reconcile_enabled' => true, 'checkout.finalization_lock_wait_seconds' => 0]);
        SystemSetting::create(['group' => 'camp_operations', 'key' => 'camp_operations.max_batch_capacity', 'value' => '6', 'type' => 'integer']);
        $this->mock(WeatherForecastService::class, fn ($mock) => $mock->shouldReceive('assessBatch')->andReturn([]));
        Event::listen('eloquent.creating: ' . Payment::class, function () {
            if ($this->failPaymentInsert) {
                throw new \RuntimeException('Synthetic failure while recording the booking payment.');
            }
        });
        Event::listen(MessageLogged::class, fn (MessageLogged $e) => $this->logs[] = $e->message . ' ' . json_encode($e->context));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** A checkout created $ageMinutes ago (hold of 15 minutes from creation unless overridden). */
    private function intent(int $ageMinutes = 30, array $overrides = [], int $pax = 2): CheckoutIntent
    {
        $n = ++$this->seq;
        $created = now()->subMinutes($ageMinutes);
        $intent = CheckoutIntent::create(array_merge([
            'reference' => 'CHK-RC' . str_pad((string) $n, 10, '0', STR_PAD_LEFT),
            'status' => CheckoutIntent::STATUS_PENDING, 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'pax_count' => $pax, 'class_type' => 'discovery', 'pickup_option' => 'own', 'currency' => 'PHP',
            'total_amount' => 4600 * $pax, 'downpayment_amount' => 2000 * $pax,
            'paymongo_checkout_session_id' => "cs_test_rc_{$n}", 'hold_expires_at' => $created->copy()->addMinutes(15),
            'payload' => [
                'booking' => ['class_type' => 'discovery', 'is_certified_diver' => false, 'start_date' => self::DATE, 'end_date' => '2026-12-20', 'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false],
                'contact' => ['name' => 'Casey Fixture', 'email' => 'casey.fixture@example.test', 'phone' => '09170000001', 'facebook' => null],
                'guardian' => [],
                'participants' => array_map(fn ($i) => ['name' => "Synthetic Diver {$i}", 'birthdate' => null, 'gender' => 'prefer_not_to_say', 'age' => 30,
                    'health_condition' => 'Synthetic asthma note', 'swimmer_status' => 'swimmer', 'price_per_person' => 4250.0], range(1, $pax)),
                'pricing' => ['pricing_version' => 1, 'subtotal' => 4250.0 * $pax, 'adjustments' => [],
                    'fees' => ['lgu' => 300.0 * $pax, 'environmental' => 50.0 * $pax, 'carpool' => 0.0, 'boat_dive' => 0.0],
                    'total_amount' => 4600 * $pax, 'downpayment_amount' => 2000 * $pax],
            ],
        ], $overrides));
        DB::table('checkout_intents')->where('id', $intent->id)->update(['created_at' => $created, 'updated_at' => $created]);

        return $intent->fresh();
    }

    /** Payment verified and saved (Phase A), booking creation failed once (Phase B): status paid. */
    private function paidIntent(int $ageMinutes = 30, array $overrides = []): CheckoutIntent
    {
        $intent = $this->intent($ageMinutes, $overrides);
        $this->failPaymentInsert = true;
        app(BookingFinalizer::class)->finalize($intent, $this->verifiedResultFor($intent), 'return');
        $this->failPaymentInsert = false;
        DB::table('checkout_intents')->where('id', $intent->id)->update(['updated_at' => now()->subMinutes(5)]);
        $this->assertSame(CheckoutIntent::STATUS_PAID, $intent->fresh()->status);

        return $intent->fresh();
    }

    private function reviewIntent(string $reason, int $ageMinutes = 30): CheckoutIntent
    {
        $intent = $this->intent($ageMinutes);
        app(BookingFinalizer::class)->finalize($intent, PaymentVerificationResult::needsReview($reason), 'webhook');

        return $intent->fresh();
    }

    private function confirmedBookingWith(int $pax): void
    {
        $booking = Booking::create(['booking_number' => 'CFP-2026-OTH' . (++$this->seq), 'pin' => '0000', 'contact_name' => 'Other Guest',
            'contact_email' => 'other@example.test', 'contact_phone' => '09170000002', 'class_type' => 'discovery', 'start_date' => self::DATE,
            'end_date' => '2026-12-20', 'status' => 'confirmed', 'total_amount' => 1, 'downpayment_amount' => 1, 'balance_amount' => 0]);
        foreach (range(1, $pax) as $i) {
            $booking->participants()->create(['name' => "Other Diver {$i}", 'age' => 30, 'price_per_person' => 4250]);
        }
    }

    /** PayMongo answers each listed intent's session read with these bodies, in order (then repeats the last). */
    private function provider(array $answers): void
    {
        $fakes = [];
        foreach ($answers as [$intent, $bodies]) {
            $sequence = Http::sequence();
            foreach ($bodies as $body) {
                $body === 'fail' ? $sequence->pushFailedConnection() : $sequence->push($body, 200);
            }
            $last = end($bodies);
            $sequence->whenEmpty($last === 'fail' ? Http::response([], 503) : Http::response($last, 200));
            $fakes[self::API . $intent->paymongo_checkout_session_id] = $sequence;
        }
        Http::fake($fakes);
    }

    private function unpaidBody(CheckoutIntent $intent): array
    {
        return $this->sessionBody($intent, []);
    }

    /** Session read after PayMongo closed it (Batch 2C.2.2B: local expiry needs this confirmation). */
    private function closedBody(CheckoutIntent $intent): array
    {
        return $this->sessionBody($intent, [], ['attributes' => ['status' => 'expired']]);
    }

    private function expireEndpoint(CheckoutIntent $intent): string
    {
        return self::API . $intent->paymongo_checkout_session_id . '/expire';
    }

    private function pendingPaymentBody(CheckoutIntent $intent): array
    {
        return $this->sessionBody($intent, [['id' => 'pay_test_pending_' . $intent->id, 'type' => 'payment',
            'attributes' => ['amount' => (int) round($intent->downpayment_amount * 100), 'currency' => 'PHP', 'status' => 'pending', 'livemode' => false]]]);
    }

    private function reconcile(array $options = [])
    {
        return $this->artisan('checkout:reconcile', $options);
    }

    private function dueIds(int $limit = 100): array
    {
        return collect(app(CheckoutReconciler::class)->candidates($limit))->map(fn ($intents) => collect($intents)->pluck('id')->all())->all();
    }

    private function sessionReads(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'GET')->count();
    }

    private function assertNoSideEffects(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, DB::table('refund_requests')->count());
        $this->assertSame(0, CheckoutIntent::whereNotNull('refund_status')->count());
    }

    // ---------------------------------------------------------- candidate selection

    public function test_candidate_selection_by_group(): void
    {
        $recent = $this->intent(3);
        $old = $this->intent(30);
        $noSession = $this->intent(30, ['paymongo_checkout_session_id' => null]);
        $paid = $this->paidIntent(30);
        $transient = $this->reviewIntent('session_not_found');
        $permanent = $this->reviewIntent('amount_mismatch');
        $finalized = $this->intent(30, ['status' => CheckoutIntent::STATUS_FINALIZED]);
        $unallocated = $this->intent(30, ['status' => CheckoutIntent::STATUS_PAID_UNALLOCATED]);

        $due = $this->dueIds();

        $this->assertSame([$paid->id], $due['paid']);
        $this->assertSame([$old->id], $due['pending'], 'Recent and session-less pending checkouts are left alone.');
        $this->assertSame([$transient->id], $due['review'], 'Permanent mismatches are never retried automatically.');
        $this->assertSame([], $due['expired']);
        foreach ([$recent, $noSession, $permanent, $finalized, $unallocated] as $excluded) {
            $this->assertNotContains($excluded->id, array_merge(...array_values($due)));
        }
    }

    // ------------------------------------------------------------------- recovery

    public function test_missed_return_and_webhook_are_recovered_into_a_booking(): void
    {
        $intent = $this->intent(30);
        $this->provider([[$intent, [$this->paidSessionBody($intent)]]]);

        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame('reconcile', $intent->fresh()->verification['source']);
        $this->assertSame(1, Booking::count());
        $this->assertNoSideEffects();
    }

    public function test_missed_callbacks_without_capacity_become_paid_unallocated(): void
    {
        $this->confirmedBookingWith(5);
        $intent = $this->intent(30);
        $this->provider([[$intent, [$this->paidSessionBody($intent)]]]);

        $this->reconcile()->expectsOutputToContain('paid_unallocated=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $intent->fresh()->status);
        $this->assertNotNull($intent->fresh()->paymongo_payment_id);
        $this->assertSame(1, Booking::count());
    }

    public function test_paid_intent_is_resumed_from_saved_evidence_without_calling_paymongo(): void
    {
        $intent = $this->paidIntent(30);
        $evidence = $intent->verification;
        Http::fake(); // any PayMongo read would be recorded

        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertSame(0, $this->sessionReads());
        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $fresh->status);
        $this->assertSame($evidence, $fresh->verification, 'Verified evidence kept as it was.');
        $this->assertSame($fresh->paymongo_payment_id, Payment::sole()->paymongo_payment_id);
    }

    public function test_repeated_runs_never_duplicate_bookings_or_payments(): void
    {
        $intent = $this->intent(30);
        $this->provider([[$intent, [$this->paidSessionBody($intent)]]]);

        $this->reconcile()->assertSuccessful();
        $this->reconcile()->assertSuccessful();
        Carbon::setTestNow(now()->addHours(2));
        $this->reconcile()->assertSuccessful();

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $this->sessionReads(), 'Finalized checkouts are not polled again.');
    }

    public function test_reconciliation_and_customer_return_never_double_book(): void
    {
        $first = $this->intent(30);
        $second = $this->intent(30);
        $this->provider([[$first, [$this->paidSessionBody($first)]], [$second, [$this->paidSessionBody($second)]]]);

        $this->get(PayMongoGateway::signedIntentUrl('checkout.return', $first))->assertSee('Booking confirmed'); // return first
        $this->reconcile()->assertSuccessful();                                                                     // then reconcile
        $this->get(PayMongoGateway::signedIntentUrl('checkout.return', $second))->assertSee('Booking confirmed');   // return after reconcile

        $this->assertSame(1, Booking::where('checkout_intent_id', $first->id)->count());
        $this->assertSame(1, Booking::where('checkout_intent_id', $second->id)->count());
        $this->assertSame(2, Payment::count());
    }

    public function test_reconciliation_and_webhook_never_double_book(): void
    {
        $first = $this->intent(30);
        $second = $this->intent(30);
        $this->provider([[$first, [$this->paidSessionBody($first)]], [$second, [$this->paidSessionBody($second)]]]);
        $event = fn (CheckoutIntent $i) => ['data' => ['id' => 'evt_rc_' . $i->id, 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => [
            'id' => $i->paymongo_checkout_session_id, 'type' => 'checkout_session',
            'attributes' => ['metadata' => ['checkout_intent_id' => (string) $i->id, 'checkout_reference' => $i->reference]]]]]];

        $this->postSignedPayMongoWebhook($event($first))->assertOk();
        $this->reconcile()->assertSuccessful();
        $this->postSignedPayMongoWebhook($event($second))->assertOk()->assertJson(['status' => 'already_finalized']);

        $this->assertSame(2, Booking::count());
        $this->assertSame(2, Payment::count());
    }

    public function test_provider_outage_keeps_everything_and_retries_after_backoff(): void
    {
        $intent = $this->intent(30);
        $this->provider([[$intent, ['fail', $this->paidSessionBody($intent)]]]);

        $this->reconcile()->expectsOutputToContain('retryable=1')->assertSuccessful();
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame([], $this->dueIds()['pending'], 'Backing off: not polled every run.');

        $this->reconcile()->assertSuccessful();
        $this->assertSame(1, $this->sessionReads());

        Carbon::setTestNow(now()->addMinutes(6));
        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
    }

    public function test_lock_contention_is_retryable_and_changes_nothing(): void
    {
        $intent = $this->paidIntent(30);
        $other = Cache::lock('slot_allocation_lock:' . self::DATE, 60, 'another-process');
        $this->assertTrue($other->get());

        $this->reconcile()->expectsOutputToContain('retryable=1')->assertSuccessful();
        $this->assertSame(CheckoutIntent::STATUS_PAID, $intent->fresh()->status);

        $other->release();
        Carbon::setTestNow(now()->addMinutes(6));
        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();
    }

    public function test_failed_finalization_remains_retryable(): void
    {
        $intent = $this->paidIntent(30);
        $this->failPaymentInsert = true;
        Http::fake();

        $this->reconcile()->expectsOutputToContain('retryable=1')->assertSuccessful();
        $this->assertSame(CheckoutIntent::STATUS_PAID, $intent->fresh()->status);
        $this->assertSame(0, Booking::count());

        $this->failPaymentInsert = false;
        Carbon::setTestNow(now()->addMinutes(6));
        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();
        $this->assertSame(1, Booking::count());
    }

    public function test_transient_review_is_recovered_and_keeps_its_history(): void
    {
        $intent = $this->reviewIntent('session_not_found');
        $this->provider([[$intent, [$this->paidSessionBody($intent)]]]);

        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $fresh->status);
        $this->assertSame('session_not_found', $fresh->verification['review_history'][0]['reason']);
    }

    public function test_permanent_review_is_left_for_staff(): void
    {
        $intent = $this->reviewIntent('amount_mismatch');
        Http::fake();

        $this->reconcile()->expectsOutputToContain('candidates=0')->assertSuccessful();

        $this->assertSame(0, $this->sessionReads());
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $intent->fresh()->status);
    }

    // ---------------------------------------------------------- expiry and late payment

    public function test_expired_hold_never_erases_a_verified_payment_or_promises_a_slot(): void
    {
        $intent = $this->paidIntent(26 * 60); // hold long over
        $this->confirmedBookingWith(5);       // and the date has filled up since
        Http::fake();

        $this->reconcile()->expectsOutputToContain('paid_unallocated=1')->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $fresh->status, 'Never expired, never confirmed without room.');
        $this->assertNotNull($fresh->paymongo_payment_id);
        $this->assertEquals(4000.00, (float) $fresh->paid_amount);
        $this->assertNull($fresh->expired_at);
        $this->assertNoSideEffects();
    }

    public function test_only_verified_unpaid_old_checkouts_are_expired(): void
    {
        $abandoned = $this->intent(25 * 60);   // no payment at all
        $stillPaying = $this->intent(25 * 60); // a payment is still pending at PayMongo
        $young = $this->intent(60);            // unpaid, but not old enough
        $unreachable = $this->intent(25 * 60); // PayMongo cannot be read
        $this->provider([
            [$abandoned, [$this->unpaidBody($abandoned), $this->closedBody($abandoned)]],
            [$stillPaying, [$this->pendingPaymentBody($stillPaying)]],
            [$young, [$this->unpaidBody($young)]],
            [$unreachable, ['fail']],
        ]);
        Http::fake([$this->expireEndpoint($abandoned) => Http::response($this->closedBody($abandoned))]);

        $this->reconcile()->expectsOutputToContain('expired=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_EXPIRED, $abandoned->fresh()->status);
        $this->assertNotNull($abandoned->fresh()->expired_at);
        foreach ([$stillPaying, $young, $unreachable] as $kept) {
            $this->assertSame(CheckoutIntent::STATUS_PENDING, $kept->fresh()->status);
        }
        $this->assertNoSideEffects();
    }

    public function test_late_payment_after_expiry_is_still_recovered(): void
    {
        $intent = $this->intent(25 * 60);
        $this->provider([[$intent, [$this->unpaidBody($intent), $this->closedBody($intent), $this->paidSessionBody($intent)]]]);
        Http::fake([$this->expireEndpoint($intent) => Http::response($this->closedBody($intent))]);
        $this->reconcile()->expectsOutputToContain('expired=1')->assertSuccessful();
        $this->assertSame(CheckoutIntent::STATUS_EXPIRED, $intent->fresh()->status);

        // The customer pays the still-open PayMongo session later; the late sweep finds it
        Carbon::setTestNow(now()->addHours(7));
        $this->assertSame([$intent->id], $this->dueIds()['expired']);
        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(1, Booking::count());
    }

    public function test_late_payment_after_expiry_is_recovered_by_webhook_too(): void
    {
        $intent = $this->intent(25 * 60, ['status' => CheckoutIntent::STATUS_EXPIRED, 'expired_at' => now()->subHour()]);
        $this->provider([[$intent, [$this->paidSessionBody($intent)]]]);

        $this->postSignedPayMongoWebhook(['data' => ['id' => 'evt_late', 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => [
            'id' => $intent->paymongo_checkout_session_id, 'type' => 'checkout_session',
            'attributes' => ['metadata' => ['checkout_intent_id' => (string) $intent->id, 'checkout_reference' => $intent->reference]]]]]])
            ->assertOk()->assertJson(['status' => 'finalized']);
    }

    // ---------------------------------------------------------- command and scheduler

    public function test_dry_run_contacts_nothing_and_changes_nothing(): void
    {
        $this->intent(30);
        $this->paidIntent(30);
        $this->intent(25 * 60);
        Http::fake();
        $before = DB::table('checkout_intents')->orderBy('id')->get()->toJson();
        config(['checkout.reconcile_enabled' => false]); // listing works even while switched off

        $this->reconcile(['--dry-run' => true])->expectsOutputToContain('pending  2 due')->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(0, $this->sessionReads());
        $this->assertSame($before, DB::table('checkout_intents')->orderBy('id')->get()->toJson());
        $this->assertSame(0, Booking::count());
        $this->assertSame([], array_filter(range(1, 10), fn ($id) => Cache::has('checkout:reconcile:intent:' . $id)), 'No pacing written.');
    }

    public function test_batch_limit_is_respected(): void
    {
        $intents = array_map(fn () => $this->intent(30), range(1, 5));
        $this->provider(array_map(fn ($i) => [$i, [$this->paidSessionBody($i)]], $intents));

        $this->reconcile(['--limit' => 2])->expectsOutputToContain('processed=2')->assertSuccessful();

        $this->assertSame(2, Booking::count());
        $this->assertSame([$intents[0]->id, $intents[1]->id], Booking::orderBy('id')->pluck('checkout_intent_id')->all(), 'Deterministic order.');
    }

    public function test_activation_switch_off_processes_nothing(): void
    {
        $this->intent(30);
        Http::fake();
        config(['checkout.reconcile_enabled' => false]);

        $this->reconcile()->expectsOutputToContain('turned off')->assertSuccessful();

        $this->assertSame(0, $this->sessionReads());
        $this->assertSame(0, Booking::count());
    }

    public function test_a_second_run_while_one_is_active_is_skipped(): void
    {
        $this->intent(30);
        Http::fake();
        $running = Cache::lock(CheckoutReconciler::RUN_LOCK, 300, 'other-server');
        $this->assertTrue($running->get());

        $this->reconcile()->expectsOutputToContain('in progress')->assertSuccessful();

        $this->assertSame(0, $this->sessionReads());
        $running->release();
    }

    public function test_scheduler_registration_is_gated(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'checkout:reconcile'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        config(['checkout.reconcile_enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app), 'Deployed code does not start reconciling on its own.');
        config(['checkout.reconcile_enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }

    public function test_logs_carry_no_personal_or_secret_data(): void
    {
        $paid = $this->intent(30);
        $old = $this->intent(25 * 60);
        $this->provider([[$paid, [$this->paidSessionBody($paid)]], [$old, [$this->unpaidBody($old)]]]);

        $this->reconcile()->assertSuccessful();

        $logged = implode("\n", $this->logs);
        $this->assertStringContainsString('Checkout reconciliation finished', $logged);
        foreach (['casey', '09170000001', 'Synthetic asthma', 'Synthetic Diver', self::FAKE_SECRET_KEY, Booking::sole()->pin . '"'] as $private) {
            $this->assertStringNotContainsStringIgnoringCase($private, $logged);
        }
    }

    // ---------------------------------------------------------- legacy and flag

    public function test_legacy_bookings_are_not_touched(): void
    {
        Booking::create(['booking_number' => 'CFP-2026-LEG01', 'pin' => '1234', 'contact_name' => 'Legacy Guest', 'contact_email' => 'legacy@example.test',
            'contact_phone' => '09170000005', 'class_type' => 'discovery', 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'status' => 'pending_downpayment', 'total_amount' => 9200, 'downpayment_amount' => 4000, 'balance_amount' => 5200]);
        $before = DB::table('bookings')->get()->toJson();
        Http::fake();

        $this->reconcile()->expectsOutputToContain('candidates=0')->assertSuccessful();

        $this->assertSame($before, DB::table('bookings')->get()->toJson());
        $this->assertSame(0, $this->sessionReads());
    }

    public function test_started_checkouts_are_recovered_after_the_checkout_flag_is_turned_off(): void
    {
        $intent = $this->intent(30);
        $this->provider([[$intent, [$this->paidSessionBody($intent)]]]);
        config(['checkout.intents_enabled' => false]);

        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
    }
}
