<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
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
        $response->assertSee('Net Collections');
    }

    public function test_admin_can_access_reports_dashboard_without_financial_tab(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/reports');

        $response->assertStatus(200);
        $response->assertSee('Reports & Analytics', false);
        $response->assertSee('Bookings & Demographics', false);
        $response->assertDontSee('Net Collections');
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

    public function test_csv_export_streams_proper_csv_content(): void
    {
        $response = $this->actingAs($this->owner)->get('/owner/reports/export?type=revenue&preset=this_month');

        $response->assertStatus(200);
        $this->assertEquals('text/csv; charset=UTF-8', $response->headers->get('content-type'));
        $this->assertStringContainsString('attachment; filename=', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('revenue-', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Monthly/Yearly Total Revenue and Growth (%)', $response->streamedContent());
        $this->assertStringContainsString('Quota/Breakeven', $response->streamedContent());
    }

    public function test_print_summary_renders_properly(): void
    {
        $response = $this->actingAs($this->owner)->get('/owner/reports/print?preset=this_month');

        $response->assertStatus(200);
        $response->assertSee('Executive', false);
        $response->assertSee('Key Performance Indicators');
    }
}
