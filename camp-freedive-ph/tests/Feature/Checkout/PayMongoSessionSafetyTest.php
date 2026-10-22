<?php

namespace Tests\Feature\Checkout;

use App\Services\ExternalApi\ExternalApiClient;
use App\Services\PayMongoService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Checkout\Concerns\FakesPayMongo;
use Tests\TestCase;

/**
 * Batch 2C.2.2B: PayMongo session closing and creation safety. Faked HTTP only (stray requests throw).
 */
class PayMongoSessionSafetyTest extends TestCase
{
    use FakesPayMongo;

    private const EXPIRE = 'api.paymongo.com/v1/checkout_sessions/cs_test_safety1/expire';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFakePayMongo();
    }

    private function service(): PayMongoService
    {
        return app(PayMongoService::class);
    }

    /** Count requests per URL path while answering with $answer (a Response, or a Throwable to simulate a timeout). */
    private function fakeCounting(array $answers, array &$counts): void
    {
        Http::fake(function (Request $request) use ($answers, &$counts) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            $counts[$path] = ($counts[$path] ?? 0) + 1;
            $answer = $answers[$path] ?? Http::response(['errors' => [['code' => 'not_stubbed']]], 404);
            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        });
    }

    // ------------------------------------------------------------- expire endpoint

    public function test_successful_expiry_request_is_accepted_but_not_proof(): void
    {
        Http::fake([self::EXPIRE => Http::response(['data' => ['id' => 'cs_test_safety1', 'attributes' => ['status' => 'expired']]])]);

        $this->assertSame('accepted', $this->service()->expireCheckoutSession('cs_test_safety1')['outcome']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/checkout_sessions/cs_test_safety1/expire'));
        Http::assertSentCount(1);
    }

    public function test_expiry_timeout_is_unavailable_and_not_retried(): void
    {
        $counts = [];
        $this->fakeCounting(['/v1/checkout_sessions/cs_test_safety1/expire' => new ConnectionException('Synthetic timeout')], $counts);

        $this->assertSame('unavailable', $this->service()->expireCheckoutSession('cs_test_safety1')['outcome']);
        $this->assertSame(1, $counts['/v1/checkout_sessions/cs_test_safety1/expire']);
    }

    public function test_expiry_http_400_is_rejected_not_expired(): void
    {
        Http::fake([self::EXPIRE => Http::response(['errors' => [['code' => 'resource_invalid_state']]], 400)]);

        $result = $this->service()->expireCheckoutSession('cs_test_safety1');

        $this->assertSame('rejected', $result['outcome']);
        $this->assertSame(400, $result['http_status']);
    }

    public function test_expiry_http_500_is_unavailable_and_not_retried(): void
    {
        $counts = [];
        $this->fakeCounting(['/v1/checkout_sessions/cs_test_safety1/expire' => Http::response(['errors' => []], 500)], $counts);

        $this->assertSame('unavailable', $this->service()->expireCheckoutSession('cs_test_safety1')['outcome']);
        $this->assertSame(1, $counts['/v1/checkout_sessions/cs_test_safety1/expire'], 'No automatic retry of the POST.');
    }

    public function test_expiry_keeps_tls_verification_on_and_disables_retries(): void
    {
        $captured = null;
        $this->mock(ExternalApiClient::class, function ($mock) use (&$captured) {
            $mock->shouldReceive('execute')->once()->andReturnUsing(function ($provider, $method, $url, $options) use (&$captured) {
                $captured = compact('provider', 'method', 'url', 'options');

                return new Response(new \GuzzleHttp\Psr7\Response(200, [], '{}'));
            });
        });

        $this->service()->expireCheckoutSession('cs_test_safety1');

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.paymongo.com/v1/checkout_sessions/cs_test_safety1/expire', $captured['url']);
        $this->assertArrayNotHasKey('without_verifying', $captured['options'], 'TLS peer and host verification stay on.');
        $this->assertSame(0, $captured['options']['max_retries']);
    }

    public function test_invalid_session_id_is_refused_without_any_request(): void
    {
        Http::fake();

        foreach (['cs_../../payments', 'pay_123', 'cs_abc/expire', 'cs_', ''] as $bad) {
            $this->assertSame('rejected', $this->service()->expireCheckoutSession($bad)['outcome']);
        }
        Http::assertNothingSent();
    }

    public function test_simulated_mode_never_calls_paymongo(): void
    {
        config(['paymongo.secret_key' => '']);
        Http::fake();

        $this->assertSame('not_configured', $this->service()->expireCheckoutSession('cs_test_safety1')['outcome']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ session creation

    private function create(): array
    {
        return $this->service()->createCheckoutSession(
            [['name' => 'Synthetic', 'amount' => 400000, 'quantity' => 1, 'currency' => 'PHP']],
            ['reference_number' => 'CHK-SYNTHETIC0001', 'metadata' => ['checkout_intent_id' => '1']],
        );
    }

    public function test_creation_timeout_sends_exactly_one_post_and_no_fallback(): void
    {
        $counts = [];
        $this->fakeCounting(['/v2/checkout_sessions' => new ConnectionException('Synthetic timeout')], $counts);

        $result = $this->create();

        $this->assertFalse($result['success']);
        $this->assertSame(['/v2/checkout_sessions' => 1], $counts, 'No retry, no v1 fallback: the session may already exist.');
    }

    public function test_creation_server_error_does_not_fall_back_to_v1(): void
    {
        $counts = [];
        $this->fakeCounting(['/v2/checkout_sessions' => Http::response(['errors' => []], 502)], $counts);

        $this->assertFalse($this->create()['success']);
        $this->assertSame(['/v2/checkout_sessions' => 1], $counts);
    }

    public function test_definite_v2_rejection_falls_back_to_v1_once(): void
    {
        $counts = [];
        $this->fakeCounting([
            '/v2/checkout_sessions' => Http::response(['errors' => [['code' => 'route_not_found']]], 404),
            '/v1/checkout_sessions' => Http::response(['data' => ['id' => 'cs_test_v1made', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/x']]]),
        ], $counts);

        $result = $this->create();

        $this->assertTrue($result['success']);
        $this->assertSame('cs_test_v1made', $result['checkout_id']);
        $this->assertSame(['/v2/checkout_sessions' => 1, '/v1/checkout_sessions' => 1], $counts);
    }

    public function test_v2_minimal_response_observed_in_test_mode_still_works(): void
    {
        // Shape observed in PayMongo test mode (Batch 2C.2.2A): v2 returns only id + checkout_url, livemode, timestamps
        $counts = [];
        $this->fakeCounting(['/v2/checkout_sessions' => Http::response(['data' => ['id' => 'cs_test_v2made', 'type' => 'checkout_session',
            'attributes' => ['checkout_url' => 'https://checkout.paymongo.test/y', 'livemode' => false, 'created_at' => 1, 'updated_at' => 1]]])], $counts);

        $result = $this->create();

        $this->assertTrue($result['success']);
        $this->assertSame('cs_test_v2made', $result['checkout_id']);
        $this->assertSame('https://checkout.paymongo.test/y', $result['checkout_url']);
        $this->assertSame(['/v2/checkout_sessions' => 1], $counts);
    }
}
