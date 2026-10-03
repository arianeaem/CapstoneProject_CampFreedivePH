<?php

namespace App\Services;

use App\Services\ExternalApi\ExternalApiClient;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Weather Safety Machine Learning Microservice Client.
 *
 * Architecture & Dual-Engine Rationale:
 * This service communicates with the standalone Python FastAPI / ONNX inference microservice.
 * Camp FreedivePH uses a dual-engine architecture:
 * 1. Native PHP Heuristic Engine (WeatherForecastService): Evaluates 9 marine variables against Coast Guard safety rules.
 * 2. Predictive ONNX Microservice (WeatherSafetyMLService): Runs 12 multi-horizon regressor and classifier models
 *    predicting wave dynamics, wind speeds, and ocean currents up to 16 days ahead.
 * 3. Redis-Backed Circuit Breaker: Fails fast (<0.1ms) when the microservice is temporarily down, preventing
 *    HTTP connection timeouts from stalling PHP worker threads.
 *
 * Fault Tolerance:
 * If the ML microservice is unreachable, times out, or returns a 5xx error, the client catches the exception,
 * trips the circuit breaker after 3 consecutive failures, and gracefully returns `null`. The calling controllers
 * automatically fall back to the native PHP heuristic engine ensuring zero downtime for customers booking sessions.
 */
class WeatherSafetyMLService
{
    protected string $baseUrl;
    protected int $timeout;
    protected bool $enabled;
    protected ExternalApiClient $apiClient;

    // Circuit Breaker State Constants
    public const CIRCUIT_STATE_CLOSED = 'CLOSED';
    public const CIRCUIT_STATE_OPEN = 'OPEN';
    public const CIRCUIT_STATE_HALF_OPEN = 'HALF_OPEN';

    // Circuit Breaker Operational Thresholds
    public const FAILURE_THRESHOLD = 3; // 3 consecutive failures trip the circuit
    public const COOLDOWN_SECONDS = 30; // 30 seconds cooldown before trial probe
    public const CACHE_KEY_STATE = 'ml_circuit_breaker:state';
    public const CACHE_KEY_FAILURES = 'ml_circuit_breaker:failures';
    public const CACHE_KEY_TRIPPED_AT = 'ml_circuit_breaker:tripped_at';

    public const RISK_TIERS = [
        0 => 'Very Safe',
        1 => 'Safe',
        2 => 'Moderate',
        3 => 'High Risk',
        4 => 'Critical Risk',
    ];

    public const RISK_KEYS = [
        'Very Safe' => 'very_safe',
        'Safe' => 'safe',
        'Moderate' => 'moderate',
        'High Risk' => 'high_risk',
        'Critical Risk' => 'critical_risk',
    ];

    public const OPERATIONAL_STATUS_LABELS = [
        'TACTICAL_CLEARANCE' => 'Live Departure Clearance (1 hour before departure)',
        'PROVISIONAL_TREND_OUTLOOK' => '24-Hour Planning Forecast (Final clearance evaluated 1 hour before departure)',
        'EXTENDED_TREND_OUTLOOK' => 'Extended Planning Outlook (Advance Planning)',
        'CONCLUDED' => 'Concluded Session',
        'BEYOND_HORIZON' => 'Beyond 16-Day Forecast Horizon',
    ];

    public const TRAINED_HORIZONS = [1, 6, 12, 24, 48, 72, 96, 144, 168];

    /**
     * Snap continuous lead time H = (target dive timestamp - current timestamp)
     * to whichever of the 9 trained horizon buckets is closest to that H:
     * [1h, 6h, 12h, 24h, 48h, 72h, 96h, 144h, 168h].
     */
    public static function snapToClosestHorizon(int $hours): int
    {
        $hInt = max(1, $hours);
        $closest = self::TRAINED_HORIZONS[0];
        $minDiff = abs($hInt - $closest);

        foreach (self::TRAINED_HORIZONS as $h) {
            $diff = abs($hInt - $h);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $h;
            }
        }

