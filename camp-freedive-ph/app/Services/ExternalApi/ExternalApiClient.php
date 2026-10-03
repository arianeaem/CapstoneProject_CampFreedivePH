<?php

namespace App\Services\ExternalApi;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Resilient Client for Outbound Third-Party API Calls.
 *
 * Responsibilities:
 * 1. Proactive Rate Limiting & Quota Throttling: Enforces local per-minute and per-day caps
 *    before dispatching network requests to prevent third-party provider 429s and surprise billing.
 * 2. Exponential Backoff with Jitter: Automatically catches transient network errors and provider 429s,
 *    respecting Retry-After headers with bounded backoff.
 * 3. Sanitized Lightweight Telemetry: Logs request latency, status, and retry metrics while stripping
 *    credentials, authorization headers, tokens, and PII.
 */
class ExternalApiClient
{
    /**
     * Execute an outbound HTTP request with rate-limiting, retries, backoff, and logging.
     *
     * @param string $provider Identifier ('paymongo', 'open_meteo', 'ml_service')
     * @param string $method 'GET', 'POST', 'PUT', etc.
     * @param string $url Full target URL
     * @param array $options Query params, headers, payload, auth, timeout
     * @return Response
     * @throws ExternalApiRateLimitException If local outbound quota is exceeded
     * @throws Exception On persistent failure after max retries
     */
    public function execute(string $provider, string $method, string $url, array $options = []): Response
    {
        $config = config("external_apis.{$provider}", []);
        $providerName = $config['name'] ?? ucfirst($provider);

        // 1. Enforce outbound rate limit quotas and budget thresholds (minute, hour, day, month)
        $this->enforceRateLimits($provider, $config);

        $maxRetries = (int) ($options['max_retries'] ?? $config['max_retries'] ?? 2);
        $backoffBaseMs = (int) ($config['backoff_base_ms'] ?? 300);
        $timeout = (int) ($options['timeout'] ?? $config['timeout_seconds'] ?? 10);

        $attempt = 0;
        $startTime = microtime(true);
        $lastException = null;
        $response = null;

        while ($attempt <= $maxRetries) {
            $attempt++;
            $attemptStartTime = microtime(true);

            try {
                // Build HTTP request
                $pendingRequest = Http::timeout($timeout)->acceptJson();

                if (!empty($options['without_verifying'])) {
                    $pendingRequest->withoutVerifying();
                }

                if (!empty($options['basic_auth'])) {
                    $pendingRequest->withBasicAuth($options['basic_auth'][0] ?? '', $options['basic_auth'][1] ?? '');
                }

                if (!empty($options['bearer_token'])) {
                    $pendingRequest->withToken($options['bearer_token']);
                }

                if (!empty($options['headers'])) {
                    $pendingRequest->withHeaders($options['headers']);
                }

                // Dispatch HTTP request
                $methodUpper = strtoupper($method);
                if ($methodUpper === 'GET') {
                    $response = $pendingRequest->get($url, $options['query'] ?? []);
                } elseif ($methodUpper === 'POST') {
                    $response = $pendingRequest->post($url, $options['json'] ?? $options['payload'] ?? []);
                } elseif ($methodUpper === 'PUT') {
                    $response = $pendingRequest->put($url, $options['json'] ?? $options['payload'] ?? []);
                } elseif ($methodUpper === 'DELETE') {
                    $response = $pendingRequest->delete($url, $options['json'] ?? $options['payload'] ?? []);
                } else {
                    $response = $pendingRequest->send($methodUpper, $url, $options);
                }

                $attemptDurationMs = round((microtime(true) - $attemptStartTime) * 1000, 2);

                // Permanent client errors (400, 401, 403, 404, 422) should NEVER be retried
                $status = $response->status();
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    $totalDurationMs = round((microtime(true) - $startTime) * 1000, 2);
                    $this->logMetric($provider, $methodUpper, $url, $status, $totalDurationMs, $attempt - 1, false);
                    return $response;
                }

                // Check for provider rate limit (429) or transient 5xx server errors
                if ($status === 429 || $response->serverError()) {
                    if ($attempt <= $maxRetries) {
                        $retryAfter = (int) ($response->header('Retry-After') ?: 0);
                        $sleepMs = $retryAfter > 0
                            ? min($retryAfter * 1000, 5000)
                            : min(4000, ($backoffBaseMs * (2 ** ($attempt - 1))) + rand(20, 150));

                        Log::warning("[ExternalAPI:{$providerName}] Received HTTP {$status}, backing off for {$sleepMs}ms (Attempt {$attempt}/{$maxRetries})", [
                            'endpoint' => $this->sanitizeUrl($url),
                            'status' => $status,
                            'attempt' => $attempt,
                        ]);

                        usleep($sleepMs * 1000);
                        continue;
                    }
                }

                // Log successful completion or final response
                $totalDurationMs = round((microtime(true) - $startTime) * 1000, 2);
                $this->logMetric($provider, $methodUpper, $url, $status, $totalDurationMs, $attempt - 1, $response->successful());

                return $response;

            } catch (\Illuminate\Http\Client\ConnectionException | \Throwable $e) {
                $lastException = $e;
                $attemptDurationMs = round((microtime(true) - $attemptStartTime) * 1000, 2);

                if ($attempt <= $maxRetries) {
                    $sleepMs = min(4000, ($backoffBaseMs * (2 ** ($attempt - 1))) + rand(20, 150));
                    Log::warning("[ExternalAPI:{$providerName}] Connection error: {$e->getMessage()}. Backing off for {$sleepMs}ms (Attempt {$attempt}/{$maxRetries})", [
                        'endpoint' => $this->sanitizeUrl($url),
                        'attempt' => $attempt,
                    ]);

                    usleep($sleepMs * 1000);
                    continue;
                }
            }
        }

