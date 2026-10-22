<?php

namespace Tests\Feature\Checkout;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\Checkout\BookingFinalizer;
use App\Services\Checkout\CheckoutReconciler;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
 * Batch 2C.2.2B: closing abandoned PayMongo sessions safely, late-payment recovery beyond 7 days, fair candidate
 * selection and the saved-evidence livemode guard. Synthetic data; faked PayMongo; stray HTTP throws.
 */
class CheckoutExpiryRecoveryTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;

    private const DATE = '2026-12-19';
    private const API = 'api.paymongo.com/v1/checkout_sessions/';

    private int $seq = 0;
    private bool $failPaymentInsert = false;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function intent(int $ageMinutes = 30, array $overrides = []): CheckoutIntent
    {
        $n = ++$this->seq;
        $created = now()->subMinutes($ageMinutes);
        $intent = CheckoutIntent::create(array_merge([
            'reference' => 'CHK-EX' . str_pad((string) $n, 10, '0', STR_PAD_LEFT),
            'status' => CheckoutIntent::STATUS_PENDING, 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'pax_count' => 2, 'class_type' => 'discovery', 'pickup_option' => 'own', 'currency' => 'PHP',
            'total_amount' => 9200, 'downpayment_amount' => 4000,
            'paymongo_checkout_session_id' => "cs_test_ex_{$n}", 'hold_expires_at' => $created->copy()->addMinutes(15),
            'payload' => [
                'booking' => ['class_type' => 'discovery', 'is_certified_diver' => false, 'start_date' => self::DATE, 'end_date' => '2026-12-20', 'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false],
                'contact' => ['name' => 'Casey Fixture', 'email' => 'casey.fixture@example.test', 'phone' => '09170000001', 'facebook' => null],
                'guardian' => [],
                'participants' => array_map(fn ($i) => ['name' => "Synthetic Diver {$i}", 'birthdate' => null, 'gender' => 'prefer_not_to_say', 'age' => 30,
                    'health_condition' => null, 'swimmer_status' => 'swimmer', 'price_per_person' => 4250.0], [1, 2]),
                'pricing' => ['pricing_version' => 1, 'subtotal' => 8500.0, 'adjustments' => [],
                    'fees' => ['lgu' => 600.0, 'environmental' => 100.0, 'carpool' => 0.0, 'boat_dive' => 0.0], 'total_amount' => 9200, 'downpayment_amount' => 4000],
            ],
        ], $overrides));
        DB::table('checkout_intents')->where('id', $intent->id)->update(['created_at' => $created, 'updated_at' => $created]);

        return $intent->fresh();
    }

    private function unpaid(CheckoutIntent $i): array
    {
        return $this->sessionBody($i, []);
    }

    private function closed(CheckoutIntent $i): array
    {
        return $this->sessionBody($i, [], ['attributes' => ['status' => 'expired']]);
    }

    private function withPayment(CheckoutIntent $i, string $status, array $session = []): array
    {
        return $this->sessionBody($i, [['id' => 'pay_test_ex_' . $i->id, 'type' => 'payment', 'attributes' => ['amount' => 400000, 'currency' => 'PHP',
            'status' => $status, 'livemode' => false]]], $session);
    }

    /** Session reads answered in order (last repeats); the expire endpoint answered by $expire; counts kept. */
    private function provider(CheckoutIntent $intent, array $reads, mixed $expire, array &$counts): void
    {
        $sessionPath = '/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id;
        Http::fake(function (Request $request) use ($sessionPath, &$reads, $expire, &$counts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $counts[$path] = ($counts[$path] ?? 0) + 1;
            if ($path === $sessionPath . '/expire') {
                return is_callable($expire) ? $expire() : $expire;
            }
            if ($path === $sessionPath) {
                $next = count($reads) > 1 ? array_shift($reads) : $reads[0];

                return $next === 'fail' ? throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout') : Http::response($next);
            }

            return null; // not this checkout: let another stub answer (or the stray-request guard throw)
        });
    }

    private function reconcile()
    {
        return $this->artisan('checkout:reconcile');
    }

    // ------------------------------------------------------------- closing sessions

    public function test_session_closed_and_confirmed_unpaid_marks_expired_without_touching_the_hold(): void
    {
        $intent = $this->intent(25 * 60);
        $hold = $intent->hold_expires_at;
        $counts = [];
        $this->provider($intent, [$this->unpaid($intent), $this->closed($intent)], Http::response($this->closed($intent)), $counts);

        $this->reconcile()->expectsOutputToContain('expired=1')->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_EXPIRED, $fresh->status);
        $this->assertNotNull($fresh->expired_at);
        $this->assertTrue($fresh->hold_expires_at->equalTo($hold), 'The 15-minute hold is never changed.');
        $this->assertNull($fresh->checkout_expires_at, 'checkout_expires_at is not repurposed.');
        $this->assertSame(1, $counts['/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id . '/expire']);
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_SESSION_CLOSED')->count());
        $this->assertSame(0, Booking::count());
        Mail::assertNothingSent();
        Notification::assertNothingSent();
    }

    public function test_session_still_active_after_the_expiry_request_stays_pending(): void
    {
        $intent = $this->intent(25 * 60);
        $counts = [];
        $this->provider($intent, [$this->unpaid($intent)], Http::response($this->unpaid($intent)), $counts); // PayMongo says 200 but the re-read is active

        $this->reconcile()->expectsOutputToContain('still_pending=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_expiry_request_failures_never_expire_locally(): void
    {
        foreach ([Http::response(['errors' => []], 400), Http::response(['errors' => []], 500), fn () => throw new \Illuminate\Http\Client\ConnectionException('Synthetic timeout')] as $answer) {
            $intent = $this->intent(25 * 60);
            $counts = [];
            $this->provider($intent, [$this->unpaid($intent)], $answer, $counts);

            $this->reconcile()->assertSuccessful();

            $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
            Cache::flush(); // next intent independent
        }
    }

    public function test_payment_found_before_closing_is_finalized_and_no_close_is_attempted(): void
    {
        $intent = $this->intent(25 * 60);
        $counts = [];
        $this->provider($intent, [$this->paidSessionBody($intent)], Http::response([]), $counts);

        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertArrayNotHasKey('/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id . '/expire', $counts);
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
    }

    public function test_payment_completed_while_closing_is_finalized_not_expired(): void
    {
        $intent = $this->intent(25 * 60);
        $counts = [];
        $paid = $this->paidSessionBody($intent, [], ['attributes' => ['status' => 'expired']]);
        $this->provider($intent, [$this->unpaid($intent), $paid], Http::response($paid), $counts);

        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertNull($intent->fresh()->expired_at);
        $this->assertSame(1, Booking::count());
    }

    public function test_pending_payment_blocks_closing(): void
    {
        $intent = $this->intent(25 * 60);
        $counts = [];
        $this->provider($intent, [$this->withPayment($intent, 'pending')], Http::response([]), $counts);

        $this->reconcile()->expectsOutputToContain('still_pending=1')->assertSuccessful();

        $this->assertArrayNotHasKey('/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id . '/expire', $counts);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_ambiguous_state_after_closing_never_expires(): void
    {
        // In-progress payment appears on the re-read; then a re-read that cannot be done at all
        foreach (['pending', 'fail'] as $kind) {
            $intent = $this->intent(25 * 60);
            $second = $kind === 'fail' ? 'fail' : $this->withPayment($intent, 'pending', ['attributes' => ['status' => 'expired']]);
            $counts = [];
            $this->provider($intent, [$this->unpaid($intent), $second], Http::response([]), $counts);

            $this->reconcile()->assertSuccessful();

            $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status, "Not expired when the re-read is {$kind}.");
            Cache::flush();
        }
    }

    public function test_payment_recorded_concurrently_wins_over_expiry(): void
    {
        $intent = $this->intent(25 * 60);
        $counts = [];
        // While PayMongo is closing the session, a webhook records the payment (Phase A) for this checkout
        $this->provider($intent, [$this->unpaid($intent), $this->closed($intent)], function () use ($intent) {
            DB::table('checkout_intents')->where('id', $intent->id)->update(['status' => CheckoutIntent::STATUS_PAID, 'paymongo_payment_id' => 'pay_test_concurrent']);

            return Http::response([]);
        }, $counts);

        $this->reconcile()->expectsOutputToContain('skipped=1')->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAID, $fresh->status, 'A paid checkout is never overwritten by expiry.');
        $this->assertSame('pay_test_concurrent', $fresh->paymongo_payment_id);
        $this->assertNull($fresh->expired_at);
    }

    // ------------------------------------------------------------ late payments

    public function test_unclosed_session_is_still_recovered_after_seven_days(): void
    {
        $intent = $this->intent(10 * 24 * 60); // 10 days old, PayMongo refused to close it
        $counts = [];
        $this->provider($intent, [$this->unpaid($intent), $this->unpaid($intent), $this->paidSessionBody($intent)], Http::response(['errors' => []], 400), $counts);
        $this->reconcile()->expectsOutputToContain('still_pending=1')->assertSuccessful();

        Carbon::setTestNow(now()->addDay()); // decaying pacing: daily at this age
        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
    }

    public function test_exhausted_sweep_is_flagged_for_staff_and_still_recoverable_by_webhook(): void
    {
        $intent = $this->intent(91 * 24 * 60);
        Http::fake([self::API . $intent->paymongo_checkout_session_id => Http::response($this->paidSessionBody($intent))]);

        $this->reconcile()->expectsOutputToContain('flagged=1')->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $fresh->status);
        $this->assertSame(CheckoutReconciler::NEEDS_STAFF_CHECK, $fresh->resolution_status);
        $this->assertStringContainsString('PayMongo', $fresh->resolution_note);
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_RECONCILE_EXHAUSTED')->count());
        $this->assertSame([], app(CheckoutReconciler::class)->candidates(25)['pending'], 'No longer polled.');
        Http::assertNothingSent();

        // A webhook can still bring the payment in
        $this->postSignedPayMongoWebhook(['data' => ['id' => 'evt_ex', 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => [
            'id' => $intent->paymongo_checkout_session_id, 'type' => 'checkout_session',
            'attributes' => ['metadata' => ['checkout_intent_id' => (string) $intent->id, 'checkout_reference' => $intent->reference]]]]]])
            ->assertOk()->assertJson(['status' => 'finalized']);
    }

    // ---------------------------------------------------------------- fairness

    public function test_newer_due_checkout_is_reached_beyond_the_scan_window(): void
    {
        config(['checkout.reconcile.scan_limit' => 10]); // stands in for 500 at a testable size
        foreach (range(1, 15) as $ignored) {
            $old = $this->intent(30);
            Cache::put('checkout:reconcile:intent:' . $old->id, ['attempts' => 1, 'next_at' => now()->addHour()->getTimestamp()], now()->addDay());
        }
        $newer = $this->intent(30);
        $reconciler = app(CheckoutReconciler::class);

        $first = $reconciler->candidates(5, true)['pending'];
        $second = $reconciler->candidates(5, true)['pending'];

        $this->assertSame([], $first, 'The first window holds only not-yet-due rows.');
        $this->assertSame([$newer->id], array_map(fn ($i) => $i->id, $second), 'The cursor moves on, so the newer checkout is reached.');
    }

    public function test_groups_share_the_run_limit_round_robin(): void
    {
        $paid = [];
        foreach (range(1, 3) as $ignored) {
            $i = $this->intent(30);
            $this->failPaymentInsert = true;
            app(BookingFinalizer::class)->finalize($i, $this->verifiedResultFor($i), 'return');
            $this->failPaymentInsert = false;
            DB::table('checkout_intents')->where('id', $i->id)->update(['updated_at' => now()->subMinutes(5)]);
            $paid[] = $i->id;
        }
        $pending = [$this->intent(30)->id, $this->intent(30)->id, $this->intent(30)->id];

        $selected = app(CheckoutReconciler::class)->candidates(2);

        $this->assertSame([$paid[0]], array_map(fn ($i) => $i->id, $selected['paid']));
        $this->assertSame([$pending[0]], array_map(fn ($i) => $i->id, $selected['pending']));
    }

    // ----------------------------------------------------------- saved evidence

    public function test_saved_evidence_from_another_mode_cannot_finalize(): void
    {
        $intent = $this->intent(30);
        $this->failPaymentInsert = true;
        app(BookingFinalizer::class)->finalize($intent, $this->verifiedResultFor($intent, ['livemode' => true]), 'return'); // evidence says live
        $this->failPaymentInsert = false;
        DB::table('checkout_intents')->where('id', $intent->id)->update(['updated_at' => now()->subMinutes(5)]);
        $counts = [];
        $live = $this->paidSessionBody($intent, ['attributes' => ['livemode' => true]], ['attributes' => ['livemode' => true]]);
        $this->provider($intent, [$live], Http::response([]), $counts);

        $this->reconcile()->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertSame(1, $counts['/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id], 'Fresh verification instead of the saved evidence.');
        $this->assertSame(CheckoutIntent::STATUS_PAID, $fresh->status, 'Not finalized; evidence kept.');
        $this->assertSame('mode_mismatch', $fresh->verification['review_history'][0]['reason']);
        $this->assertSame(0, Booking::count());
    }

    public function test_saved_evidence_from_the_configured_mode_resumes_without_paymongo(): void
    {
        $intent = $this->intent(30);
        $this->failPaymentInsert = true;
        app(BookingFinalizer::class)->finalize($intent, $this->verifiedResultFor($intent), 'return');
        $this->failPaymentInsert = false;
        DB::table('checkout_intents')->where('id', $intent->id)->update(['updated_at' => now()->subMinutes(5)]);
        Http::fake();

        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(1, Booking::count());
    }

    public function test_reconciler_and_repeated_webhooks_stay_idempotent(): void
    {
        $intent = $this->intent(30);
        Http::fake([self::API . $intent->paymongo_checkout_session_id => Http::response($this->paidSessionBody($intent))]);
        $event = ['data' => ['id' => 'evt_dupe', 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => [
            'id' => $intent->paymongo_checkout_session_id, 'type' => 'checkout_session',
            'attributes' => ['metadata' => ['checkout_intent_id' => (string) $intent->id, 'checkout_reference' => $intent->reference]]]]]];

        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();
        $this->postSignedPayMongoWebhook($event)->assertOk()->assertJson(['status' => 'already_finalized']);
        $this->postSignedPayMongoWebhook($event)->assertOk()->assertJson(['status' => 'already_finalized']);

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_feature_flags_default_to_off(): void
    {
        $defaults = require config_path('checkout.php');

        $this->assertFalse($defaults['intents_enabled']);
        $this->assertFalse($defaults['reconcile_enabled']);
    }
}
