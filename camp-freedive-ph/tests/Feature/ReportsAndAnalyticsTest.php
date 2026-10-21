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

    /** One paid Discovery booking today, so every summary has something to show. */
    protected function seedPaidBooking(): Carbon
    {
        $now = Carbon::now('Asia/Manila');
        $batch = Batch::create([
            'batch_code' => 'BATCH-PRINT-001',
            'name' => 'Print Batch',
            'start_date' => $now->toDateString(),
            'end_date' => $now->copy()->addDay()->toDateString(),
            'status' => 'confirmed',
            'max_capacity' => 20,
        ]);
        $booking = Booking::create([
            'batch_id' => $batch->id,
            'booking_number' => 'CFP-PRINT-001',
            'pin' => '1234',
            'contact_name' => 'Jane Printdiver',
            'contact_email' => 'jane@example.com',
            'contact_phone' => '09171234567',
            'class_type' => 'discovery',
            'start_date' => $now->toDateString(),
            'end_date' => $now->copy()->addDay()->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 4250,
            'balance_amount' => 2250,
        ]);
        Payment::create([
            'booking_id' => $booking->id,
            'transaction_id' => 'TXN-PRINT-12345',
            'payment_type' => 'downpayment',
            'payment_method' => 'gcash',
            'amount' => 2000,
            'status' => 'completed',
        ]);

        return $now;
    }

    public function test_print_without_type_matches_overall_summary(): void
    {
        $this->seedPaidBooking();

        foreach (['/owner/reports/print?preset=this_month', '/owner/reports/print?preset=this_month&type=overall', '/owner/reports-and-analytics/print-summary?preset=this_month'] as $url) {
            $response = $this->actingAs($this->owner)->get($url);
            $response->assertOk();
            $response->assertSeeInOrder(['Reports summary', 'At a glance', 'Money kept', '2. Money', '3. Bookings', '4. Batches &amp; coaches', 'For internal use only'], false);
            $response->assertSee('₱2,000.00');
            $response->assertDontSee('Price changes from pricing rules');
        }
    }

    public function test_owner_revenue_print_only_contains_money(): void
    {
        $this->seedPaidBooking();

        $response = $this->actingAs($this->owner)->get('/owner/reports/print?type=revenue&preset=this_month');

        $response->assertOk();
        $response->assertSee('Revenue summary');
        $response->assertSee('Money kept');
        $response->assertSee('₱2,000.00');
        $response->assertSee('Price changes from pricing rules');
        $response->assertSee('A4 portrait', false);
        $response->assertDontSee('Where every booking stands');
        $response->assertDontSee('Enough coaches?');
        // No transaction or participant lists
        $response->assertDontSee('TXN-PRINT-12345');
        $response->assertDontSee('CFP-PRINT-001');
        $response->assertDontSee('Jane Printdiver');
    }

    public function test_bookings_and_operations_prints_contain_only_their_category(): void
    {
        $this->seedPaidBooking();

        foreach (['owner' => $this->owner, 'admin' => $this->admin] as $prefix => $user) {
            $bookings = $this->actingAs($user)->get("/{$prefix}/reports/print?type=bookings&preset=this_month");
            $bookings->assertOk();
            $bookings->assertSee('Bookings summary');
            $bookings->assertSee('Where every booking stands');
            $bookings->assertSee('Paid the downpayment');
            $bookings->assertDontSee('Money kept');
            $bookings->assertDontSee('₱');
            $bookings->assertDontSee('Enough coaches?');
            $bookings->assertDontSee('CFP-PRINT-001');
            $bookings->assertDontSee('Jane Printdiver');

            $operations = $this->actingAs($user)->get("/{$prefix}/reports/print?type=operations&preset=this_month");
            $operations->assertOk();
            $operations->assertSee('Batches &amp; coaches summary', false);
            $operations->assertSee('Enough coaches?');
            $operations->assertSee('Active coaches');
            $operations->assertDontSee('Money kept');
            $operations->assertDontSee('₱');
            $operations->assertDontSee('Where every booking stands');
            $operations->assertDontSee('Jane Printdiver');
        }
    }

    public function test_admin_overall_print_has_no_financial_data(): void
    {
        $this->seedPaidBooking();

        $response = $this->actingAs($this->admin)->get('/admin/reports/print?preset=this_month');

        $response->assertOk();
        $response->assertSee('Reports summary');
        $response->assertSee('Bookings, batches and coaches');
        $response->assertDontSee('Money kept');
        $response->assertDontSee('₱');
    }

    public function test_admin_cannot_print_revenue_by_any_route_or_parameter(): void
    {
        $this->seedPaidBooking();

        foreach ([
            '/admin/reports/print?type=revenue&preset=this_month',
            '/admin/reports-and-analytics/print-summary?type=revenue',
            '/admin/reports/print?type=REVENUE',
            '/admin/reports/print?type[]=revenue',
            '/owner/reports/print?type=revenue',
        ] as $url) {
            // Refused before any report data is loaded
            // (owner URLs are redirected to the admin area with the same query)
            $this->assertContains($this->actingAs($this->admin)->followingRedirects()->get($url)->status(), [403, 422], $url);
        }

        $this->actingAs($this->admin)->get('/admin/reports/print?type=revenue')->assertForbidden();
    }

    public function test_coach_and_guest_cannot_print_any_summary(): void
    {
        foreach (['overall', 'revenue', 'bookings', 'operations'] as $type) {
            $this->actingAs($this->coach)->get("/owner/reports/print?type={$type}")->assertForbidden();
            $this->actingAs($this->coach)->get("/admin/reports/print?type={$type}")->assertForbidden();
        }

        auth()->logout();
        $this->get('/owner/reports/print?type=revenue')->assertRedirect('/login');
        $this->get('/admin/reports/print?type=bookings')->assertRedirect('/login');
    }

    public function test_print_rejects_invalid_type_and_dates(): void
    {
        foreach ([
            'type=everything',
            'type=financials',
            'preset=custom&start_date=not-a-date&end_date=2026-01-31',
            'preset=custom&start_date=2026-01-01&end_date=31/01/2026',
            'preset=custom&start_date=2026-02-01&end_date=2026-01-01',
            'preset[]=custom',
        ] as $query) {
            $this->actingAs($this->owner)->get("/owner/reports/print?{$query}")->assertStatus(422);
        }

        // Unknown presets fall back to this month, as on the dashboard
        $this->actingAs($this->owner)->get('/owner/reports/print?preset=whenever')
            ->assertOk()
            ->assertSee(Carbon::now('Asia/Manila')->format('F Y'));
    }

    public function test_print_uses_the_selected_date_range(): void
    {
        $now = $this->seedPaidBooking();
        $today = $now->toDateString();

        $inRange = $this->actingAs($this->owner)->get("/owner/reports/print?type=revenue&preset=custom&start_date={$today}&end_date={$today}");
        $inRange->assertOk();
        $inRange->assertSee($now->format('M d, Y') . ' - ' . $now->format('M d, Y'));
        $inRange->assertSee('₱2,000.00');

        $past = $now->copy()->subYear();
        $outOfRange = $this->actingAs($this->owner)->get('/owner/reports/print?type=revenue&preset=custom&start_date=' . $past->copy()->startOfMonth()->toDateString() . '&end_date=' . $past->copy()->endOfMonth()->toDateString());
        $outOfRange->assertOk();
        $outOfRange->assertDontSee('₱2,000.00');

        // Back link keeps the same dates and opens the matching tab
        $inRange->assertSee(e('?' . http_build_query(['preset' => 'custom', 'start_date' => $today, 'end_date' => $today, 'tab' => 'financial'])), false);
    }

    public function test_reports_page_offers_print_options_per_role_with_selected_dates(): void
    {
        $query = 'preset=custom&start_date=2026-01-01&end_date=2026-01-31';

        $owner = $this->actingAs($this->owner)->get("/owner/reports?{$query}");
        $owner->assertOk();
        foreach (['overall', 'revenue', 'bookings', 'operations'] as $type) {
            $owner->assertSee(e(route('owner.reports.print') . '?' . http_build_query(['type' => $type, 'preset' => 'custom', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31'])), false);
        }
        $owner->assertSee('Overall summary');

        $admin = $this->actingAs($this->admin)->get("/admin/reports?{$query}");
        $admin->assertOk();
        foreach (['overall', 'bookings', 'operations'] as $type) {
            $admin->assertSee(e(route('admin.reports.print') . '?' . http_build_query(['type' => $type, 'preset' => 'custom', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31'])), false);
        }
        $admin->assertDontSee('type=revenue', false);
    }

    public function test_package_table_is_full_width_so_share_column_is_not_clipped(): void
    {
        $this->seedPaidBooking();

        foreach (['overall', 'revenue'] as $type) {
            $response = $this->actingAs($this->owner)->get("/owner/reports/print?type={$type}&preset=this_month");
            $response->assertOk();
            // The package table comes after the two-column grid closes, not inside a half-width cell
            $response->assertSeeInOrder(['Average per participant', '</div>', '<table class="rpt rpt-packages w-full avoid-break">', '<th class="num">Share</th>'], false);
        }
    }

    public function test_printed_money_figures_come_from_analytics_service(): void
    {
        $this->seedPaidBooking();
        $service = app(\App\Services\Analytics\AnalyticsService::class);
        $fin = $service->getAnalyticsReport($service->resolveDateRange('this_month'), true)['financials'];
        $peso = fn ($v) => '₱' . number_format((float) $v, 2);

        foreach (['overall', 'revenue'] as $type) {
            $response = $this->actingAs($this->owner)->get("/owner/reports/print?type={$type}&preset=this_month");
            $response->assertSeeInOrder(['Money collected', $peso($fin['gross_revenue']), 'Money kept', $peso($fin['net_revenue']), 'Still to collect', $peso($fin['outstanding_receivables'])]);
            $response->assertSeeInOrder(['Discovery', $peso($fin['packages']['discovery']['revenue']), $fin['packages']['discovery']['share_percentage'] . '%']);
        }
        $this->assertSame(2000.0, $fin['gross_revenue']);
        $this->assertEquals(100, $fin['packages']['discovery']['share_percentage']);
    }

    public function test_print_booking_percentages_use_one_decimal_and_coach_table_has_heading(): void
    {
        $this->seedPaidBooking();

        foreach (['overall', 'bookings'] as $type) {
            $response = $this->actingAs($this->owner)->get("/owner/reports/print?type={$type}&preset=this_month");
            $response->assertSeeInOrder(['Paid &amp; going', '1', '100.0%', 'Finished trip', '0', '0.0%', 'Total', '1', '100%'], false);
        }
        $this->actingAs($this->owner)->get('/owner/reports/print?type=bookings&preset=this_month')
            ->assertSeeInOrder(['Paid the downpayment', '100.0%', 'Cancelled', '0.0%']);

        foreach (['overall', 'operations'] as $type) {
            $this->actingAs($this->owner)->get("/owner/reports/print?type={$type}&preset=this_month")
                ->assertSeeInOrder(['Coach workload and assignments', '<th>Coach</th>'], false);
        }
    }

    public function test_admin_can_still_export_bookings_and_batches(): void
    {
        foreach (['bookings', 'batches'] as $type) {
            $response = $this->actingAs($this->admin)->get("/admin/reports/export?type={$type}&preset=this_month");
            $response->assertOk();
            $this->assertStringContainsString("{$type}-", $response->headers->get('content-disposition'));
        }
    }
}
