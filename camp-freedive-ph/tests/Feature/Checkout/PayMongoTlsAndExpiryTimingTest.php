<?php

namespace Tests\Feature\Checkout;

use App\Models\CheckoutIntent;
use App\Models\SystemSetting;
use App\Services\ExternalApi\ExternalApiClient;
use App\Services\PayMongoService;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
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
 * Batch 2C.2.3: every PayMongo request keeps TLS verification on, and the close-the-session threshold is a
 * configurable setting separate from the 15-minute hold. Faked HTTP only; no PayMongo calls.
 */
class PayMongoTlsAndExpiryTimingTest extends TestCase
{
    use FakesPayMongo;
    use RefreshDatabase;

    private const DATE = '2026-12-19';
    private const API = 'api.paymongo.com/v1/checkout_sessions/';

    private array $calls = [];
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
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- TLS

    /** Every request PayMongoService hands to the HTTP client is recorded with its options. */
    private function captureClient(): void
    {
        $this->mock(ExternalApiClient::class, function ($mock) {
            $mock->shouldReceive('execute')->andReturnUsing(function ($provider, $method, $url, $options) {
                $this->calls[] = compact('provider', 'method', 'url', 'options');

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], json_encode(['data' => [
                    'id' => 'cs_test_capture', 'type' => 'checkout_session',
                    'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/c', 'status' => 'succeeded', 'client_key' => 'ck_test',
                        'payments' => [], 'livemode' => false, 'metadata' => []],
                ]])));
            });
        });
    }

    public static function operations(): array
    {
        return [
            'checkout session creation' => ['createCheckoutSession'],
            'payment intent creation' => ['createPaymentIntent'],
            'payment method creation' => ['createPaymentMethod'],
            'payment intent attach' => ['attachPaymentIntent'],
            'refund' => ['refund'],
            'payment read' => ['getPayment'],
            'legacy paid-payment check' => ['paidPaymentForCheckout'],
            'cached session read' => ['getCheckoutSession'],
            'verification session read' => ['fetchCheckoutSessionForVerification'],
            'session expiry' => ['expireCheckoutSession'],
        ];
    }

    #[DataProvider('operations')]
    public function test_paymongo_operation_keeps_tls_verification_on(string $operation): void
    {
        $this->captureClient();
        $service = app(PayMongoService::class);

        match ($operation) {
            'createCheckoutSession' => $service->createCheckoutSession([['name' => 'Synthetic', 'amount' => 400000, 'quantity' => 1, 'currency' => 'PHP']]),
            'createPaymentIntent' => $service->createPaymentIntent(4000.00),
            'createPaymentMethod' => $service->createPaymentMethod('gcash', [], ['name' => 'Synthetic', 'email' => 'synthetic@example.test']),
            'attachPaymentIntent' => $service->attachPaymentIntent('pi_test_capture', 'pm_test_capture', 'https://example.test/return', 'ck_test'),
            'refund' => $service->refund('pay_test_capture', 4000.00),
            'getPayment' => $service->getPayment('pay_test_capture'),
            'paidPaymentForCheckout' => $service->paidPaymentForCheckout('cs_test_capture'),
            'getCheckoutSession' => $service->getCheckoutSession('cs_test_capture'),
            'fetchCheckoutSessionForVerification' => $service->fetchCheckoutSessionForVerification('cs_test_capture'),
            'expireCheckoutSession' => $service->expireCheckoutSession('cs_test_capture'),
        };

        $this->assertNotEmpty($this->calls, "{$operation} reached the HTTP client");
        foreach ($this->calls as $call) {
            $this->assertSame('paymongo', $call['provider']);
            $this->assertStringStartsWith('https://api.paymongo.com/', $call['url']);
            $this->assertArrayNotHasKey('without_verifying', $call['options'], "{$operation} must not disable TLS verification");
            $this->assertNotEmpty($call['options']['basic_auth'][0] ?? null, 'Authentication preserved');
        }
    }

    public function test_session_creation_still_never_retries_automatically(): void
    {
        $this->captureClient();

        app(PayMongoService::class)->createCheckoutSession([['name' => 'Synthetic', 'amount' => 400000, 'quantity' => 1, 'currency' => 'PHP']]);

        $this->assertSame(0, $this->calls[0]['options']['max_retries']);
        $this->assertSame(15, $this->calls[0]['options']['timeout']);
    }

    public function test_no_insecure_switch_exists_in_the_paymongo_service(): void
    {
        $source = file_get_contents(app_path('Services/PayMongoService.php'));

        $this->assertStringNotContainsString("'without_verifying' => true", $source);
        $this->assertStringNotContainsString('withoutVerifying', $source);
        $this->assertDoesNotMatchRegularExpression("/'verify'\\s*=>\\s*false/", $source);
    }

    public function test_a_caller_cannot_switch_tls_verification_off_through_extra_options(): void
    {
        $this->captureClient();
        $service = new class(app(ExternalApiClient::class)) extends PayMongoService {
            public function rawRequest(): void
            {
                $this->request('GET', 'https://api.paymongo.com/v1/payments/pay_x', [], 10, ['without_verifying' => true]);
            }
        };

        $service->rawRequest();

        $this->assertArrayNotHasKey('without_verifying', $this->calls[0]['options']);
    }

    // ------------------------------------------------------------ expiry timing

    public function test_hold_is_fifteen_minutes_and_closing_threshold_defaults_to_24_hours(): void
    {
        $defaults = require config_path('checkout.php');

        $this->assertSame(15, CheckoutIntent::HOLD_MINUTES);
        $this->assertSame(24, $defaults['reconcile']['expire_after_hours']);
        $this->assertFalse($defaults['intents_enabled']);
        $this->assertFalse($defaults['reconcile_enabled']);
    }

    private function intent(int $ageMinutes): CheckoutIntent
    {
        $n = ++$this->seq;
        $created = now()->subMinutes($ageMinutes);
        $intent = CheckoutIntent::create([
            'reference' => 'CHK-TM' . str_pad((string) $n, 10, '0', STR_PAD_LEFT), 'status' => CheckoutIntent::STATUS_PENDING,
            'start_date' => self::DATE, 'end_date' => '2026-12-20', 'pax_count' => 2, 'class_type' => 'discovery', 'pickup_option' => 'own',
            'currency' => 'PHP', 'total_amount' => 9200, 'downpayment_amount' => 4000, 'paymongo_checkout_session_id' => "cs_test_tm_{$n}",
            'hold_expires_at' => $created->copy()->addMinutes(CheckoutIntent::HOLD_MINUTES),
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

    /** Session reads answered in order (last repeats); expire endpoint counted. */
    private function provider(CheckoutIntent $intent, array $reads, array &$counts): void
    {
        $sessionPath = '/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id;
        Http::fake(function (Request $request) use ($sessionPath, &$reads, &$counts, $intent) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $counts[$path] = ($counts[$path] ?? 0) + 1;
            if ($path === $sessionPath . '/expire') {
                return Http::response($this->sessionBody($intent, [], ['attributes' => ['status' => 'expired']]));
            }
            if ($path === $sessionPath) {
                return Http::response(count($reads) > 1 ? array_shift($reads) : $reads[0]);
            }

            return null;
        });
    }

    private function prepareReconcile(): void
    {
        config(['checkout.reconcile_enabled' => true, 'checkout.finalization_lock_wait_seconds' => 0]);
        SystemSetting::create(['group' => 'camp_operations', 'key' => 'camp_operations.max_batch_capacity', 'value' => '6', 'type' => 'integer']);
        $this->mock(WeatherForecastService::class, fn ($mock) => $mock->shouldReceive('assessBatch')->andReturn([]));
    }

    public function test_closing_waits_for_the_configured_threshold_not_the_hold(): void
    {
        $this->prepareReconcile();
        $intent = $this->intent(3 * 60); // hold long over, but younger than the 24-hour default
        $counts = [];
        $this->provider($intent, [$this->sessionBody($intent, [])], $counts);

        $this->artisan('checkout:reconcile')->expectsOutputToContain('still_pending=1')->assertSuccessful();

        $this->assertArrayNotHasKey('/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id . '/expire', $counts, 'No close before the threshold.');
        $this->assertSame(CheckoutIntent::STATUS_PENDING, $intent->fresh()->status);
    }

    public function test_a_configured_shorter_threshold_is_respected(): void
    {
        $this->prepareReconcile();
        config(['checkout.reconcile.expire_after_hours' => 2]);
        $intent = $this->intent(3 * 60);
        $hold = $intent->hold_expires_at;
        $counts = [];
        $this->provider($intent, [$this->sessionBody($intent, []), $this->sessionBody($intent, [], ['attributes' => ['status' => 'expired']])], $counts);

        $this->artisan('checkout:reconcile')->expectsOutputToContain('expired=1')->assertSuccessful();

        $this->assertSame(1, $counts['/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id . '/expire']);
        $this->assertTrue($intent->fresh()->hold_expires_at->equalTo($hold), 'The 15-minute hold is not touched.');
    }

    public function test_pending_payment_blocks_closing_even_with_a_shorter_threshold(): void
    {
        $this->prepareReconcile();
        config(['checkout.reconcile.expire_after_hours' => 1]);
        $intent = $this->intent(2 * 60);
        $counts = [];
        $pending = $this->sessionBody($intent, [['id' => 'pay_test_tm_pending', 'type' => 'payment', 'attributes' => ['amount' => 400000, 'currency' => 'PHP', 'status' => 'pending', 'livemode' => false]]]);
        $this->provider($intent, [$pending], $counts);

        $this->artisan('checkout:reconcile')->expectsOutputToContain('still_pending=1')->assertSuccessful();

        $this->assertArrayNotHasKey('/v1/checkout_sessions/' . $intent->paymongo_checkout_session_id . '/expire', $counts);
    }

    public function test_late_verified_payment_after_a_shorter_close_is_still_recovered(): void
    {
        $this->prepareReconcile();
        config(['checkout.reconcile.expire_after_hours' => 1]);
        $intent = $this->intent(2 * 60);
        $counts = [];
        $this->provider($intent, [
            $this->sessionBody($intent, []),
            $this->sessionBody($intent, [], ['attributes' => ['status' => 'expired']]),
            $this->paidSessionBody($intent, [], ['attributes' => ['status' => 'expired']]),
        ], $counts);
        $this->artisan('checkout:reconcile')->expectsOutputToContain('expired=1')->assertSuccessful();

        Carbon::setTestNow(now()->addHours(7)); // post-close re-check
        $this->artisan('checkout:reconcile')->expectsOutputToContain('finalized=1')->assertSuccessful();

        $this->assertSame(CheckoutIntent::STATUS_FINALIZED, $intent->fresh()->status);
    }
}
