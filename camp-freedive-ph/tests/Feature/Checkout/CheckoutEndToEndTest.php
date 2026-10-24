<?php

namespace Tests\Feature\Checkout;

use App\Mail\BookingConfirmedMail;
use App\Models\Booking;
use App\Models\CheckoutIntent;
use App\Models\Payment;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Checkout\Concerns\BuildsCheckoutIntents;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2 completion: the whole payment-first flow through the real booking form, return page and webhook.
 * Synthetic people only; faked PayMongo; stray HTTP throws.
 */
class CheckoutEndToEndTest extends TestCase
{
    use BuildsCheckoutIntents;
    use FakesPayMongo;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
        $this->setUpCheckoutWorld();
        $this->mock(WeatherSafetyService::class, fn ($mock) => $mock->shouldReceive('getForecast')->andReturn([
            'risk_level' => 'safe', 'overall_classification' => 'Safe', 'is_bookable' => true,
            'day1' => ['classification' => 'Safe'], 'day2' => ['classification' => 'Safe'],
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function bookingForm(): array
    {
        return [
            'class_type' => 'discovery', 'start_date' => self::DATE, 'end_date' => '2026-12-20',
            'participants' => [
                ['first_name' => 'Synthetic', 'last_name' => 'Alpha', 'age' => 26, 'health_condition' => 'Synthetic note', 'swimmer_status' => 'swimmer'],
                ['first_name' => 'Synthetic', 'last_name' => 'Bravo', 'age' => 27, 'health_condition' => 'Synthetic note', 'swimmer_status' => 'swimmer'],
            ],
            'contact_first_name' => 'Casey', 'contact_last_name' => 'Fixture', 'contact_email' => self::CONTACT_EMAIL, 'contact_phone' => '09170000001',
            'pickup_option' => 'own', 'pickup_location' => null, 'boat_dive' => false,
            'has_agreed_to_terms' => true, 'confirmation_ack' => true, 'payment_method' => 'paymongo',
        ];
    }

    /** Submit the form with checkout intents on; PayMongo then shows $paid. Returns the intent (nothing official exists yet). */
    private function startCheckout(bool $paid = true): CheckoutIntent
    {
        config(['checkout.intents_enabled' => true]);
        $this->fakeCheckoutSessionCreated('cs_test_e2e_001');

        $response = $this->postJson(route('booking.store'), $this->bookingForm())->assertOk();

        $response->assertJsonStructure(['checkout_reference', 'checkout_url'])->assertJsonMissing(['booking_number'])->assertJsonMissingPath('pin');
        $intent = CheckoutIntent::sole();
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->status);
        $this->assertTrue($intent->hold_expires_at->equalTo(now()->addMinutes(15)), '15-minute hold.');
        $this->assertSame(0, Booking::count(), 'No booking before a verified payment.');
        $this->assertArrayNotHasKey('pin', $intent->payload);
        Mail::assertNothingSent();

        // First matching fake wins, so the session answer is set once here
        $this->fakeSessionRetrieval($intent->paymongo_checkout_session_id, $paid ? $this->paidSessionBody($intent) : $this->sessionBody($intent, []));

        return $intent;
    }

    private function assertOneConfirmedBooking(CheckoutIntent $intent): Booking
    {
        $booking = Booking::sole();
        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
        $this->assertSame($booking->id, $intent->fresh()->booking_id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertMatchesRegularExpression('/^\d{4}$/', (string) $booking->pin, 'PIN created at finalization.');
        $this->assertSame(2, $booking->participants()->count());
        $this->assertSame(1, Payment::where('booking_id', $booking->id)->where('status', 'paid')->count());
        Mail::assertNothingSent(); // never sent inside the return or webhook request
        $this->deliverQueuedCheckoutNotifications();
        Mail::assertSent(BookingConfirmedMail::class, 1);
        Mail::assertSent(BookingConfirmedMail::class, fn ($m) => $m->hasTo(self::CONTACT_EMAIL) && $m->booking->is($booking));

        return $booking;
    }

    public function test_return_before_webhook_creates_one_booking_and_one_confirmation(): void
    {
        $intent = $this->startCheckout();

        $page = $this->get($this->signedCheckoutUrl($intent))->assertOk()->assertSee('Booking confirmed');
        $this->postSignedPayMongoWebhook($this->paidWebhookEvent($intent, 'evt_e2e_late'))->assertOk()->assertJson(['status' => 'already_finalized']);
        $this->get($this->signedCheckoutUrl($intent))->assertOk()->assertSee('Booking confirmed');

        $booking = $this->assertOneConfirmedBooking($intent);
        $page->assertSee($booking->booking_number)->assertDontSee('pin=', false);
    }

    public function test_webhook_before_return_creates_one_booking_and_one_confirmation(): void
    {
        $intent = $this->startCheckout();

        $this->postSignedPayMongoWebhook($this->paidWebhookEvent($intent, 'evt_e2e_first'))->assertOk()->assertJson(['status' => 'finalized']);
        $this->postSignedPayMongoWebhook($this->paidWebhookEvent($intent, 'evt_e2e_first'))->assertOk(); // PayMongo redelivery
        $page = $this->get($this->signedCheckoutUrl($intent))->assertOk();

        $booking = $this->assertOneConfirmedBooking($intent);
        $page->assertSee($booking->booking_number);
    }

    public function test_abandoned_payment_page_leaves_no_booking_and_the_hold_lapses(): void
    {
        $intent = $this->startCheckout(paid: false);

        $this->get($this->signedCheckoutUrl($intent, 'checkout.cancel'))->assertOk();
        $this->get($this->signedCheckoutUrl($intent))->assertOk()->assertDontSee('Booking confirmed');
        Carbon::setTestNow(now()->addMinutes(16));

        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
        $this->assertFalse($intent->fresh()->hasLiveHold(), 'The 15-minute hold has lapsed.');
        $this->assertSame(0, Booking::count());
        Mail::assertNothingSent();
    }

    public function test_flag_off_keeps_the_legacy_booking_flow(): void
    {
        config(['checkout.intents_enabled' => false]);
        $this->fakeCheckoutSessionCreated('cs_test_legacy_001');

        $response = $this->postJson(route('booking.store'), $this->bookingForm())->assertOk();

        $response->assertJsonStructure(['booking_number', 'pin', 'checkout_url']);
        $this->assertSame(0, CheckoutIntent::count());
        $this->assertSame('pending_downpayment', Booking::sole()->status);
        $this->assertFalse((bool) (require config_path('checkout.php'))['intents_enabled'], 'Flag defaults to off.');
    }
}
