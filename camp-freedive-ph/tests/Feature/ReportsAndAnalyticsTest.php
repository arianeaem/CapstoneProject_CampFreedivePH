<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsAndAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $admin;
    protected User $coach;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create([
            'role' => 'owner',
            'status' => 'active',
            'must_change_password' => false,
        ]);

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
    }

    public function test_owner_can_access_reports_dashboard(): void
    {
        $response = $this->actingAs($this->owner)->get('/owner/reports');

        $response->assertStatus(200);
        $response->assertSee('Reports & Analytics', false);
        $response->assertSee('Revenue', false);
        $response->assertSee('Money kept');
    }

    public function test_admin_can_access_reports_dashboard_without_financial_tab(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/reports');

        $response->assertStatus(200);
        $response->assertSee('Reports & Analytics', false);
        $response->assertSee('Bookings & Demographics', false);
        $response->assertDontSee('Money kept');
    }

    public function test_coach_cannot_access_reports_dashboard(): void
    {
        $response = $this->actingAs($this->coach)->get('/owner/reports');
        $response->assertStatus(403);

        $responseAdmin = $this->actingAs($this->coach)->get('/admin/reports');
        $responseAdmin->assertStatus(403);
    }

    public function test_unauthenticated_user_redirected_to_login(): void
    {
        $response = $this->get('/owner/reports');
        $response->assertRedirect('/login');
    }

    public function test_reports_filter_by_date_presets_and_custom_range(): void
    {
        $now = Carbon::now('Asia/Manila');

        // Create a batch and booking
        $batch = Batch::create([
            'batch_code' => 'BATCH-TEST-001',
            'name' => 'Test Batch Discovery',
            'start_date' => $now->toDateString(),
            'end_date' => $now->copy()->addDays(1)->toDateString(),
            'status' => 'confirmed',
            'max_capacity' => 20,
        ]);

        $booking = Booking::create([
            'batch_id' => $batch->id,
            'booking_number' => 'CFP-TEST-001',
            'pin' => '1234',
            'contact_name' => 'John Diver',
            'contact_email' => 'john@example.com',
            'contact_phone' => '09171234567',
            'class_type' => 'discovery',
            'start_date' => $now->toDateString(),
            'end_date' => $now->copy()->addDays(1)->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 4250,
            'balance_amount' => 2250,
        ]);

        Payment::create([
            'booking_id' => $booking->id,
            'transaction_id' => 'TXN-TEST-12345',
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'amount' => 2000,
            'status' => 'completed',
        ]);

        // Query with this_month preset
        $responseMonth = $this->actingAs($this->owner)->get('/owner/reports?preset=this_month');
        $responseMonth->assertStatus(200);
        $responseMonth->assertSee('₱2,000.00');

        // Query with custom range
        $responseCustom = $this->actingAs($this->owner)->get('/owner/reports?preset=custom&start_date=' . $now->toDateString() . '&end_date=' . $now->toDateString());
        $responseCustom->assertStatus(200);
        $responseCustom->assertSee('₱2,000.00');
    }

    public function test_exports_download_branded_excel_workbooks(): void
    {
        foreach (['revenue' => 'Monthly/Yearly Total Revenue and Growth (%)', 'bookings' => 'Where every booking stands', 'batches' => 'Batches at a glance'] as $type => $section) {
            $response = $this->actingAs($this->owner)->get("/owner/reports/export?type={$type}&preset=this_month");

            $response->assertStatus(200);
            $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('content-type'));
            $this->assertStringContainsString("{$type}-", $response->headers->get('content-disposition'));
            $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));

            $xlsx = $response->getContent();
            $this->assertStringStartsWith("PK", $xlsx);
            $this->assertStringContainsString($section, $xlsx);
        }

        $this->assertStringContainsString('Quota/Breakeven', $this->actingAs($this->owner)->get('/owner/reports/export?type=revenue&preset=this_month')->getContent());
    }

    public function test_admin_cannot_export_revenue(): void
    {
        $this->actingAs($this->admin)->get('/admin/reports/export?type=revenue&preset=this_month')->assertStatus(403);
        $this->actingAs($this->admin)->get('/admin/reports/export?type=bookings&preset=this_month')->assertStatus(200);
    }

    public function test_print_summary_renders_properly(): void
    {
        $response = $this->actingAs($this->owner)->get('/owner/reports/print?preset=this_month');

        $response->assertStatus(200);
        $response->assertSee('Reports summary');
        $response->assertSee('At a glance');
        $response->assertSee('Where every booking stands');
        // Only prints by itself when opened directly, so the Reports page's frame prints once
        $response->assertSee('if (window.self === window.top) window.print()', false);
    }
}
