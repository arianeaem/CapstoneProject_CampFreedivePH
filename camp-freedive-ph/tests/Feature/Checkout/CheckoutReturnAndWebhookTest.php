<?php

namespace Tests\Feature\Checkout;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\Gateways\PayMongoGateway;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2C.1: signed customer return/cancel pages and PayMongo webhooks for checkout intents.
 * Synthetic data only; PayMongo is faked (stray HTTP throws); mail, notifications and queue are faked.
 */
class CheckoutReturnAndWebhookTest extends TestCase
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
        Carbon::setTestNow(Carbon::parse('2026-11-02 09:00:00', 'Asia/Manila'));
        config(['checkout.intents_enabled' => true, 'checkout.finalization_lock_wait_seconds' => 0]);
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

    private function intent(array $overrides = [], int $pax = 2): CheckoutIntent
    {
        $n = ++$this->seq;
        $participants = [];
        foreach (array_slice(['Alpha', 'Bravo', 'Charlie', 'Delta'], 0, $pax) as $last) {
            $participants[] = ['name' => "Synthetic {$last}", 'birthdate' => null, 'gender' => 'prefer_not_to_say', 'age' => 30,
                'health_condition' => 'Synthetic asthma note', 'swimmer_status' => 'swimmer', 'price_per_person' => 4250.0];
        }

        return CheckoutIntent::create(array_merge([
            'reference' => 'CHK-RT' . str_pad((string) $n, 10, '0', STR_PAD_LEFT),
            'status' => CheckoutIntent::STATUS_PENDING, 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'pax_count' => $pax, 'class_type' => 'discovery', 'pickup_option' => 'own', 'currency' => 'PHP',
            'total_amount' => 4600 * $pax, 'downpayment_amount' => 2000 * $pax,
            'paymongo_checkout_session_id' => "cs_test_ret_{$n}", 'hold_expires_at' => now()->addMinutes(15),
            'payload' => [
                'booking' => ['class_type' => 'discovery', 'is_certified_diver' => false, 'start_date' => self::DATE, 'end_date' => '2026-12-20', 'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false],
                'contact' => ['name' => 'Casey Fixture', 'email' => 'casey.fixture@example.test', 'phone' => '09170000001', 'facebook' => null],
                'guardian' => [],
                'participants' => $participants,
                'pricing' => ['pricing_version' => 1, 'subtotal' => 4250.0 * $pax, 'adjustments' => [],
                    'fees' => ['lgu' => 300.0 * $pax, 'environmental' => 50.0 * $pax, 'carpool' => 0.0, 'boat_dive' => 0.0],
                    'total_amount' => 4600 * $pax, 'downpayment_amount' => 2000 * $pax],
            ],
        ], $overrides));
    }

    private function returnUrl(CheckoutIntent $intent, string $route = 'checkout.return'): string
    {
        return PayMongoGateway::signedIntentUrl($route, $intent);
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

    /** PayMongo answers the session read with these bodies, in order (then repeats the last). */
    private function provider(CheckoutIntent $intent, array ...$bodies): void
    {
        $sequence = Http::sequence();
        foreach ($bodies as $body) {
            $body === ['__fail__'] ? $sequence->pushFailedConnection() : $sequence->push($body, 200);
        }
        $sequence->whenEmpty(Http::response(end($bodies) === ['__fail__'] ? [] : end($bodies), 200));
        Http::fake([self::API . $intent->paymongo_checkout_session_id => $sequence]);
    }

    private function pendingBody(CheckoutIntent $intent): array
    {
        return $this->sessionBody($intent, [['id' => 'pay_test_pending_' . $intent->id, 'type' => 'payment',
            'attributes' => ['amount' => (int) round($intent->downpayment_amount * 100), 'currency' => 'PHP', 'status' => 'pending', 'livemode' => false]]]);
    }

    private function intentEvent(CheckoutIntent $intent, string $type = 'checkout_session.payment.paid', ?string $sessionId = null, ?string $eventId = null, array $metadata = []): array
    {
        return ['data' => ['id' => $eventId ?? 'evt_test_' . $intent->id, 'type' => 'event', 'attributes' => ['type' => $type, 'data' => [
            'id' => $sessionId ?? $intent->paymongo_checkout_session_id, 'type' => 'checkout_session',
            'attributes' => ['metadata' => $metadata + ['checkout_intent_id' => (string) $intent->id, 'checkout_reference' => $intent->reference]],
        ]]]];
    }

    private function sessionReads(): int
    {
        return collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'GET')->count();
    }

    private function assertNothingSent(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    // -------------------------------------------------------- return: signature security

    public function test_unsigned_return_is_rejected_and_checks_nothing(): void
    {
        $intent = $this->intent();

        $this->get("/checkout/{$intent->reference}/return")->assertForbidden();

        $this->assertSame(0, $this->sessionReads());
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_return_signed_for_another_checkout_is_rejected(): void
    {
        $mine = $this->intent();
        $other = $this->intent();
        $tampered = str_replace($mine->reference, $other->reference, $this->returnUrl($mine));

        $this->get($tampered)->assertForbidden();
        $this->assertSame(0, $this->sessionReads());
    }

    public function test_expired_return_link_is_rejected(): void
    {
        $intent = $this->intent();
        $url = $this->returnUrl($intent);

        Carbon::setTestNow(now()->addHours(49));

        $this->get($url)->assertForbidden();
        $this->assertSame(0, $this->sessionReads());
    }

    public function test_cancel_link_must_be_signed_too(): void
    {
        $intent = $this->intent();

        $this->get("/checkout/{$intent->reference}/cancel")->assertForbidden();
        $this->get(str_replace('/return', '/cancel', $this->returnUrl($intent)))->assertForbidden(); // signature covers the path
    }

    public function test_signed_link_for_an_unknown_reference_is_not_found(): void
    {
        $this->get(url(URL::temporarySignedRoute('checkout.return', now()->addHour(), ['reference' => 'CHK-ZZZZZZZZZZZZ'], false)))->assertNotFound();
    }

    // -------------------------------------------------------- return: payment outcomes

    public function test_paid_return_finalizes_once_and_shows_a_safe_confirmation(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent));

        $response = $this->get($this->returnUrl($intent));

        $response->assertOk()->assertSee('Booking confirmed')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $booking = Booking::sole();
        $response->assertSee($booking->booking_number)->assertSee('Discovery');
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame(1, Payment::count());
        $this->assertSame('return', $intent->fresh()->verification['source']);
        $this->assertOnlyOutcomeMessages(\App\Mail\BookingConfirmedMail::class, 'casey.fixture@example.test');
        $response->assertDontSee('pin=', false); // no PIN-bearing manage link; the PIN goes by email only
    }

    public function test_repeated_return_shows_the_same_booking_without_calling_paymongo_again(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent));
        $this->get($this->returnUrl($intent))->assertOk();
        $readsAfterFirst = $this->sessionReads();

        $second = $this->get($this->returnUrl($intent));

        $second->assertOk()->assertSee(Booking::sole()->booking_number);
        $this->assertSame($readsAfterFirst, $this->sessionReads(), 'Finalized: answered from the database.');
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
    }

    public function test_confirmation_page_never_contains_the_pin_or_sensitive_details(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent));
        $this->get($this->returnUrl($intent));
        Booking::sole()->update(['pin' => '4831']); // a recognisable PIN for the assertion

        $html = $this->get($this->returnUrl($intent))->getContent();

        foreach (['4831', 'name="pin"', 'casey', '09170000001', 'Synthetic asthma', $intent->paymongo_checkout_session_id, 'pay_test_', 'checkout_intent_id', self::FAKE_SECRET_KEY] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $html);
        }
    }

    public function test_unpaid_return_shows_not_confirmed_and_creates_nothing(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->pendingBody($intent));

        $response = $this->get($this->returnUrl($intent));

        $response->assertOk()->assertSee('Payment not yet confirmed')->assertDontSee('Booking confirmed')->assertDontSee('CFP-');
        $this->assertSame(0, Booking::count());
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_review_return_shows_review_without_booking_or_refund_promise(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent, ['attributes' => ['amount' => 100]]));

        $response = $this->get($this->returnUrl($intent));

        $response->assertOk()->assertSee('Payment under review')->assertDontSee('CFP-')->assertDontSee('refund');
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $intent->fresh()->status);
        $this->assertSame(0, Booking::count());
    }

    public function test_late_payment_without_capacity_shows_paid_unallocated(): void
    {
        $this->confirmedBookingWith(5);
        $intent = $this->intent(['hold_expires_at' => now()->subMinutes(30)]);
        $this->provider($intent, $this->paidSessionBody($intent));

        $response = $this->get($this->returnUrl($intent));

        $response->assertOk()->assertSee('Payment received')->assertSee('not yet confirmed')->assertDontSee('Booking number');
        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $intent->fresh()->status);
        $this->assertSame(1, Booking::count(), 'Only the other booking; never overbooked.');
        $this->assertNotNull($intent->fresh()->paymongo_payment_id, 'Payment evidence kept.');
    }

    public function test_provider_outage_shows_checking_status_without_details(): void
    {
        $intent = $this->intent();
        $this->provider($intent, ['__fail__']);

        $response = $this->get($this->returnUrl($intent));

        $response->assertOk()->assertSee('Checking your payment')->assertSee('do not pay again')
            ->assertDontSee('Synthetic timeout')->assertDontSee('Exception');
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_finalization_failure_keeps_the_payment_and_the_next_return_completes_it(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent));
        $this->failPaymentInsert = true;

        $this->get($this->returnUrl($intent))->assertOk()->assertSee('Checking your payment');
        $this->assertSame(CheckoutIntent::STATUS_PAID, $intent->fresh()->status);
        $this->assertSame(0, Booking::count());

        $this->failPaymentInsert = false;
        $this->get($this->returnUrl($intent))->assertOk()->assertSee('Booking confirmed');
        $this->assertSame(1, Booking::count());
    }

    // -------------------------------------------------------------- cancel page

    public function test_cancel_before_payment_changes_nothing(): void
    {
        $intent = $this->intent();
        $before = $intent->fresh()->toArray();

        $this->get($this->returnUrl($intent, 'checkout.cancel'))->assertOk()->assertSee('Checkout not completed')->assertDontSee('CFP-');

        $this->assertSame($before, $intent->fresh()->toArray(), 'Cancel is read-only (hold untouched).');
        $this->assertSame(0, $this->sessionReads());
    }

    public function test_cancel_after_successful_payment_keeps_the_booking(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent));
        $this->get($this->returnUrl($intent));
        $booking = Booking::sole()->toArray();

        $this->get($this->returnUrl($intent, 'checkout.cancel'))->assertOk()->assertSee('Booking confirmed');

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame($booking, Booking::sole()->toArray());
    }

    public function test_cancel_after_payment_was_recorded_keeps_the_paid_state(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent));
        $this->failPaymentInsert = true;
        $this->get($this->returnUrl($intent)); // Phase A saved, Phase B failed
        $paid = $intent->fresh()->toArray();

        $this->get($this->returnUrl($intent, 'checkout.cancel'))->assertOk()->assertSee('Checking your payment')->assertSee('do not pay again');

        $this->assertSame($paid, $intent->fresh()->toArray());
    }

    // ------------------------------------------------------------------- webhooks

    public function test_authenticated_intent_webhook_finalizes_once(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent));

        $this->postSignedPayMongoWebhook($this->intentEvent($intent))->assertOk()->assertJson(['status' => 'finalized']);

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame('webhook', $intent->fresh()->verification['source']);
        $this->assertSame(1, Booking::count());
        $this->assertOnlyOutcomeMessages(\App\Mail\BookingConfirmedMail::class, 'casey.fixture@example.test');
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        $intent = $this->intent();
        $body = json_encode($this->intentEvent($intent));

        $this->call('POST', route('paymongo.webhook.api'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => 't=1,te=forged,li='], $body)
            ->assertStatus(400);

        $this->assertSame(0, $this->sessionReads());
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_webhook_naming_no_matching_checkout_changes_nothing(): void
    {
        $intent = $this->intent();

        $this->postSignedPayMongoWebhook($this->intentEvent($intent, metadata: ['checkout_reference' => 'CHK-NOTTHEONE01']))
            ->assertOk()->assertJson(['status' => 'unmatched']);
        $this->postSignedPayMongoWebhook($this->intentEvent($intent, metadata: ['checkout_intent_id' => '999999']))
            ->assertOk()->assertJson(['status' => 'unmatched']);

        $this->assertSame(0, $this->sessionReads());
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertSame(2, AuditLog::where('action', 'CHECKOUT_WEBHOOK_UNMATCHED')->count());
    }

    public function test_webhook_for_a_different_session_goes_to_review_without_booking(): void
    {
        $intent = $this->intent();

        $this->postSignedPayMongoWebhook($this->intentEvent($intent, sessionId: 'cs_test_someone_else'))
            ->assertOk()->assertJson(['status' => 'payment_review']);

        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $intent->fresh()->status);
        $this->assertSame('session_mismatch', $intent->fresh()->failure_reason);
        $this->assertSame(0, $this->sessionReads(), 'Mismatch caught before any provider read.');
        $this->assertSame(0, Booking::count());
    }

    public function test_duplicate_webhook_deliveries_create_one_booking(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent));

        $this->postSignedPayMongoWebhook($this->intentEvent($intent))->assertOk()->assertJson(['status' => 'finalized']);
        $this->postSignedPayMongoWebhook($this->intentEvent($intent))->assertOk()->assertJson(['status' => 'already_finalized']);
        $this->postSignedPayMongoWebhook($this->intentEvent($intent, eventId: 'evt_other_delivery'))->assertOk();

        $this->assertSame(1, Booking::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, $this->sessionReads());
    }

    public function test_return_then_webhook_and_webhook_then_return_each_create_one_booking(): void
    {
        $first = $this->intent();
        $this->provider($first, $this->paidSessionBody($first));
        $this->get($this->returnUrl($first))->assertSee('Booking confirmed');
        $this->postSignedPayMongoWebhook($this->intentEvent($first))->assertOk()->assertJson(['status' => 'already_finalized']);

        $second = $this->intent();
        $this->provider($second, $this->paidSessionBody($second));
        $this->postSignedPayMongoWebhook($this->intentEvent($second))->assertOk()->assertJson(['status' => 'finalized']);
        $this->get($this->returnUrl($second))->assertSee('Booking confirmed');

        $this->assertSame(2, Booking::count());
        $this->assertSame(1, Booking::where('checkout_intent_id', $first->id)->count());
        $this->assertSame(1, Booking::where('checkout_intent_id', $second->id)->count());
        $this->assertSame(2, Payment::count());
    }

    public function test_paid_event_before_paymongo_shows_the_payment_asks_for_retry_and_the_retry_finalizes(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->pendingBody($intent), $this->paidSessionBody($intent));
        $event = $this->intentEvent($intent, eventId: 'evt_same_delivery');

        $this->postSignedPayMongoWebhook($event)->assertStatus(503);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);

        // PayMongo redelivers the SAME event id: it must not be dropped as a duplicate
        $this->postSignedPayMongoWebhook($event)->assertOk()->assertJson(['status' => 'finalized']);
        $this->assertSame(1, Booking::count());
    }

    public function test_provider_outage_on_webhook_is_retryable_and_changes_nothing(): void
    {
        $intent = $this->intent();
        $this->provider($intent, ['__fail__'], $this->paidSessionBody($intent));
        $event = $this->intentEvent($intent);

        $this->postSignedPayMongoWebhook($event)->assertStatus(503);
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertNull($intent->fresh()->paymongo_payment_id);

        $this->postSignedPayMongoWebhook($event)->assertOk()->assertJson(['status' => 'finalized']);
    }

    public function test_review_outcome_is_acknowledged_once_saved(): void
    {
        $intent = $this->intent();
        $this->provider($intent, $this->paidSessionBody($intent, ['attributes' => ['amount' => 100]]));

        $this->postSignedPayMongoWebhook($this->intentEvent($intent))->assertOk()->assertJson(['status' => 'payment_review']);

        $fresh = $intent->fresh();
        $this->assertSame(CheckoutIntent::STATUS_PAYMENT_REVIEW, $fresh->status);
        $this->assertSame('amount_mismatch', $fresh->verification['review_history'][0]['reason']);
    }

    public function test_paid_unallocated_outcome_is_acknowledged_with_evidence_saved(): void
    {
        $this->confirmedBookingWith(5);
        $intent = $this->intent(['hold_expires_at' => now()->subMinutes(30)]);
        $this->provider($intent, $this->paidSessionBody($intent));

        $this->postSignedPayMongoWebhook($this->intentEvent($intent))->assertOk()->assertJson(['status' => 'paid_unallocated']);

        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $intent->fresh()->status);
        $this->assertNotNull($intent->fresh()->paymongo_payment_id);
    }

    public function test_late_webhook_with_capacity_still_finalizes(): void
    {
        $intent = $this->intent(['hold_expires_at' => now()->subMinutes(30)]);
        $this->provider($intent, $this->paidSessionBody($intent));

        $this->postSignedPayMongoWebhook($this->intentEvent($intent))->assertOk()->assertJson(['status' => 'finalized']);
    }

    public function test_two_checkouts_paying_for_the_last_slots_never_overbook(): void
    {
        $this->confirmedBookingWith(4);
        $a = $this->intent(['hold_expires_at' => now()->subMinutes(30)]);
        $b = $this->intent(['hold_expires_at' => now()->subMinutes(30)]);
        Http::fake([
            self::API . $a->paymongo_checkout_session_id => Http::response($this->paidSessionBody($a)),
            self::API . $b->paymongo_checkout_session_id => Http::response($this->paidSessionBody($b)),
        ]);

        $this->postSignedPayMongoWebhook($this->intentEvent($a))->assertOk()->assertJson(['status' => 'finalized']);
        $this->get($this->returnUrl($b))->assertOk()->assertSee('Payment received');

        $this->assertSame(CheckoutIntent::STATUS_PAID_UNALLOCATED, $b->fresh()->status);
        $this->assertSame(6, \App\Models\BookingParticipant::count());
    }

    public function test_non_payment_intent_event_is_acknowledged_without_changes(): void
    {
        $intent = $this->intent();

        $this->postSignedPayMongoWebhook($this->intentEvent($intent, type: 'payment.failed'))->assertOk()->assertJson(['status' => 'ignored']);

        $this->assertSame(0, $this->sessionReads());
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_legacy_booking_webhook_still_uses_the_legacy_handler(): void
    {
        $booking = Booking::create(['booking_number' => 'CFP-2026-LEG01', 'pin' => '1234', 'contact_name' => 'Legacy Guest', 'contact_email' => 'legacy@example.test',
            'contact_phone' => '09170000005', 'class_type' => 'discovery', 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'status' => 'pending_downpayment', 'total_amount' => 9200, 'downpayment_amount' => 4000, 'balance_amount' => 5200]);
        $event = ['data' => ['id' => 'evt_legacy_1', 'type' => 'event', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => [
            'id' => 'cs_legacy_1', 'type' => 'checkout_session', 'attributes' => ['amount' => 400000, 'fee' => 0, 'payment_method_type' => 'gcash',
                'metadata' => ['booking_id' => $booking->id, 'booking_number' => $booking->booking_number, 'checkout_intent_id' => '1'],
                'payments' => [['id' => 'pay_legacy_1', 'attributes' => ['status' => 'paid', 'amount' => 400000, 'currency' => 'PHP', 'livemode' => false]]]]]]]];

        $this->postSignedPayMongoWebhook($event)->assertOk()->assertJson(['received' => true]);

        $this->assertSame('confirmed', $booking->fresh()->status, 'Legacy handler confirmed it as before.');
        $this->assertSame(0, $this->sessionReads(), 'The intent handler (which reads PayMongo) was not used.');
        $this->assertSame(0, CheckoutIntent::count());
    }

    // ------------------------------------------------------------- feature flag

    public function test_started_payments_still_complete_after_the_flag_is_turned_off(): void
    {
        $byReturn = $this->intent();
        $byWebhook = $this->intent();
        Http::fake([
            self::API . $byReturn->paymongo_checkout_session_id => Http::response($this->paidSessionBody($byReturn)),
            self::API . $byWebhook->paymongo_checkout_session_id => Http::response($this->paidSessionBody($byWebhook)),
        ]);
        config(['checkout.intents_enabled' => false]);

        $this->get($this->returnUrl($byReturn))->assertOk()->assertSee('Booking confirmed');
        $this->postSignedPayMongoWebhook($this->intentEvent($byWebhook))->assertOk()->assertJson(['status' => 'finalized']);

        $this->assertSame(2, Booking::count());
    }

    // --------------------------------------------------------- session creation URLs

    public function test_intent_checkout_session_uses_signed_per_intent_return_and_cancel_urls(): void
    {
        $intent = $this->intent(['paymongo_checkout_session_id' => null]);
        $this->fakeCheckoutSessionCreated('cs_test_created_1');

        $result = app(PayMongoGateway::class)->createCheckoutSessionForIntent($intent, ['name' => 'Casey Fixture', 'email' => 'casey.fixture@example.test', 'phone' => '09170000001']);

        $this->assertTrue($result['success']);
        $attributes = $this->singleCheckoutSessionAttributes();
        $this->assertStringContainsString("/checkout/{$intent->reference}/return?", $attributes['success_url']);
        $this->assertStringContainsString("/checkout/{$intent->reference}/cancel?", $attributes['cancel_url']);
        foreach (['success_url', 'cancel_url'] as $key) {
            $this->assertStringContainsString('signature=', $attributes[$key]);
            $this->assertStringContainsString('expires=', $attributes[$key]);
            $this->assertStringNotContainsString('CFP-', $attributes[$key]);
            $this->assertStringNotContainsString('pin', strtolower($attributes[$key]));
            $this->assertStringNotContainsString(self::FAKE_SECRET_KEY, $attributes[$key]);
        }

        // The link PayMongo will send the customer to really validates
        $intent->update(['paymongo_checkout_session_id' => 'cs_test_created_1']);
        Http::fake([self::API . '*' => Http::response($this->pendingBody($intent))]);
        $this->get($attributes['success_url'])->assertOk();
        $this->get($attributes['cancel_url'])->assertOk();
    }
}