        return $closest;
    }

    /**
     * Initializes the ML client from configuration.
     *
     * Note: Timeout is deliberately capped at 4s so slow network conditions
     * never block the customer-facing booking checkout page.
     */
    public function __construct(?ExternalApiClient $apiClient = null)
    {
        $this->baseUrl = rtrim((string) config('services.ml_safety.url', 'http://127.0.0.1:8001'), '/');
        $this->timeout = (int) config('services.ml_safety.timeout', 4);
        $this->enabled = (bool) config('services.ml_safety.enabled', true);
        $this->apiClient = $apiClient ?? app(ExternalApiClient::class);
    }

    /**
     * Check if ML Safety microservice is enabled and configured.
     *
     * @return bool True if enabled and baseUrl is present.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->baseUrl);
    }

    /**
     * Determine whether the circuit breaker permits outbound requests.
     *
     * State Machine:
     * - CLOSED: Requests pass through normally.
     * - OPEN: Requests fail fast (<0.1ms) unless cooldown has expired.
     * - HALF_OPEN: One trial probe request is permitted to test microservice recovery.
     */
    public function isCircuitAvailable(): bool
    {
        $state = (string) Cache::get(self::CACHE_KEY_STATE, self::CIRCUIT_STATE_CLOSED);

        if ($state === self::CIRCUIT_STATE_CLOSED) {
            return true;
        }

        if ($state === self::CIRCUIT_STATE_OPEN) {
            $trippedAt = (int) Cache::get(self::CACHE_KEY_TRIPPED_AT, 0);
            $elapsedSeconds = now()->timestamp - $trippedAt;

            if ($elapsedSeconds >= self::COOLDOWN_SECONDS) {
                // Cooldown elapsed -> Transition to HALF_OPEN to probe microservice recovery
                Cache::put(self::CACHE_KEY_STATE, self::CIRCUIT_STATE_HALF_OPEN, now()->addMinutes(5));
                Log::info('[WeatherSafetyMLService] Circuit breaker transitioning from OPEN to HALF_OPEN (probing recovery).');
                return true;
            }

            // Circuit remains open -> Fail-fast immediately
            return false;
        }

        if ($state === self::CIRCUIT_STATE_HALF_OPEN) {
            return true;
        }

        return true;
    }

    /**
     * Record a successful request, resetting failures and closing the circuit.
     */
    public function recordSuccess(): void
    {
        $previousState = (string) Cache::get(self::CACHE_KEY_STATE, self::CIRCUIT_STATE_CLOSED);
        if ($previousState !== self::CIRCUIT_STATE_CLOSED) {
            Log::info('[WeatherSafetyMLService] Circuit breaker recovered: state reset to CLOSED.');
        }

        Cache::put(self::CACHE_KEY_STATE, self::CIRCUIT_STATE_CLOSED, now()->addDays(1));
        Cache::put(self::CACHE_KEY_FAILURES, 0, now()->addDays(1));
        Cache::forget(self::CACHE_KEY_TRIPPED_AT);
    }

    /**
     * Record a request failure (timeout or 5xx), incrementing counters and tripping circuit if threshold reached.
     */
    public function recordFailure(?string $reason = null): void
    {
        $state = (string) Cache::get(self::CACHE_KEY_STATE, self::CIRCUIT_STATE_CLOSED);
        $failures = (int) Cache::get(self::CACHE_KEY_FAILURES, 0) + 1;
        Cache::put(self::CACHE_KEY_FAILURES, $failures, now()->addMinutes(10));

        if ($state === self::CIRCUIT_STATE_HALF_OPEN || $failures >= self::FAILURE_THRESHOLD) {
            Cache::put(self::CACHE_KEY_STATE, self::CIRCUIT_STATE_OPEN, now()->addMinutes(10));
            Cache::put(self::CACHE_KEY_TRIPPED_AT, now()->timestamp, now()->addMinutes(10));

            Log::warning("[WeatherSafetyMLService] Circuit breaker TRIPPED to OPEN state. Consecutive failures: {$failures}. Reason: {$reason}. Failing fast for " . self::COOLDOWN_SECONDS . "s.");
        }
    }

    /**
     * Retrieve the current circuit breaker status for health monitoring and diagnostics.
     */
    public function getCircuitStatus(): array
    {
        $state = (string) Cache::get(self::CACHE_KEY_STATE, self::CIRCUIT_STATE_CLOSED);
        $failures = (int) Cache::get(self::CACHE_KEY_FAILURES, 0);
        $trippedAt = (int) Cache::get(self::CACHE_KEY_TRIPPED_AT, 0);

        return [
            'state' => $state,
            'is_available' => $this->isCircuitAvailable(),
            'consecutive_failures' => $failures,
            'failure_threshold' => self::FAILURE_THRESHOLD,
            'cooldown_seconds' => self::COOLDOWN_SECONDS,
            'tripped_at' => $trippedAt > 0 ? Carbon::createFromTimestamp($trippedAt, WeatherForecastService::TIMEZONE)->toIso8601String() : null,
            'seconds_remaining' => ($state === self::CIRCUIT_STATE_OPEN && $trippedAt > 0) ? max(0, self::COOLDOWN_SECONDS - (now()->timestamp - $trippedAt)) : 0,
        ];
    }

    /**
     * Manually reset the circuit breaker state to CLOSED.
     */
    public function resetCircuit(): void
    {
        Cache::put(self::CACHE_KEY_STATE, self::CIRCUIT_STATE_CLOSED, now()->addDays(1));
        Cache::put(self::CACHE_KEY_FAILURES, 0, now()->addDays(1));
        Cache::forget(self::CACHE_KEY_TRIPPED_AT);
    }

    /**
     * Assess a dive booking session through the 12 ONNX ML inference pipeline.
     *
     * @param string $date YYYY-MM-DD
     * @param string $startTime HH:MM
     * @param string $endTime HH:MM
     * @param array $boundaryWeather Array of hourly atmospheric readings
     * @param array|null $pagasa Optional PAGASA signals
     * @return array|null Standardized ML assessment result or null on failure/disabled
     */
    public function assessBookingSession(
        string $date,
        string $startTime,
        string $endTime,
        array $boundaryWeather,
        ?array $pagasa = null
    ): ?array {
        if (!$this->isEnabled()) {
            return null;
        }

        // Fail fast in <0.1ms if the circuit breaker is currently OPEN
        if (!$this->isCircuitAvailable()) {
            Log::debug("[WeatherSafetyMLService] Circuit is OPEN, skipping HTTP call to {$this->baseUrl} for {$date} (fail-fast active)");
            return null;
        }

        if (empty($boundaryWeather)) {
            return null;
        }

        try {
            $payload = [
                'planned_date' => $date,
                'dive_start' => $startTime,
                'dive_end' => $endTime,
                'boundary_weather' => $boundaryWeather,
                'pagasa' => $pagasa ?? [
                    'tcws_signal' => 0,
                    'gale_warning' => false,
                    'tsunami_warning' => false,
                ],
                'site_name' => 'Anilao, Mabini, Batangas',
            ];

            $response = $this->apiClient->execute('ml_service', 'POST', "{$this->baseUrl}/assess-booking", [
                'json' => $payload,
                'timeout' => $this->timeout,
                'max_retries' => 1,
            ]);

            if (!$response->successful()) {
                $this->recordFailure("HTTP {$response->status()}");
                Log::warning('[WeatherSafetyMLService] ML assessment endpoint returned non-200', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'date' => $date,
                ]);
                return null;
            }

            $data = $response->json();

            // Strict Quantile Integrity: If physics_forecast object is present, validate all quantile bounds
            if (isset($data['physics_forecast'])) {
                $this->validateQuantiles($data['physics_forecast']);
            }

            $this->recordSuccess();
            return $this->standardizeResponse($data);
        } catch (Exception $e) {
            $this->recordFailure($e->getMessage());
            Log::warning('[WeatherSafetyMLService] Exception calling ML assessment: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch multi-horizon physics forecast with quantiles from /forecast endpoint.
     * Enforces strict validation: malformed or missing quantiles increment the circuit breaker failure counter.
     *
     * @param int $horizonHours
     * @param array|null $boundaryWeather
     * @return array|null
     */
    public function fetchPhysicsForecast(int $horizonHours = 24, ?array $boundaryWeather = null): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        if (!$this->isCircuitAvailable()) {
            Log::warning('[WeatherSafetyMLService] fetchPhysicsForecast blocked by circuit breaker.');
            return null;
        }

        try {
            $payload = [
                'horizon_hours' => $horizonHours,
            ];
            if (!empty($boundaryWeather)) {
                $payload['readings'] = $boundaryWeather;
            }

            $response = $this->apiClient->execute('ml_service', 'POST', "{$this->baseUrl}/forecast", [
                'json' => $payload,
                'timeout' => $this->timeout,
                'max_retries' => 1,
            ]);

            if (!$response->successful()) {
                $this->recordFailure("HTTP {$response->status()} on /forecast");
                return null;
            }

            $data = $response->json();
            $physics = $data['physics_forecast'] ?? $data['physics'] ?? null;

            if (!$physics || !is_array($physics)) {
                throw new Exception("Missing physics_forecast object in /forecast response");
            }

            // Strictly validate quantile schema — throws Exception on malformed/missing fields
            $this->validateQuantiles($physics);

            $this->recordSuccess();
            return $data;
        } catch (Exception $e) {
            $this->recordFailure("Quantile validation or connection failure: " . $e->getMessage());
            Log::warning('[WeatherSafetyMLService] Failed physics forecast request: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Directly query the ONNX raw physics engine without formatting or domain heuristics.
     */
    public function assessRaw(int $horizonHours, ?array $boundaryWeather = null): ?array
    {
        return $this->fetchPhysicsForecast($horizonHours, $boundaryWeather);
    }

    /**
     * Validate that the physics forecast contains valid numeric p10, p50, p90 quantile structures.
     *
     * @param array $physics
     * @return bool
     * @throws Exception If any required quantile field is missing or malformed
     */
    public function validateQuantiles(array $physics): bool
    {
        $requiredQuantileFields = [
            'significant_wave_height_m',
            'peak_period_s',
            'swell_height_m',
            'wind_wave_height_m',
            'wind_speed_kmh',
            'wind_gust_kmh',
            'wind_direction_deg',
            'sea_level_pressure_hpa',
            'current_speed_ms',
            'current_direction_deg',
        ];

        foreach ($requiredQuantileFields as $field) {
            if (!isset($physics[$field])) {
                throw new Exception("Missing required quantile field: {$field}");
            }

            $q = $physics[$field];
            if (!is_array($q) || !isset($q['p10']) || !isset($q['p50']) || !isset($q['p90'])) {
                throw new Exception("Malformed quantile object for field '{$field}' (must contain p10, p50, p90)");
            }

            if (!is_numeric($q['p10']) || !is_numeric($q['p50']) || !is_numeric($q['p90'])) {
                throw new Exception("Non-numeric quantile bounds detected for field '{$field}'");
            }
        }

        return true;
    }

    /**
     * Transform raw Open-Meteo hourly readings into the schema required by ML inference.
     */
    public function formatBoundaryWeather(array $hourlyReadings): array
    {
        $formatted = [];

        foreach ($hourlyReadings as $h) {
            $timestamp = $h['time'] ?? $h['timestamp'] ?? null;
            if (!$timestamp) {
                continue;
            }

            $windSpeedKmh = (float) ($h['wind_speed_10m'] ?? $h['wind_speed'] ?? 0.0);
            $windGustKmh = (float) ($h['wind_gusts_10m'] ?? $h['wind_gust'] ?? $windSpeedKmh * 1.25);
            $windDir = (float) ($h['wind_direction_10m'] ?? $h['wind_dir'] ?? 0.0);
            $pressureHpa = (float) ($h['surface_pressure'] ?? $h['slp'] ?? 1012.0);
            $rainMm = (float) ($h['rain'] ?? $h['precipitation'] ?? $h['rain_rate_mm_hr'] ?? 0.0);
            $currentSpeed = isset($h['ocean_current_velocity']) ? (float) $h['ocean_current_velocity'] : (isset($h['current_speed']) ? (float) $h['current_speed'] : null);
            $currentDir = isset($h['ocean_current_direction']) ? (float) $h['ocean_current_direction'] : (isset($h['current_dir']) ? (float) $h['current_dir'] : null);

            $entry = [
                'timestamp' => $timestamp,
                'wind_speed' => round($windSpeedKmh, 2),
                'wind_gust' => round($windGustKmh, 2),
                'wind_dir' => round($windDir, 1),
                'slp' => round($pressureHpa, 2),
                'rain_rate_mm_hr' => round($rainMm, 2),
            ];

            if ($currentSpeed !== null) {
                $entry['current_speed'] = round($currentSpeed, 3);
            }
            if ($currentDir !== null) {
                $entry['current_dir'] = round($currentDir, 1);
            }

            $formatted[] = $entry;
        }

        return $formatted;
    }

    /**
     * Standardize FastAPI response into uniform 5-tier classification structure.
     */
    protected function standardizeResponse(array $raw): array
    {
        $worstHour = $raw['worst_hour'] ?? [];
        $rawRec = $raw['overall_recommendation'] ?? $worstHour['final_tier_name'] ?? $raw['displayed_risk_name'] ?? 'Safe';

        // Standardize recommendation to the 5 official safety tiers: Very Safe, Safe, Moderate, High Risk, Critical Risk
        $recommendation = match (trim($rawRec)) {
            'Very Safe', 'GO' => 'Very Safe',
            'Safe', 'PROVISIONAL_GO' => 'Safe',
            'Moderate', 'CAUTION_ADVANCED_ONLY' => 'Moderate',
            'High Risk', 'HIGH_RISK_NO_GO' => 'High Risk',
            'Critical Risk', 'NO_GO' => 'Critical Risk',
            default => 'Safe',
        };

        $rawTierName = $worstHour['final_tier_name'] ?? $raw['displayed_risk_name'] ?? $recommendation;
        $classification = match (trim($rawTierName)) {
            'Very Safe', 'GO' => 'Very Safe',
            'Safe', 'PROVISIONAL_GO' => 'Safe',
            'Moderate', 'CAUTION_ADVANCED_ONLY' => 'Moderate',
            'High Risk', 'HIGH_RISK_NO_GO' => 'High Risk',
            'Critical Risk', 'NO_GO' => 'Critical Risk',
            default => $recommendation,
        };

        $riskKey = self::RISK_KEYS[$classification] ?? 'safe';
        $opStatus = $raw['overall_operational_status'] ?? 'PROVISIONAL_TREND_OUTLOOK';
        $opStatusLabel = self::OPERATIONAL_STATUS_LABELS[$opStatus] ?? $opStatus;

        return [
            'success' => true,
            'source' => 'ml_onnx_microservice',
            'planned_date' => $raw['planned_date'] ?? null,
            'dive_start' => $raw['dive_start'] ?? null,
            'dive_end' => $raw['dive_end'] ?? null,
            'overall_recommendation' => $recommendation,
            'ml_recommendation' => $recommendation,
            'ml_classification' => $classification,
            'ml_risk_key' => $riskKey,
            'operational_status' => $opStatus,
            'operational_status_label' => $opStatusLabel,
            'is_authoritative_go' => (bool) ($raw['is_authoritative_go'] ?? false),
            'safety_threshold_triggered' => (bool) ($raw['overall_safety_threshold_triggered'] ?? $raw['safety_threshold_triggered'] ?? $raw['overall_hard_gate_triggered'] ?? false),
            'hard_gate_triggered' => (bool) ($raw['overall_safety_threshold_triggered'] ?? $raw['safety_threshold_triggered'] ?? $raw['overall_hard_gate_triggered'] ?? false),
            'worst_hour' => [
                'timestamp' => $worstHour['timestamp'] ?? null,
                'hour' => $worstHour['hour'] ?? null,
                'horizon_hours' => $worstHour['horizon_hours'] ?? null,
                'classification' => $worstHour['final_tier_name'] ?? $classification,
                'primary_hazard' => $worstHour['primary_hazard'] ?? 'Normal Marine Conditions',
                'advisory_message' => $worstHour['advisory_message'] ?? '',
                'safety_threshold_triggered' => (bool) ($worstHour['safety_threshold_triggered'] ?? $worstHour['hard_gate_triggered'] ?? false),
                'hard_gate_triggered' => (bool) ($worstHour['safety_threshold_triggered'] ?? $worstHour['hard_gate_triggered'] ?? false),
                'override_reasons' => $worstHour['override_reasons'] ?? [],
            ],
            'hourly_assessments' => $raw['hourly_assessments'] ?? [],
            'generated_at' => $raw['generated_at'] ?? now()->toIso8601String(),
            'routed_horizon_bucket' => $raw['routed_horizon_bucket'] ?? null,
            'physics_forecast' => $raw['physics_forecast'] ?? null,
        ];
    }
}
