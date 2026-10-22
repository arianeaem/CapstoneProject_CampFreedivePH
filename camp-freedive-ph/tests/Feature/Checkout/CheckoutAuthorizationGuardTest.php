<?php

namespace Tests\Feature\Checkout;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\Checkout\CheckoutReconciler;
use App\Services\Checkout\PaymentVerifier;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
 * Batch 2C.2.8: a payment in progress is never closed. Shapes follow what PayMongo test mode showed:
 * open GCash authorization = payments[] empty + payment_intent awaiting_next_action (2C.2.7A); expiring it was accepted
 * and left the session expired + payment_intent cancelled (2C.2.7B). Synthetic data; faked PayMongo; stray HTTP throws.
 */
class CheckoutAuthorizationGuardTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;

    private const DATE = '2026-12-19';

    private int $seq = 0;

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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function intent(int $ageMinutes = 25 * 60): CheckoutIntent
    {
        $n = ++$this->seq;
        $created = now()->subMinutes($ageMinutes);
        $intent = CheckoutIntent::create([
            'reference' => 'CHK-AG' . str_pad((string) $n, 10, '0', STR_PAD_LEFT),
            'status' => CheckoutIntent::STATUS_PENDING, 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'pax_count' => 2, 'class_type' => 'discovery', 'pickup_option' => 'own', 'currency' => 'PHP',
            'total_amount' => 9200, 'downpayment_amount' => 4000,
            'paymongo_checkout_session_id' => "cs_test_ag_{$n}", 'hold_expires_at' => $created->copy()->addMinutes(15),
            'payload' => [
                'booking' => ['class_type' => 'discovery', 'is_certified_diver' => false, 'start_date' => self::DATE, 'end_date' => '2026-12-20', 'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false],
                'contact' => ['name' => 'Casey Fixture', 'email' => 'casey.fixture@example.test', 'phone' => '09170000001', 'facebook' => null],
                'guardian' => [],
                'participants' => array_map(fn ($i) => ['name' => "Synthetic Diver {$i}", 'birthdate' => null, 'gender' => 'prefer_not_to_say', 'age' => 30,
                    'health_condition' => null, 'swimmer_status' => 'swimmer', 'price_per_person' => 4250.0], [1, 2]),
                'pricing' => ['pricing_version' => 1, 'subtotal' => 8500.0, 'adjustments' => [],
                    'fees' => ['lgu' => 600.0, 'environmental' => 100.0, 'carpool' => 0.0, 'boat_dive' => 0.0], 'total_amount' => 9200, 'downpayment_amount' => 4000],
            ],
        ]);
        DB::table('checkout_intents')->where('id', $intent->id)->update(['created_at' => $created, 'updated_at' => $created]);

        return $intent->fresh();
    }

    /** Session with no payment objects; $intentStatus null leaves the payment intent out, an array replaces it whole. */
    private function body(CheckoutIntent $i, string|array|null $intentStatus, string $session = 'active', array $payments = []): array
    {
        $body = $this->sessionBody($i, $payments, ['attributes' => ['status' => $session]]);
        if (is_string($intentStatus)) {
            $body['data']['attributes']['payment_intent']['attributes']['status'] = $intentStatus;
        } else {
            $body['data']['attributes']['payment_intent'] = $intentStatus;
        }

        return $body;
    }

    private function pendingPayment(CheckoutIntent $i): array
    {
        return ['id' => 'pay_test_ag_' . $i->id, 'type' => 'payment', 'attributes' => ['amount' => 400000, 'currency' => 'PHP', 'status' => 'pending', 'livemode' => false]];
    }

    /** Session reads answered in order (last repeats); expire answered by $expire; request counts kept per path. */
    private function provider(CheckoutIntent $intent, array $reads, mixed $expire, array &$counts): void
    {
        $sessionPath = '/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id;
        Http::fake(function (Request $request) use ($sessionPath, &$reads, $expire, &$counts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $counts[$path] = ($counts[$path] ?? 0) + 1;
            if ($path === $sessionPath . '/expire') {
                return $expire;
            }
            if ($path === $sessionPath) {
                return Http::response(count($reads) > 1 ? array_shift($reads) : $reads[0]);
            }

            return null;
        });
    }

    private function expirePath(CheckoutIntent $intent): string
    {
        return '/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id . '/expire';
    }

    private function reconcile()
    {
        return $this->artisan('checkout:reconcile');
    }

    private function paidEvent(CheckoutIntent $intent, string $eventId): array
    {
        return ['data' => ['id' => $eventId, 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => [
            'id' => $intent->paymongo_checkout_session_id, 'type' => 'checkout_session',
            'attributes' => ['metadata' => ['checkout_intent_id' => (string) $intent->id, 'checkout_reference' => $intent->reference]]]]]];
    }

    private function fillDate(int $pax): void
    {
        $booking = Booking::create(['booking_number' => 'CFP-2026-AGF' . (++$this->seq), 'pin' => '0000', 'contact_name' => 'Other Guest',
            'contact_email' => 'other@example.test', 'contact_phone' => '09170000002', 'class_type' => 'discovery', 'start_date' => self::DATE,
            'end_date' => '2026-12-20', 'status' => 'confirmed', 'total_amount' => 1, 'downpayment_amount' => 1, 'balance_amount' => 0]);
        foreach (range(1, $pax) as $i) {
            $booking->participants()->create(['name' => "Other Diver {$i}", 'age' => 30, 'price_per_person' => 4250]);
        }
    }

    // --------------------------------------------------- never closed while in progress

    public static function notClosable(): array
    {
        return [
            'GCash authorization open (awaiting_next_action)' => ['awaiting_next_action', 'payment_authorizing'],
            'authorized, processing' => ['processing', 'payment_authorizing'],
            'payment intent missing' => [null, 'payment_state_unconfirmed'],
            'unknown payment intent status' => ['requires_capture', 'payment_state_unconfirmed'],
            'malformed payment intent' => [['attributes' => 'garbage'], 'payment_state_unconfirmed'],
            'payment intent not an object' => ['__string__', 'payment_state_unconfirmed'],
            'succeeded without a payment object' => ['succeeded', 'payment_state_unconfirmed'],
        ];
    }

    #[DataProvider('notClosable')]
    public function test_payment_intent_state_that_does_not_prove_nothing_started_is_never_closed(string|array|null $status, string $reason): void
    {
        $intent = $this->intent();
        $body = $status === '__string__' ? $this->body($intent, null) : $this->body($intent, $status);
        if ($status === '__string__') {
            $body['data']['attributes']['payment_intent'] = 'pi_test_bare_string';
        }
        $counts = [];
        $this->provider($intent, [$body], Http::response($this->body($intent, 'cancelled', 'expired')), $counts);

        $this->assertSame($reason, app(PaymentVerifier::class)->verify($intent)->reason);
        $this->reconcile()->expectsOutputToContain('still_pending=1')->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertArrayNotHasKey($this->expirePath($intent), $counts, 'No expiry request while a payment may be under way.');
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $fresh->status);
        $this->assertNull($fresh->expired_at);
        $this->assertNull($fresh->resolution_status, 'Not flagged before unresolved_flag_hours.');
    }

    public function test_pending_payment_object_is_never_closed_even_when_the_intent_says_nothing_started(): void
    {
        $intent = $this->intent();
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_payment_method', 'active', [$this->pendingPayment($intent)])], Http::response([]), $counts);

        $this->reconcile()->expectsOutputToContain('still_pending=1')->assertSuccessful();

        $this->assertArrayNotHasKey($this->expirePath($intent), $counts);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    // ---------------------------------------------------------- closing allowed

    public function test_nothing_started_is_closed_after_the_existing_threshold_and_confirmed_by_the_re_read(): void
    {
        $intent = $this->intent();
        $hold = $intent->hold_expires_at;
        $counts = [];
        // Observed shapes: before = awaiting_payment_method; after expiry = expired + cancelled
        $this->provider($intent, [$this->body($intent, 'awaiting_payment_method'), $this->body($intent, 'cancelled', 'expired')],
            Http::response($this->body($intent, 'cancelled', 'expired')), $counts);

        $this->reconcile()->expectsOutputToContain('expired=1')->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertSame(1, $counts[$this->expirePath($intent)]);
        $this->assertSame(CheckoutIntent::STATUS_EXPIRED, $fresh->status);
        $this->assertTrue($fresh->hold_expires_at->equalTo($hold));
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_SESSION_CLOSED')->count());
    }

    public function test_nothing_started_is_not_closed_before_the_threshold(): void
    {
        $intent = $this->intent(23 * 60);
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_payment_method')], Http::response([]), $counts);

        $this->reconcile()->expectsOutputToContain('still_pending=1')->assertSuccessful();

        $this->assertArrayNotHasKey($this->expirePath($intent), $counts);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_session_already_closed_is_recorded_without_another_expiry_request(): void
    {
        // e.g. an earlier expiry was accepted but its re-read failed
        $intent = $this->intent();
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'cancelled', 'expired')], Http::response([]), $counts);

        $this->reconcile()->expectsOutputToContain('expired=1')->assertSuccessful();

        $this->assertArrayNotHasKey($this->expirePath($intent), $counts);
        $this->assertSame(2, $counts['/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id], 'Verification read plus the confirming re-read.');
        $this->assertSame(CheckoutIntent::STATUS_EXPIRED, $intent->fresh()->status);
    }

    // ----------------------------------------------------- after the expiry request

    public function test_closed_session_whose_payment_intent_still_looks_active_is_flagged_not_expired(): void
    {
        foreach (['succeeded', 'processing', 'awaiting_next_action'] as $status) {
            $intent = $this->intent();
            unset($counts); // a fresh array: earlier fakes keep their own (captured by reference)
            $counts = [];
            $this->provider($intent, [$this->body($intent, 'awaiting_payment_method'), $this->body($intent, $status, 'expired')], Http::response([]), $counts);

            $this->reconcile()->assertSuccessful(); // earlier flagged checkouts are still polled too, so counts vary

            $fresh = $intent->fresh();
            $this->assertSame(1, $counts[$this->expirePath($intent)]);
            $this->assertSame(CheckoutIntent::STATUS_PENDING, $fresh->status, "expired + {$status} is never treated as unpaid.");
            $this->assertNull($fresh->expired_at);
            $this->assertSame(CheckoutReconciler::AUTHORIZATION_UNRESOLVED, $fresh->resolution_status);
            Cache::flush(); // clear pacing: the flagged checkout is still a candidate
            $this->assertContains($intent->id, array_map(fn ($i) => $i->id, app(CheckoutReconciler::class)->candidates(25)['pending']), 'Still polled.');
        }
        $this->assertSame(3, AuditLog::where('action', 'CHECKOUT_PAYMENT_UNRESOLVED')->count());
    }

    public function test_ambiguous_state_after_the_expiry_request_stays_unresolved_and_retryable(): void
    {
        foreach ([null, 'requires_capture'] as $status) {
            $intent = $this->intent();
            $counts = [];
            $this->provider($intent, [$this->body($intent, 'awaiting_payment_method'), $this->body($intent, $status, 'expired')], Http::response([]), $counts);

            $this->reconcile()->expectsOutputToContain('retryable=1')->assertSuccessful();

            $fresh = $intent->fresh();
            $this->assertSame(CheckoutIntent::STATUS_PENDING, $fresh->status);
            $this->assertNull($fresh->expired_at);
            Cache::flush();
        }
    }

    public function test_paid_payment_takes_priority_over_any_session_or_payment_intent_state(): void
    {
        foreach ([['awaiting_next_action', 'expired'], [null, 'expired'], ['requires_capture', 'active']] as [$status, $session]) {
            $intent = $this->intent();
            $paid = $this->paidSessionBody($intent, [], ['attributes' => ['status' => $session]]);
            if ($status === null) {
                $paid['data']['attributes']['payment_intent'] = null;
            } else {
                $paid['data']['attributes']['payment_intent']['attributes']['status'] = $status;
            }
            $counts = [];
            $this->provider($intent, [$paid], Http::response([]), $counts);

            $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

            $this->assertArrayNotHasKey($this->expirePath($intent), $counts);
            $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
            Cache::flush();
        }
        $this->assertSame(3, Booking::count());
    }

    // -------------------------------------------- stuck authorizations (72 hours)

    public function test_authorization_older_than_the_limit_is_flagged_once_never_expired_and_still_polled(): void
    {
        $intent = $this->intent(73 * 60);
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_next_action')], Http::response([]), $counts);

        $this->reconcile()->expectsOutputToContain('still_pending=1')->assertSuccessful();
        Carbon::setTestNow(now()->addDay());
        $this->reconcile()->expectsOutputToContain('still_pending=1')->assertSuccessful();

        $fresh = $intent->fresh();
        $this->assertArrayNotHasKey($this->expirePath($intent), $counts, 'Never expired because the time ran out.');
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $fresh->status);
        $this->assertSame(CheckoutReconciler::AUTHORIZATION_UNRESOLVED, $fresh->resolution_status);
        $this->assertStringContainsString('payment_authorizing', $fresh->resolution_note);
        $this->assertSame(1, AuditLog::where('action', 'CHECKOUT_PAYMENT_UNRESOLVED')->count(), 'Flagged once.');
        $this->assertSame(2, $counts['/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id], 'Still checked automatically.');
    }

    public function test_authorization_younger_than_the_limit_is_not_flagged(): void
    {
        $intent = $this->intent(71 * 60);
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_next_action')], Http::response([]), $counts);

        $this->reconcile()->assertSuccessful();

        $this->assertNull($intent->fresh()->resolution_status);
    }

    public function test_flagged_authorization_closes_normally_once_paymongo_shows_nothing_started(): void
    {
        // GCash's documented window lapses: the intent returns to awaiting_payment_method
        $intent = $this->intent(73 * 60);
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_next_action'), $this->body($intent, 'awaiting_payment_method'), $this->body($intent, 'cancelled', 'expired')],
            Http::response([]), $counts);

        $this->reconcile()->assertSuccessful();
        Carbon::setTestNow(now()->addDay());
        $this->reconcile()->expectsOutputToContain('expired=1')->assertSuccessful();

        $this->assertSame(1, $counts[$this->expirePath($intent)]);
        $this->assertSame(CheckoutIntent::STATUS_EXPIRED, $intent->fresh()->status);
    }

    public function test_flagged_authorization_still_ends_at_the_unclosed_limit(): void
    {
        $intent = $this->intent(91 * 24 * 60);
        DB::table('checkout_intents')->where('id', $intent->id)->update(['resolution_status' => CheckoutReconciler::AUTHORIZATION_UNRESOLVED]);
        Http::fake();

        $this->reconcile()->expectsOutputToContain('flagged=1')->assertSuccessful();

        $this->assertSame(CheckoutReconciler::NEEDS_STAFF_CHECK, $intent->fresh()->resolution_status);
        $this->assertSame([], app(CheckoutReconciler::class)->candidates(25)['pending']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------- late payment recovery

    public function test_flagged_authorization_is_finalized_by_a_later_poll(): void
    {
        $intent = $this->intent(73 * 60);
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_next_action'), $this->paidSessionBody($intent)], Http::response([]), $counts);

        $this->reconcile()->assertSuccessful();
        Carbon::setTestNow(now()->addDay());
        $this->reconcile()->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(1, Booking::count());
    }

    public function test_late_verified_webhook_after_the_flag_finalizes_and_duplicates_change_nothing(): void
    {
        $intent = $this->intent(73 * 60);
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_next_action'), $this->paidSessionBody($intent)], Http::response([]), $counts);
        $this->reconcile()->assertSuccessful();
        $this->assertSame(CheckoutReconciler::AUTHORIZATION_UNRESOLVED, $intent->fresh()->resolution_status);

        $this->postSignedPayMongoWebhook($this->paidEvent($intent, 'evt_ag_late'))->assertOk()->assertJson(['status' => 'finalized']);
        $this->postSignedPayMongoWebhook($this->paidEvent($intent, 'evt_ag_late'))->assertOk();
        $this->postSignedPayMongoWebhook($this->paidEvent($intent, 'evt_ag_late_2'))->assertOk()->assertJson(['status' => 'already_finalized']);
        Carbon::setTestNow(now()->addDay());
        $this->reconcile()->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_late_payment_on_a_full_date_is_paid_unallocated_and_never_overbooks(): void
    {
        $intent = $this->intent(73 * 60);
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_next_action'), $this->paidSessionBody($intent)], Http::response([]), $counts);
        $this->reconcile()->assertSuccessful();
        $this->fillDate(5); // capacity 6, checkout needs 2

        $this->postSignedPayMongoWebhook($this->paidEvent($intent, 'evt_ag_full'))->assertOk();

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $fresh->status);
        $this->assertNotNull($fresh->paymongo_payment_id, 'The verified payment is kept.');
        $this->assertSame(1, Booking::count(), 'Only the booking that filled the date.');
    }

    // ------------------------------------------------- return and webhook unchanged

    public function test_webhook_during_an_open_authorization_asks_for_retry_and_changes_nothing(): void
    {
        $intent = $this->intent(30);
        $counts = [];
        $this->provider($intent, [$this->body($intent, 'awaiting_next_action'), $this->paidSessionBody($intent)], Http::response([]), $counts);
        $event = $this->paidEvent($intent, 'evt_ag_early');

        $this->postSignedPayMongoWebhook($event)->assertStatus(503);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertNull($intent->fresh()->resolution_status);

        $this->postSignedPayMongoWebhook($event)->assertOk()->assertJson(['status' => 'finalized']);
        $this->assertArrayNotHasKey($this->expirePath($intent), $counts);
    }

    public function test_timing_settings_are_unchanged(): void
    {
        $defaults = require config_path('checkout.php');

        $this->assertSame(15, CheckoutIntent::HOLD_MINUTES);
        $this->assertSame(24, $defaults['reconcile']['expire_after_hours']);
        $this->assertSame(72, $defaults['reconcile']['unresolved_flag_hours']);
        $this->assertFalse($defaults['intents_enabled']);
        $this->assertFalse($defaults['reconcile_enabled']);
    }
}
