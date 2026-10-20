<?php

namespace Tests\Feature;

use App\Mail\AdminOperationalNotificationMail;
use App\Mail\WeatherRiskNoticeMail;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\User;
use App\Services\WeatherForecastService;
use App\Services\WeatherSafetyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Open-Meteo -> rules -> classification, with no ML model in between.
 * Any HTTP call other than Open-Meteo (e.g. the old model service on :8001) fails the test.
 */
class RuleBasedSafetyPipelineTest extends TestCase
{
    use RefreshDatabase;

    /** Sea data for the first 10 days only, like Open-Meteo's marine range. */
    private const SEA_DAYS = 10;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Mail::fake();
    }

    /**
     * @param float $currentKmh Open-Meteo's ocean_current_velocity (km/h)
     * @param float $gustKmh Open-Meteo's wind_gusts_10m (km/h); 48+ is the Critical hard gate
     */
    private function fakeOpenMeteo(float $currentKmh = 0.3, float $gustKmh = 12.0): void
    {
        $today = Carbon::today(WeatherForecastService::TIMEZONE);
        $times = [];
        $hasSea = [];
        for ($d = 0; $d < WeatherForecastService::MAX_FORECAST_DAYS; $d++) {
            foreach (range(0, 23) as $h) {
                $times[] = $today->copy()->addDays($d)->format('Y-m-d') . sprintf('T%02d:00', $h);
                $hasSea[] = $d < self::SEA_DAYS;
            }
        }
        $sea = fn (float $v) => array_map(fn ($ok) => $ok ? $v : null, $hasSea);
        $all = fn (float $v) => array_fill(0, count($times), $v);

        Http::preventStrayRequests();
        Http::fake([
            'marine-api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'wave_height' => $sea(0.2),
                'wave_period' => $sea(6.0),
                'swell_wave_height' => $sea(0.1),
                'wind_wave_height' => $sea(0.1),
                'ocean_current_velocity' => $sea($currentKmh),
                'swell_wave_period' => $sea(5.0),
                'wind_wave_period' => $sea(2.0),
            ]]),
            'api.open-meteo.com/*' => Http::response(['hourly' => [
                'time' => $times,
                'precipitation' => $all(0.0),
                'rain' => $all(0.0),
                'showers' => $all(0.0),
                'pressure_msl' => $all(1013.0),
                'wind_speed_10m' => $all(8.0),
                'wind_gusts_10m' => $all($gustKmh),
                'wind_direction_10m' => $all(45.0),
            ]]),
        ]);
    }

    private function batchStartingIn(int $days): Batch
    {
        $start = Carbon::today(WeatherForecastService::TIMEZONE)->addDays($days);

        return Batch::create([
            'name' => "Batch +{$days}",
            'batch_code' => "RULES-{$days}",
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'status' => 'open',
        ]);
    }

    public function test_batch_days_are_rated_from_open_meteo_with_rules_only(): void
    {
        $this->fakeOpenMeteo();

        $result = app(WeatherForecastService::class)->assessBatch($this->batchStartingIn(3));

        $this->assertContains($result['day1']['classification'], ['Very Safe', 'Safe']);
        $this->assertContains($result['day2']['classification'], ['Very Safe', 'Safe']);
        $this->assertArrayNotHasKey('ml_assessment', $result);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '8001'));
    }

    public function test_strong_current_alone_makes_the_day_high_risk(): void
    {
        // 2.5 km/h = 0.69 m/s: at or above 0.65 m/s the current alone floors the day at High Risk
        $this->fakeOpenMeteo(currentKmh: 2.5);

        $result = app(WeatherForecastService::class)->assessBatch($this->batchStartingIn(3));

        $this->assertSame('High Risk', $result['day1']['classification']);
        $this->assertSame('High Risk', $result['overall_classification']);
    }

    /** A batch with one admin and one paid participant, checked from 3:00 PM the day before. */
    private function batchWithRecipients(int $daysAway): Batch
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 15:00', WeatherForecastService::TIMEZONE));
        User::factory()->create(['role' => 'admin', 'status' => 'active', 'email' => 'admin@example.com']);
        $batch = $this->batchStartingIn($daysAway);
        Booking::create([
            'batch_id' => $batch->id, 'booking_number' => 'BK-WX-1', 'pin' => '1234',
            'class_type' => 'discovery', 'status' => 'confirmed', 'total_amount' => 4250, 'downpayment_amount' => 2125,
            'balance_amount' => 2125, 'contact_name' => 'Guest', 'contact_email' => 'guest@example.com', 'contact_phone' => '09123456789',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);

        return $batch;
    }

    /** Re-run the scheduled assessment once an hour, like weather:assess-batches. */
    private function assessHourly(Batch $batch, int $times): array
    {
        foreach (range(1, $times) as $_) {
            Carbon::setTestNow(now()->addHour()); // also expires the 60-minute forecast cache
            $result = app(WeatherForecastService::class)->assessBatch($batch->fresh());
        }

        return $result;
    }

    public function test_hourly_rechecks_of_a_high_risk_batch_days_away_send_no_email(): void
    {
        $batch = $this->batchWithRecipients(3);
        $this->fakeOpenMeteo(currentKmh: 2.5);

        $result = $this->assessHourly($batch, 3);

        $this->assertSame('High Risk', $result['overall_classification']);
        Mail::assertNothingSent();
    }

    public function test_hourly_rechecks_within_18_hours_send_each_recipient_one_notice(): void
    {
        $batch = $this->batchWithRecipients(1); // dive at 6:30 AM tomorrow: 14.5 to 12.5 hours away
        $this->fakeOpenMeteo(currentKmh: 2.5);

        $result = $this->assessHourly($batch, 3);

        $this->assertSame('High Risk', $result['overall_classification']);
        Mail::assertSent(WeatherRiskNoticeMail::class, 1);
        Mail::assertSent(WeatherRiskNoticeMail::class, fn ($mail) => $mail->hasTo('guest@example.com') && $mail->level === 'high');
        Mail::assertSent(AdminOperationalNotificationMail::class, 1);
        Mail::assertSent(AdminOperationalNotificationMail::class, fn ($mail) => $mail->hasTo('admin@example.com')
            && str_starts_with($mail->eventTitle, 'High Risk'));
    }

    public function test_high_to_critical_escalation_sends_one_notice_per_level(): void
    {
        $batch = $this->batchWithRecipients(1);
        $this->fakeOpenMeteo(currentKmh: 2.5);
        $this->assertSame('High Risk', $this->assessHourly($batch, 2)['overall_classification']);

        // Http::fake() appends stubs and the first match wins, so start a fresh fake for the gusty forecast
        Http::swap(new HttpFactory);
        $this->fakeOpenMeteo(currentKmh: 2.5, gustKmh: 60.0);
        $this->assertSame('Critical Risk', $this->assessHourly($batch, 2)['overall_classification']);

        Mail::assertSent(WeatherRiskNoticeMail::class, 2);
        Mail::assertSent(WeatherRiskNoticeMail::class, fn ($mail) => $mail->level === 'high');
        Mail::assertSent(WeatherRiskNoticeMail::class, fn ($mail) => $mail->level === 'critical');
        Mail::assertSent(AdminOperationalNotificationMail::class, 2);
        Mail::assertSent(AdminOperationalNotificationMail::class, fn ($mail) => str_starts_with($mail->eventTitle, 'URGENT: Critical Risk'));
    }

    public function test_current_between_0_50_and_0_65_is_moderate(): void
    {
        // 2.0 km/h = 0.56 m/s
        $this->fakeOpenMeteo(currentKmh: 2.0);

        $result = app(WeatherForecastService::class)->assessBatch($this->batchStartingIn(3));

        $this->assertSame('Moderate', $result['day1']['classification']);
    }

    public function test_days_beyond_open_meteo_sea_data_are_not_rated(): void
    {
        $this->fakeOpenMeteo();
        $batch = $this->batchStartingIn(self::SEA_DAYS + 1);

        $result = app(WeatherForecastService::class)->assessBatch($batch);

        $this->assertSame('Not Available', $result['day1']['classification']);
        $this->assertNull($batch->fresh()->risk_classification);
    }

    public function test_booking_preview_rates_dates_in_range(): void
    {
        $this->fakeOpenMeteo();

        $preview = app(WeatherForecastService::class)
            ->previewDateAssessment(Carbon::today(WeatherForecastService::TIMEZONE)->addDays(2));

        $this->assertTrue($preview['available']);
        $this->assertContains($preview['overall_classification'], ['Very Safe', 'Safe']);
        $this->assertArrayNotHasKey('engines', $preview);
    }

    public function test_booking_shows_dates_without_sea_data_as_not_rated_but_bookable(): void
    {
        $this->fakeOpenMeteo();
        $start = Carbon::today(WeatherForecastService::TIMEZONE)->addDays(self::SEA_DAYS + 1);

        $forecast = app(WeatherSafetyService::class)->getForecast($start, $start->copy()->addDay());

        $this->assertSame('Not Available', $forecast['overall_classification']);
        $this->assertSame('not_available', $forecast['risk_level']);
        $this->assertTrue($forecast['is_bookable']);
        $this->assertArrayNotHasKey('wave_height_m', $forecast['day1']);
    }

    public function test_weighted_score_from_plain_scores(): void
    {
        $score = app(WeatherForecastService::class)->computeWeightedScore([
            'wave_height' => 1,
            'wind_speed' => 1,
            'ocean_current' => 0,
            'swell_height' => 1,
            'wave_period' => 0,
            'wind_wave_height' => 1,
            'rain' => 0,
            'sea_level_pressure' => 0,
            'wind_direction' => 0,
        ]);

        $this->assertIsFloat($score);
        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThanOrEqual(100.0, $score);
    }
}
