<?php

namespace App\Services\ExternalApi;

use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Wrapper for calling outside APIs (PayMongo, Open-Meteo, ML service).
 *
 * - checks our own per-minute/hour/day/month limits before sending, so we don't hit the provider limit
 * - retries on 429 and 5xx errors with backoff (uses Retry-After if given)
 * - logs time, status and retries, without tokens or personal info
 */
class ExternalApiClient
{
    /**
     * Send an HTTP request with limits, retries and logging.
     *
     * @param string $provider 'paymongo' or 'open_meteo'
     * @param string $method 'GET', 'POST', 'PUT', etc.
     * @param string $url full URL
     * @param array $options query, headers, payload, auth, timeout
     * @return Response
     * @throws ExternalApiRateLimitException if our own limit is reached
     * @throws Exception if it still fails after all retries
     */
    public function execute(string $provider, string $method, string $url, array $options = []): Response
    {
        $config = config("external_apis.{$provider}", []);
        $providerName = $config['name'] ?? ucfirst($provider);

        // 1. Check our request limits (minute, hour, day, month)
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
                // Build the request
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

                // Send it
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

                // Don't retry client errors (400, 401, 403, 404, 422)
                $status = $response->status();
                if ($status >= 400 && $status < 500 && $status !== 429) {
                    $totalDurationMs = round((microtime(true) - $startTime) * 1000, 2);
                    $this->logMetric($provider, $methodUpper, $url, $status, $totalDurationMs, $attempt - 1, false);
                    return $response;
                }

                // Retry on 429 or 5xx
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

                // Log the result
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
     * Check the request limits per minute, hour, day and month.
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

        // 1. Per minute
        $minuteLimit = (int) ($rateLimits['max_requests_per_minute'] ?? 60);
        $minuteKey = "ext_api_rate:{$provider}:minute";

        if (RateLimiter::tooManyAttempts($minuteKey, $minuteLimit)) {
            $seconds = RateLimiter::availableIn($minuteKey);
            Log::warning("[ExternalAPI:{$provider}] Outbound rate limit reached ({$minuteLimit} req/min). Throttled for {$seconds}s.");
            throw new ExternalApiRateLimitException("Outbound quota limit reached for {$provider}. Retry in {$seconds} seconds.", 429, $seconds);
        }

        // 2. Per hour
        if (!empty($rateLimits['max_requests_per_hour'])) {
            $this->checkQuotaPeriod($provider, 'hourly', date('Y-m-d-H'), (int) $rateLimits['max_requests_per_hour'], 7200, 3600, $warnThresholdPct, $critThresholdPct);
        }

        // 3. Per day
        if (!empty($rateLimits['max_requests_per_day'])) {
            $this->checkQuotaPeriod($provider, 'daily', date('Y-m-d'), (int) $rateLimits['max_requests_per_day'], 172800, 86400, $warnThresholdPct, $critThresholdPct);
        }

        // 4. Per month
        if (!empty($rateLimits['max_requests_per_month'])) {
            $this->checkQuotaPeriod($provider, 'monthly', date('Y-m'), (int) $rateLimits['max_requests_per_month'], 3024000, 86400 * 30, $warnThresholdPct, $critThresholdPct);
        }

        // Count this minute's request
        RateLimiter::hit($minuteKey, 60);
    }

    /**
     * Check and count one time window, and log a warning when we get close to the limit.
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

        // Limit reached
        if ($current >= $limit) {
            Log::error("[ExternalAPI:{$provider}] API usage limit reached: 100% of configured {$period} limit has been reached ({$current}/{$limit}). Hard stop active.");
            throw new ExternalApiRateLimitException("Configured {$period} quota ceiling reached for {$provider}.", 429, $retryAfterSeconds);
        }

        // Usage % after this request
        $nextCount = $current + 1;
        $usagePercent = round(($nextCount / $limit) * 100, 1);

        // Warnings
        if ($usagePercent >= $critPct) {
            Log::warning("[ExternalAPI:{$provider}] API usage critical warning: {$usagePercent}% of the configured {$period} limit has been reached ({$nextCount}/{$limit}).");
        } elseif ($usagePercent >= $warnPct) {
            Log::warning("[ExternalAPI:{$provider}] API usage warning: {$usagePercent}% of the configured {$period} limit has been reached ({$nextCount}/{$limit}).");
        }

        // Add 1 and keep the expiry
        if ($current === 0) {
            Cache::put($cacheKey, 1, $ttlSeconds);
        } else {
            Cache::increment($cacheKey);
        }
    }

    /**
     * URL without tokens or secret query params.
     */
    protected function sanitizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $clean = ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . ($parsed['path'] ?? '');
        return $clean;
    }

    /**
     * Log the request info (no payload).
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
