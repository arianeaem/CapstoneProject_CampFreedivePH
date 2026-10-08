<?php

namespace Tests\Unit;

use App\Services\WeatherForecastService;
use Tests\TestCase;

/**
 * Worst-of rating, the two wave-period hazards and the PAGASA Signal 1-2 floor.
 */
class WorstOfScoringTest extends TestCase
{
    protected WeatherForecastService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WeatherForecastService::class);
    }

    private function invokeProtected(string $method, ...$args)
    {
        $m = new \ReflectionMethod($this->service, $method);
        $m->setAccessible(true);

        return $m->invoke($this->service, ...$args);
    }

    public function test_one_high_floor_parameter_is_not_averaged_away(): void
    {
        // Top wave band (>= 1.0 m) alone is only 16% weighted = Very Safe without the floor
        $this->assertSame('High Risk', $this->service->classifyScores(['wave_height' => 4], 16.0));
        $this->assertSame('High Risk', $this->service->classifyScores(['ocean_current' => 3], 14.0));
        $this->assertSame('Moderate', $this->service->classifyScores(['swell_height' => 2], 10.0));
    }

    public function test_floor_never_lowers_and_never_forces_critical(): void
    {
        $this->assertSame('Critical Risk', $this->service->classifyScores(['wave_height' => 1], 85.0));
        $this->assertSame('High Risk', $this->service->classifyScores(['wave_height' => 4, 'swell_height' => 4], 30.0));
        $this->assertSame('Very Safe', $this->service->classifyScores(['wave_height' => 0, 'rain' => 4], 10.0));
    }

    public function test_short_wind_wave_period_scores_as_chop(): void
    {
        // Coach LC: < 3 s feels rough, once the wind waves reach 0.2 m
        $this->assertSame(3, $this->invokeProtected('scoreWavePeriods', 6.0, 2.5, 0.25, null, 0.0));
        // Flat water: Open-Meteo still reports ~2 s wind-wave period, which is not chop
        $this->assertSame(0, $this->invokeProtected('scoreWavePeriods', 4.0, 1.9, 0.1, null, 0.0));
        // No wind-wave period from Open-Meteo: fall back to the combined period
        $this->assertSame(0, $this->invokeProtected('scoreWavePeriods', 8.0, null, 0.0, null, 0.0));
    }

    public function test_long_swell_period_scores_only_when_swell_is_felt(): void
    {
        // Coastal engineer: > 10 s critical energy
        $this->assertSame(4, $this->invokeProtected('scoreWavePeriods', 12.0, 8.0, 0.1, 12.0, 0.6));
        // Same period but a swell under 0.3 m carries little energy
        $this->assertSame(0, $this->invokeProtected('scoreWavePeriods', 12.0, 8.0, 0.1, 12.0, 0.1));
    }

    public function test_pagasa_signal_1_and_2_mean_at_least_high_risk(): void
    {
        $this->assertSame('High Risk', $this->service->overrideFloor(['tcws_signal' => 1]));
        $this->assertSame('High Risk', $this->service->overrideFloor(['tcws_signal' => 2]));
        $this->assertNull($this->service->overrideFloor(['tcws_signal' => 0]));
        $this->assertNull($this->service->overrideFloor(['tcws_signal' => 3])); // Critical via checkOverrideConditions
        $this->assertFalse($this->service->checkOverrideConditions(['tcws_signal' => 2]));
        $this->assertTrue($this->service->checkOverrideConditions(['tcws_signal' => 3]));
    }
}
