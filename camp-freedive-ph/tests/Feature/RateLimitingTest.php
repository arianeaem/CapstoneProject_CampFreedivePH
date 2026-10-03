<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        RateLimiter::clear('login');
        RateLimiter::clear('password_reset');
        RateLimiter::clear('booking_create');
        RateLimiter::clear('booking_quote_weather');
        RateLimiter::clear('manage_lookup');
        RateLimiter::clear('manage_requests');
        RateLimiter::clear('paymongo_checkout');
        RateLimiter::clear('paymongo_webhook');
        RateLimiter::clear('weather_sync');
        RateLimiter::clear('ml_api');
    }

    public function test_login_rate_limiting_blocks_brute_force_after_limit(): void
    {
        $maxAttempts = (int) config('rate_limits.login.max_attempts', 10);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->postJson('/login', [
                'email' => 'admin@campfreedive.ph',
                'password' => 'WrongPassword!',
            ]);
            $this->assertNotEquals(429, $response->status(), "Attempt {$i} should not be throttled yet.");
        }

        // Attempt max + 1 should be throttled with 429
        $throttledResponse = $this->postJson('/login', [
            'email' => 'admin@campfreedive.ph',
            'password' => 'WrongPassword!',
        ]);

        $throttledResponse->assertStatus(429);
        $throttledResponse->assertJsonStructure(['error']);
        $throttledResponse->assertJsonFragment([
            'error' => 'Too many requests. Please try again later.',
        ]);
        $this->assertTrue($throttledResponse->headers->has('Retry-After'));
    }

    public function test_password_reset_is_rate_limited(): void
    {
        $maxAttempts = (int) config('rate_limits.password_reset.max_attempts', 5);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->postJson('/forgot-password', [
                'email' => 'user@example.com',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $throttled = $this->postJson('/forgot-password', [
            'email' => 'user@example.com',
        ]);

        $throttled->assertStatus(429);
        $throttled->assertJsonFragment([
            'error' => 'Too many requests. Please try again later.',
        ]);
        $this->assertTrue($throttled->headers->has('Retry-After'));
    }

    public function test_booking_creation_is_rate_limited(): void
    {
        $maxAttempts = (int) config('rate_limits.booking_create.max_attempts', 10);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->postJson('/book', []);
            $this->assertNotEquals(429, $response->status());
        }

        $throttled = $this->postJson('/book', []);
        $throttled->assertStatus(429);
        $throttled->assertJsonFragment([
            'error' => 'Too many requests. Please try again later.',
        ]);
        $this->assertTrue($throttled->headers->has('Retry-After'));
    }

    public function test_customer_portal_pin_lookup_is_rate_limited(): void
    {
        $maxAttempts = (int) config('rate_limits.manage_lookup.max_attempts', 10);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->postJson('/manage-booking/search', [
                'booking_number' => 'CFP-2026-TEST',
                'pin' => '0000',
            ]);
            $this->assertNotEquals(429, $response->status());
        }

        $throttled = $this->postJson('/manage-booking/search', [
            'booking_number' => 'CFP-2026-TEST',
            'pin' => '0000',
        ]);

        $throttled->assertStatus(429);
        $throttled->assertJsonFragment([
            'error' => 'Too many requests. Please try again later.',
        ]);
        $this->assertTrue($throttled->headers->has('Retry-After'));
    }

    public function test_weather_sync_endpoint_is_rate_limited_per_user_or_ip(): void
    {
        $owner = User::where('email', 'owner@campfreedive.ph')->first();
        $this->actingAs($owner);

        $maxAttempts = (int) config('rate_limits.weather_sync.max_attempts', 10);

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->post('/owner/safety-monitoring/sync-cache');
            $this->assertNotEquals(429, $response->status());
        }

        $throttled = $this->postJson('/owner/safety-monitoring/sync-cache');
        $throttled->assertStatus(429);
        $throttled->assertJsonFragment([
            'error' => 'Too many requests. Please try again later.',
        ]);
    }

    public function test_ml_api_is_rate_limited(): void
    {
        $maxAttempts = (int) config('rate_limits.ml_api.max_attempts', 60);
        $token = env('ML_API_TOKEN', 'cfml_live_your_secure_64_character_token_here');

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/v1/ml/training-data');
            $this->assertNotEquals(429, $response->status());
        }

        $throttled = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/ml/training-data');

        $throttled->assertStatus(429);
        $throttled->assertJsonFragment([
            'error' => 'Too many requests. Please try again later.',
        ]);
    }

    public function test_different_ips_have_independent_rate_limit_buckets(): void
    {
        $maxAttempts = (int) config('rate_limits.password_reset.max_attempts', 5);

        // Exhaust IP 1
        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->postJson('/forgot-password', ['email' => 'ip1@example.com']);
        }

        // IP 1 should now be throttled
        $ip1Throttled = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/forgot-password', ['email' => 'ip1@example.com']);
        $ip1Throttled->assertStatus(429);

        // IP 2 should still be allowed
        $ip2Allowed = $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/forgot-password', ['email' => 'ip2@example.com']);
        $this->assertNotEquals(429, $ip2Allowed->status());
    }

    public function test_rate_limit_resets_after_decay_window(): void
    {
        $maxAttempts = (int) config('rate_limits.password_reset.max_attempts', 5);
        $ip = '10.0.0.99';

        for ($i = 0; $i < $maxAttempts; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])
                ->postJson('/forgot-password', ['email' => 'test@example.com']);
        }

        $throttled = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/forgot-password', ['email' => 'test@example.com']);
        $throttled->assertStatus(429);

        // Advance time past decay window (16 minutes)
        $this->travel(16)->minutes();

        $allowedAfterReset = $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/forgot-password', ['email' => 'test@example.com']);
        $this->assertNotEquals(429, $allowedAfterReset->status());
    }
}
