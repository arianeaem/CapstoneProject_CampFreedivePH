<?php

namespace Tests\Feature;

use App\Services\ExternalApi\ExternalApiClient;
use App\Services\ExternalApi\ExternalApiRateLimitException;
use App\Services\PayMongoService;
use App\Services\WeatherForecastService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ExternalApiRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('ext_api_rate:paymongo:minute');
        RateLimiter::clear('ext_api_rate:open_meteo:minute');
        RateLimiter::clear('ext_api_rate:ml_service:minute');
        Cache::flush();
    }

    public function test_external_api_client_enforces_outbound_rate_limit(): void
    {
        Http::fake([
            'https://api.paymongo.com/*' => Http::response(['data' => ['id' => 'pay_123']], 200),
        ]);

        $client = new ExternalApiClient();
        $maxRequests = (int) config('external_apis.paymongo.rate_limit.max_requests_per_minute', 60);

        // Consume all allowed attempts
        for ($i = 0; $i < $maxRequests; $i++) {
            $response = $client->execute('paymongo', 'GET', 'https://api.paymongo.com/v1/payments/pay_123');
            $this->assertEquals(200, $response->status());
        }

        // The next request must be blocked locally before dispatching HTTP
        $this->expectException(ExternalApiRateLimitException::class);
        $client->execute('paymongo', 'GET', 'https://api.paymongo.com/v1/payments/pay_123');
    }

    public function test_external_api_client_retries_on_provider_429_with_backoff(): void
    {
        $attemptCount = 0;

        Http::fake([
            'https://marine-api.open-meteo.com/*' => function ($request) use (&$attemptCount) {
                $attemptCount++;
                if ($attemptCount === 1) {
                    return Http::response(['error' => 'Too Many Requests'], 429, ['Retry-After' => '0']);
                }
                return Http::response([
                    'hourly' => [
                        'time' => ['2026-09-20T00:00:00Z'],
                        'wave_height' => [0.8],
                    ],
                ], 200);
            },
        ]);

        $client = new ExternalApiClient();
        $response = $client->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine', [
            'query' => ['latitude' => 13.7481, 'longitude' => 120.9408],
            'max_retries' => 2,
        ]);

        $this->assertEquals(200, $response->status());
        $this->assertEquals(2, $attemptCount, "Client should have retried after receiving provider 429.");
        $this->assertArrayHasKey('hourly', $response->json());
    }

    public function test_external_api_client_retries_on_server_500_errors(): void
    {
        $attemptCount = 0;

        Http::fake([
            'https://api.open-meteo.com/*' => function ($request) use (&$attemptCount) {
                $attemptCount++;
                if ($attemptCount === 1) {
                    return Http::response(['error' => 'Internal Gateway Error'], 502);
                }
                return Http::response([
                    'hourly' => [
                        'time' => ['2026-09-20T00:00:00Z'],
                        'precipitation' => [0.0],
                    ],
                ], 200);
            },
        ]);

        $client = new ExternalApiClient();
        $response = $client->execute('open_meteo', 'GET', 'https://api.open-meteo.com/v1/forecast', [
            'query' => ['latitude' => 13.7481, 'longitude' => 120.9408],
            'max_retries' => 2,
        ]);

        $this->assertEquals(200, $response->status());
        $this->assertEquals(2, $attemptCount);
    }

    public function test_paymongo_payment_lookup_is_cached(): void
    {
        $callCount = 0;

        Http::fake([
            'https://api.paymongo.com/v1/payments/pay_test_cached' => function () use (&$callCount) {
                $callCount++;
                return Http::response(['data' => ['id' => 'pay_test_cached', 'attributes' => ['status' => 'paid']]], 200);
            },
        ]);

        config(['paymongo.secret_key' => 'sk_test_valid_key_for_testing']);
        $service = app(PayMongoService::class);

        // First call should make an HTTP request
        $firstResult = $service->getPayment('pay_test_cached');
        $this->assertNotNull($firstResult);
        $this->assertEquals(1, $callCount);

        // Second call within TTL should be served from Cache without invoking HTTP
        $secondResult = $service->getPayment('pay_test_cached');
        $this->assertNotNull($secondResult);
        $this->assertEquals(1, $callCount, "Second getPayment() call should be served from Cache.");
    }

    public function test_weather_forecast_service_serves_stale_cache_when_open_meteo_fails(): void
    {
        // Populate stale cache
        $staleData = [
            '2026-09-20' => [
                'date' => '2026-09-20',
                'day_name' => 'Sunday',
                'overall_classification' => 'Safe',
                'weighted_score_pct' => 15.0,
            ],
        ];
        Cache::put('forecast:continuous_16d', $staleData, now()->addHours(6));

        // Mock Open-Meteo as completely unavailable (503)
        Http::fake([
            'https://marine-api.open-meteo.com/*' => Http::response(['error' => 'Service Unavailable'], 503),
            'https://api.open-meteo.com/*' => Http::response(['error' => 'Service Unavailable'], 503),
        ]);

        $service = app(WeatherForecastService::class);
        $result = $service->updateAllForecasts(16);

        // Should use the old cache instead of throwing an error
        $this->assertIsArray($result);
        $this->assertArrayHasKey('2026-09-20', $result);
        $this->assertEquals('Safe', $result['2026-09-20']['overall_classification']);
    }

    public function test_daily_quota_counter_blocks_requests_when_ceiling_reached(): void
    {
        $client = new ExternalApiClient();
        $today = date('Y-m-d');
        $dailyLimit = (int) config('external_apis.open_meteo.rate_limit.max_requests_per_day', 8000);

        // Seed daily counter to limit
        Cache::put("ext_api_daily:open_meteo:{$today}", $dailyLimit, now()->addDays(1));

        $this->expectException(ExternalApiRateLimitException::class);
        $client->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine');
    }

    public function test_hourly_and_monthly_quota_counters_block_requests_at_hard_stop(): void
    {
        $client = new ExternalApiClient();
        $thisMonth = date('Y-m');
        $monthlyLimit = (int) config('external_apis.paymongo.rate_limit.max_requests_per_month', 25000);

        // Seed monthly counter to limit
        Cache::put("ext_api_monthly:paymongo:{$thisMonth}", $monthlyLimit, now()->addDays(30));

        $this->expectException(ExternalApiRateLimitException::class);
        $client->execute('paymongo', 'GET', 'https://api.paymongo.com/v1/payments/pay_123');
    }

    public function test_warning_threshold_logs_at_80_and_90_percent(): void
    {
        Http::fake([
            'https://marine-api.open-meteo.com/*' => Http::response(['hourly' => []], 200),
        ]);

        config([
            'external_apis.open_meteo.rate_limit.max_requests_per_day' => 10,
            'external_apis.open_meteo.rate_limit.warning_threshold_pct' => 80,
            'external_apis.open_meteo.rate_limit.critical_threshold_pct' => 90,
        ]);

        $today = date('Y-m-d');
        // Seed to 7 requests (next request will be 8 = 80%)
        Cache::put("ext_api_daily:open_meteo:{$today}", 7, now()->addDays(1));

        $client = new ExternalApiClient();
        $res80 = $client->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine');
        $this->assertEquals(200, $res80->status());

        // Now count is 8, next request will be 9 = 90% (critical warning)
        $res90 = $client->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine');
        $this->assertEquals(200, $res90->status());

        // Next request is 10 = 100% (allowed, reaching cap)
        $res100 = $client->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine');
        $this->assertEquals(200, $res100->status());

        // Request 11 must be blocked (Hard Stop)
        $this->expectException(ExternalApiRateLimitException::class);
        $client->execute('open_meteo', 'GET', 'https://marine-api.open-meteo.com/v1/marine');
    }

    public function test_client_does_not_retry_permanent_4xx_errors(): void
    {
        $attemptCount = 0;

        Http::fake([
            'https://api.paymongo.com/*' => function ($request) use (&$attemptCount) {
                $attemptCount++;
                return Http::response(['errors' => [['code' => 'resource_not_found', 'detail' => 'Payment intent does not exist']]], 404);
            },
        ]);

        $client = new ExternalApiClient();
        $response = $client->execute('paymongo', 'GET', 'https://api.paymongo.com/v1/payment_intents/pi_invalid', [
            'max_retries' => 2,
        ]);

        $this->assertEquals(404, $response->status());
        $this->assertEquals(1, $attemptCount, "Client should NOT retry permanent 404 client errors.");
    }
}
