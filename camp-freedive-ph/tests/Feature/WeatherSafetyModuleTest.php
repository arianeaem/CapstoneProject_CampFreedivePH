<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\BatchRiskAssessment;
use App\Models\Booking;
use App\Models\HourlyAssessment;
use App\Models\ManualOverride;
use App\Models\NotificationLog;
use App\Models\Payment;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\WeatherForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WeatherSafetyModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $coach;
    protected WeatherForecastService $forecastService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $this->coach = User::factory()->create([
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        $this->forecastService = app(WeatherForecastService::class);
    }

    public function test_assess_batch_evaluates_4_windows_and_stores_day1_and_day2_assessments(): void
    {
        $batch = Batch::create([
            'name' => 'Anilao Weekend Batch #1',
            'batch_code' => 'BATCH-TEST-001',
            'start_date' => Carbon::now('Asia/Manila')->addDays(3)->format('Y-m-d'),
            'end_date' => Carbon::now('Asia/Manila')->addDays(4)->format('Y-m-d'),
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
        ]);

        $result = $this->forecastService->assessBatch($batch, null, $this->admin);

        $this->assertNotNull($result['overall_classification']);
        $this->assertEquals(2, $batch->riskAssessments()->count());
        $this->assertEquals(1, $batch->riskAssessments()->where('day_number', 1)->count());
        $this->assertEquals(1, $batch->riskAssessments()->where('day_number', 2)->count());

        // Hourly assessments for AM and PM windows
        $this->assertGreaterThanOrEqual(10, HourlyAssessment::count());

        $day1 = $batch->latestDay1Assessment;
        $this->assertNotNull($day1);
        $this->assertNotNull($day1->overall_classification);
        $this->assertNotNull($day1->recommended_action);
        $this->assertGreaterThan(0, $day1->amHourlyAssessments()->count());
        $this->assertGreaterThan(0, $day1->pmHourlyAssessments()->count());
    }

    public function test_manual_override_forces_critical_risk_on_both_days(): void
    {
        $batch = Batch::create([
            'name' => 'Stormy Weekend Batch',
            'batch_code' => 'BATCH-STORM-002',
            'start_date' => Carbon::now('Asia/Manila')->addDays(2)->format('Y-m-d'),
            'end_date' => Carbon::now('Asia/Manila')->addDays(3)->format('Y-m-d'),
            'status' => 'confirmed',
        ]);

        $overrideData = [
            'tcws_signal' => 3,
            'gale_warning' => true,
            'reason' => 'PAGASA Marine Warning #3 - Severe Tropical Storm in Batangas waters',
        ];

        $res = $this->forecastService->applyManualOverride($batch, $overrideData, $this->admin, false);

        $this->assertEquals(1, ManualOverride::where('batch_id', $batch->id)->count());

        $day1 = $batch->latestDay1Assessment;
        $day2 = $batch->latestDay2Assessment;

        $this->assertEquals('Critical Risk', $day1->overall_classification);
        $this->assertEquals('Critical Risk', $day2->overall_classification);
        $this->assertNull($day1->weighted_score_pct);
        $this->assertNull($day2->weighted_score_pct);
        $this->assertTrue($day1->override_triggered);
        $this->assertTrue($day2->override_triggered);
        $this->assertEquals('critical_risk', $batch->fresh()->risk_classification);
    }

    public function test_manual_override_with_non_weather_hazard_forces_critical_risk(): void
    {
        $batch = Batch::create([
            'name' => 'Oil Spill Batch',
            'batch_code' => 'BATCH-OIL-004',
            'start_date' => Carbon::now('Asia/Manila')->addDays(2)->format('Y-m-d'),
            'end_date' => Carbon::now('Asia/Manila')->addDays(3)->format('Y-m-d'),
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.weather.override', $batch), [
                'tcws_signal' => 0,
                'other_hazard' => 'oil_spill',
                'reason' => 'Coast Guard advisory: oil spill near Anilao',
            ])
            ->assertSessionHasNoErrors();

        $override = ManualOverride::where('batch_id', $batch->id)->latest('id')->first();
        $this->assertEquals('Oil spill', $override->other_hazard);
        $this->assertContains('Oil spill', $override->active_advisories);
        $this->assertEquals('Critical Risk', $batch->latestDay1Assessment->overall_classification);
        $this->assertEquals('Critical Risk', $batch->latestDay2Assessment->overall_classification);
    }

    public function test_manual_override_other_hazard_requires_description(): void
    {
        $batch = Batch::create([
            'name' => 'Other Hazard Batch',
            'batch_code' => 'BATCH-OTHER-005',
            'start_date' => Carbon::now('Asia/Manila')->addDays(2)->format('Y-m-d'),
            'end_date' => Carbon::now('Asia/Manila')->addDays(3)->format('Y-m-d'),
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.weather.override', $batch), ['other_hazard' => 'other', 'reason' => 'Fish kill'])
            ->assertSessionHasErrors('other_hazard_detail');

        $this->actingAs($this->admin)
            ->post(route('admin.weather.override', $batch), [
                'other_hazard' => 'other',
                'other_hazard_detail' => 'Fish kill reported near Mainit Point',
                'reason' => 'BFAR advisory',
            ])
            ->assertSessionHasNoErrors();

        $this->assertEquals('Fish kill reported near Mainit Point', ManualOverride::where('batch_id', $batch->id)->latest('id')->first()->other_hazard);
        $this->assertEquals('Critical Risk', $batch->latestDay1Assessment->overall_classification);
    }

    public function test_batch_cancellation_cascades_status_creates_100_percent_refund_requests_and_logs_notifications(): void
    {
        $batch = Batch::create([
            'name' => 'Cancelled Batch',
            'batch_code' => 'BATCH-CANCEL-003',
            'start_date' => Carbon::now('Asia/Manila')->addDays(1)->format('Y-m-d'),
            'end_date' => Carbon::now('Asia/Manila')->addDays(2)->format('Y-m-d'),
            'status' => 'confirmed',
        ]);

        $booking = Booking::create([
            'batch_id' => $batch->id,
            'booking_number' => 'BK-SAFETY-001',
            'pin' => '1234',
            'class_type' => 'discovery',
            'start_date' => $batch->start_date,
            'end_date' => $batch->end_date,
            'status' => 'confirmed',
            'contact_name' => 'Juan Dela Cruz',
            'contact_email' => 'juan@example.com',
            'contact_phone' => '09171234567',
            'total_amount' => 7000,
            'balance_due' => 0,
        ]);

        $payment = Payment::create([
            'booking_id' => $booking->id,
            'transaction_id' => 'TXN-SAFETY-001',
            'amount' => 7000,
            'payment_method' => 'gcash',
            'payment_type' => 'full',
            'status' => 'completed',
            'paid_at' => now(),
        ]);

        $notificationsSent = $this->forecastService->cancelBatchWithRefundsAndNotifications(
            $batch,
            'PAGASA Gale Warning and 3.5m wave surge in Anilao',
            $this->admin
        );

        $this->assertEquals(1, $notificationsSent);
        $this->assertEquals('cancelled_by_camp', $batch->fresh()->status);
        $this->assertEquals('cancelled_by_camp', $booking->fresh()->status);

        // 100% Force Majeure Refund created
        $refund = RefundRequest::where('booking_id', $booking->id)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(100, $refund->eligibility_calculated['refund_percentage']);

        // Templated notification log created
        $log = NotificationLog::where('batch_id', $batch->id)->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Good day, Juan Dela Cruz', $log->message_body);
        $this->assertStringContainsString('Full refund', $log->message_body);
        $this->assertStringContainsString('Reschedule', $log->message_body);
    }

    public function test_admin_can_access_weather_monitoring_pages(): void
    {
        $batch = Batch::create([
            'name' => 'Batch 4',
            'batch_code' => 'Batch 4',
            'start_date' => Carbon::now('Asia/Manila')->addDays(5)->format('Y-m-d'),
            'end_date' => Carbon::now('Asia/Manila')->addDays(6)->format('Y-m-d'),
            'status' => 'confirmed',
        ]);

        $responseIndex = $this->actingAs($this->admin)->get(route('admin.weather.index'));
        $responseIndex->assertStatus(200);
        $responseIndex->assertSee('Safety Monitoring');

        $responseShow = $this->actingAs($this->admin)->get(route('admin.weather.show', $batch));
        $responseShow->assertStatus(200);
        $responseShow->assertSee($batch->batch_number);
        $responseShow->assertSee('Overall Batch Assessment');
        $responseShow->assertSee('DAY 1');
        $responseShow->assertSee('DAY 2');
    }

    public function test_coach_is_forbidden_from_admin_weather_module(): void
    {
        $response = $this->actingAs($this->coach)->get(route('admin.weather.index'));
        $response->assertStatus(403);
    }

    public function test_client_weather_preview_api_endpoint_returns_day1_and_day2(): void
    {
        $startDate = Carbon::now('Asia/Manila')->addDays(4)->format('Y-m-d');

        $response = $this->getJson("/api/weather/preview?start_date={$startDate}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'available',
            'start_date',
            'end_date',
            'overall_classification',
            'day1' => ['date', 'classification', 'recommended_action', 'worst_hour', 'lead_time'],
            'day2' => ['date', 'classification', 'recommended_action', 'worst_hour', 'lead_time'],
            'advisory_notes',
        ]);
        $response->assertJson(['available' => true]);
    }
}