        $totalDurationMs = round((microtime(true) - $startTime) * 1000, 2);
        $this->logMetric($provider, strtoupper($method), $url, $response ? $response->status() : 500, $totalDurationMs, $attempt - 1, false);

        if ($response) {
            return $response;
        }

        throw $lastException ?? new Exception("External API request to {$providerName} failed after {$maxRetries} retries.");
    }

    /**
     * Enforce outbound quota limits per minute, hour, day, and month with tiered warning thresholds.
     *
     * @param string $provider
     * @param array $config
     * @throws ExternalApiRateLimitException
     */
    protected function enforceRateLimits(string $provider, array $config): void
    {
        $rateLimits = $config['rate_limit'] ?? [];
        $warnThresholdPct = (int) ($rateLimits['warning_threshold_pct'] ?? 80);
        $critThresholdPct = (int) ($rateLimits['critical_threshold_pct'] ?? 90);

        // 1. Per-minute rate limit (Sliding token window)
        $minuteLimit = (int) ($rateLimits['max_requests_per_minute'] ?? 60);
        $minuteKey = "ext_api_rate:{$provider}:minute";

        if (RateLimiter::tooManyAttempts($minuteKey, $minuteLimit)) {
            $seconds = RateLimiter::availableIn($minuteKey);
            Log::warning("[ExternalAPI:{$provider}] Outbound rate limit reached ({$minuteLimit} req/min). Throttled for {$seconds}s.");
            throw new ExternalApiRateLimitException("Outbound quota limit reached for {$provider}. Retry in {$seconds} seconds.", 429, $seconds);
        }

        // 2. Hourly quota tracking & budget enforcement
        if (!empty($rateLimits['max_requests_per_hour'])) {
            $this->checkQuotaPeriod($provider, 'hourly', date('Y-m-d-H'), (int) $rateLimits['max_requests_per_hour'], 7200, 3600, $warnThresholdPct, $critThresholdPct);
        }

        // 3. Daily quota tracking & budget enforcement
        if (!empty($rateLimits['max_requests_per_day'])) {
            $this->checkQuotaPeriod($provider, 'daily', date('Y-m-d'), (int) $rateLimits['max_requests_per_day'], 172800, 86400, $warnThresholdPct, $critThresholdPct);
        }

        // 4. Monthly quota tracking & budget enforcement
        if (!empty($rateLimits['max_requests_per_month'])) {
            $this->checkQuotaPeriod($provider, 'monthly', date('Y-m'), (int) $rateLimits['max_requests_per_month'], 3024000, 86400 * 30, $warnThresholdPct, $critThresholdPct);
        }

        // Record the minute attempt
        RateLimiter::hit($minuteKey, 60);
    }

    /**
     * Check, track, and log warnings for a specific quota time window.
     */
    protected function checkQuotaPeriod(
        string $provider,
        string $period,
        string $timeKey,
        int $limit,
        int $ttlSeconds,
        int $retryAfterSeconds,
        int $warnPct,
        int $critPct
    ): void {
        $cacheKey = "ext_api_{$period}:{$provider}:{$timeKey}";
        $current = (int) Cache::get($cacheKey, 0);

        // Check Hard Stop (100% limit reached)
        if ($current >= $limit) {
            Log::error("[ExternalAPI:{$provider}] API usage limit reached: 100% of configured {$period} limit has been reached ({$current}/{$limit}). Hard stop active.");
            throw new ExternalApiRateLimitException("Configured {$period} quota ceiling reached for {$provider}.", 429, $retryAfterSeconds);
        }

        // Calculate usage percentage after this request
        $nextCount = $current + 1;
        $usagePercent = round(($nextCount / $limit) * 100, 1);

        // Warning thresholds evaluation
        if ($usagePercent >= $critPct) {
            Log::warning("[ExternalAPI:{$provider}] API usage critical warning: {$usagePercent}% of the configured {$period} limit has been reached ({$nextCount}/{$limit}).");
        } elseif ($usagePercent >= $warnPct) {
            Log::warning("[ExternalAPI:{$provider}] API usage warning: {$usagePercent}% of the configured {$period} limit has been reached ({$nextCount}/{$limit}).");
        }

        // Increment count and maintain TTL
        if ($current === 0) {
            Cache::put($cacheKey, 1, $ttlSeconds);
        } else {
            Cache::increment($cacheKey);
        }
    }

    /**
     * Get sanitized URL removing sensitive query params and tokens.
     */
    protected function sanitizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $clean = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '');
        return $clean;
    }

    /**
     * Log request performance metric without sensitive payload information.
     */
    protected function logMetric(string $provider, string $method, string $url, int $status, float $durationMs, int $retries, bool $success): void
    {
        $logData = [
            'provider' => $provider,
            'method' => $method,
            'endpoint' => $this->sanitizeUrl($url),
            'timestamp' => now()->toIso8601String(),
            'status' => $status,
            'duration_ms' => $durationMs,
            'retries' => $retries,
            'success' => $success,
            'rate_limit_encountered' => ($status === 429),
        ];

        if ($success) {
            Log::info("[ExternalAPI:{$provider}] Outbound API call completed", $logData);
        } else {
            Log::warning("[ExternalAPI:{$provider}] Outbound API call completed with non-success", $logData);
        }
    }
}
