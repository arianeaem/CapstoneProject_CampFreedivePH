<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ManagementListHelpers;
use Tests\TestCase;

class BookingListExportPrintTest extends TestCase
{
    use ManagementListHelpers;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStaff();
    }

    private function booking(array $attrs = [], int $pax = 1, ?float $paid = 2000): Booking
    {
        $n = ++$this->seq;
        $start = Carbon::parse('2026-11-07')->addWeeks($n % 5);
        $booking = Booking::create(array_merge([
            'booking_number' => sprintf('CFP-LIST-%03d', $n),
            'pin' => '7391',
            'contact_name' => sprintf('Guest %03d', $n),
            'contact_email' => "guest{$n}@example.com",
            'contact_phone' => '0917555' . sprintf('%04d', $n),
            'class_type' => 'discovery',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'status' => 'confirmed',
            'total_amount' => 4250,
            'balance_amount' => 2250,
        ], $attrs));
        foreach (range(1, $pax) as $p) {
            BookingParticipant::create(['booking_id' => $booking->id, 'name' => "Diver {$n}-{$p}", 'age' => 30, 'price_per_person' => 4250, 'health_condition' => 'Asthma, uses inhaler', 'swimmer_status' => 'non_swimmer']);
        }
        if ($paid !== null) {
            Payment::create(['booking_id' => $booking->id, 'transaction_id' => "TXN-SECRET-{$n}", 'payment_type' => 'downpayment', 'payment_method' => 'gcash', 'amount' => $paid, 'status' => 'completed']);
        }

        return $booking;
    }

    public function test_owner_export_has_all_columns_including_money(): void
    {
        $batch = Batch::create(['batch_code' => 'BATCH-77', 'name' => 'Batch 77', 'start_date' => '2026-11-07', 'end_date' => '2026-11-08', 'status' => 'confirmed', 'max_capacity' => 20]);
        $this->booking(['batch_id' => $batch->id, 'pickup_option' => 'carpool', 'boat_dive' => true], 2);

        $response = $this->actingAs($this->owner)->get('/owner/bookings/export');

        $this->assertXlsxDownload($response, 'bookings');
        [$header, $row] = $this->listSheet($response);
        $this->assertSame(['Booking #', 'Booked on', 'Contact name', 'Guest phone', 'Class', 'Dive start', 'Dive end', 'Participants', 'Pickup / transport', 'Boat dive', 'Batch', 'Booking status', 'Payment status', 'Total', 'Paid', 'Balance'], $header);
        $this->assertSame('CFP-LIST-001', $row[0]);
        $this->assertSame('Guest 001', $row[2]);
        $this->assertSame('09175550001', $row[3]);
        $this->assertSame(['2', 'Carpool', 'Yes', 'BATCH-77', 'Confirmed', 'Downpayment Paid', '4250', '2000', '2250'], array_slice($row, 7));
    }

    public function test_admin_export_never_has_money_columns(): void
    {
        $this->booking();

        foreach (['/admin/bookings/export', '/admin/bookings/export?columns=total,paid,balance&include_financials=1'] as $url) {
            $response = $this->actingAs($this->admin)->get($url);
            $this->assertXlsxDownload($response, 'bookings');
            $rows = $this->listSheet($response);
            $this->assertCount(13, $rows[0]);
            $this->assertNotContains('Total', $rows[0]);
            $this->assertNotContains('4250', $rows[1]);
            $this->assertNotContains('2000', $rows[1]);
            $this->assertSame('Downpayment Paid', $rows[1][12]);
        }

        // The owner URL sends an admin to the admin area, still without money
        $redirected = $this->actingAs($this->admin)->followingRedirects()->get('/owner/bookings/export');
        $this->assertCount(13, $this->listSheet($redirected)[0]);
    }

    public function test_export_leaves_out_sensitive_fields(): void
    {
        $this->booking();

        $xml = $this->sheetXml($this->actingAs($this->owner)->get('/owner/bookings/export'), 2);
        foreach (['7391', 'guest1@example.com', 'Asthma', 'inhaler', 'non_swimmer', "Can't swim", 'TXN-SECRET', 'Diver 1-1'] as $secret) {
            $this->assertStringNotContainsString($secret, $xml);
        }
    }

    public function test_export_and_print_match_the_filtered_sorted_list_across_pages(): void
    {
        foreach (range(1, 14) as $i) {
            $this->booking(['class_type' => $i % 2 ? 'fundive' : 'discovery', 'contact_name' => 'Name ' . chr(90 - $i)]);
        }
        $query = 'class_type=fundive&sort=guest_desc';

        // Booking numbers shown on screen, page by page
        $onScreen = collect();
        foreach ([1, 2] as $page) {
            $onScreen = $onScreen->merge($this->actingAs($this->admin)->get("/admin/bookings?{$query}&per_page=5&page={$page}")->viewData('bookings')->pluck('booking_number'));
        }
        $this->assertCount(7, $onScreen);

        $exported = collect($this->listSheet($this->actingAs($this->admin)->get("/admin/bookings/export?{$query}&page=2&per_page=5")))->skip(1)->pluck(0)->values();
        $this->assertSame($onScreen->all(), $exported->all());

        $print = $this->actingAs($this->admin)->get("/admin/bookings/print?{$query}");
        $print->assertOk()->assertSeeInOrder($onScreen->all())->assertSee('7 matching records');
    }

    public function test_default_hides_unpaid_drafts_unless_status_picked(): void
    {
        $this->booking();
        $this->booking(['status' => 'pending_downpayment', 'booking_number' => 'CFP-DRAFT-1'], 1, null);

        $default = collect($this->listSheet($this->actingAs($this->owner)->get('/owner/bookings/export')))->pluck(0);
        $this->assertNotContains('CFP-DRAFT-1', $default);

        $drafts = collect($this->listSheet($this->actingAs($this->owner)->get('/owner/bookings/export?status=pending_downpayment')))->pluck(0);
        $this->assertSame(['Booking #', 'CFP-DRAFT-1'], $drafts->all());

        $this->actingAs($this->owner)->get('/owner/bookings/print')->assertSee('All except unpaid drafts (default)')->assertDontSee('CFP-DRAFT-1');
    }

    public function test_url_only_filters_are_honoured(): void
    {
        $this->booking(['start_date' => '2026-12-05', 'end_date' => '2026-12-06', 'booking_number' => 'CFP-DEC']);
        $this->booking(['start_date' => '2027-01-09', 'end_date' => '2027-01-10', 'booking_number' => 'CFP-JAN']);
        $refunded = $this->booking(['booking_number' => 'CFP-REF']);
        $refunded->payments()->update(['status' => 'refunded']);

        $byDate = collect($this->listSheet($this->actingAs($this->owner)->get('/owner/bookings/export?date_from=2026-12-01&date_to=2026-12-31')))->pluck(0)->all();
        $this->assertSame(['Booking #', 'CFP-DEC'], $byDate);

        $byPayment = collect($this->listSheet($this->actingAs($this->owner)->get('/owner/bookings/export?payment_status=refunded')))->pluck(0)->all();
        $this->assertSame(['Booking #', 'CFP-REF'], $byPayment);
    }

    public function test_invalid_filters_return_422_on_list_export_and_print(): void
    {
        foreach ([
            'date_from=31/12/2026', 'date_to=not-a-date', 'date_from=2026-12-31&date_to=2026-12-01', 'sort=hacked',
            'status=deleted', 'status[]=confirmed', 'class_type=scuba', 'batch_status=maybe', 'payment_status=free',
            'search[]=x', 'search=' . str_repeat('a', 101),
        ] as $query) {
            foreach (['/admin/bookings', '/admin/bookings/export', '/admin/bookings/print'] as $path) {
                $this->actingAs($this->admin)->get("{$path}?{$query}")->assertStatus(422);
            }
        }
    }

    public function test_empty_results_still_render(): void
    {
        $export = $this->actingAs($this->owner)->get('/owner/bookings/export?search=nobody-matches');
        $this->assertXlsxDownload($export, 'bookings');
        $this->assertCount(1, $this->listSheet($export));

        $this->actingAs($this->owner)->get('/owner/bookings/print?search=nobody-matches')
            ->assertOk()->assertSee('No records match these filters.')->assertSee('0 matching records');
    }

    public function test_print_register_is_landscape_without_contact_or_money(): void
    {
        $this->booking();

        foreach (['owner' => $this->owner, 'admin' => $this->admin] as $prefix => $user) {
            $response = $this->actingAs($user)->get("/{$prefix}/bookings/print");
            $response->assertOk();
            $response->assertSee('Booking register');
            $response->assertSee('size: A4 landscape', false);
            $response->assertSee('.rpt thead { display: table-header-group; }', false);
            $response->assertSeeInOrder(['Booking #', 'Contact name', 'Class', 'Dive dates', 'Participants', 'Batch', 'Booking status', 'Payment status']);
            $response->assertSee('CFP-LIST-001');
            $response->assertSee('Prepared by ' . $user->name);
            foreach (['09175550001', 'guest1@example.com', '7391', '₱', '4,250', '4250', 'Asthma', 'TXN-SECRET'] as $secret) {
                $response->assertDontSee($secret, false);
            }
        }
    }

    public function test_contact_searches_are_not_repeated_on_the_printout(): void
    {
        $this->booking();

        $response = $this->actingAs($this->admin)->get('/admin/bookings/print?search=09175550001');
        $response->assertOk()->assertSee('(contact details search)');

        // Only the on-screen "Back to list" link (not printed) carries the search back to the list
        $printed = str_replace(e(route('admin.bookings.index', ['search' => '09175550001'])), '', $response->getContent());
        $this->assertStringNotContainsString('09175550001', $printed);
    }

    public function test_print_refuses_more_than_200_records_but_excel_exports_them_all(): void
    {
        foreach (range(1, 201) as $i) {
            $this->booking([], 1, null);
        }

        $print = $this->actingAs($this->admin)->get('/admin/bookings/print');
        $print->assertStatus(422);
        $print->assertSee('Too many records to print.');
        $print->assertSee('201 records match');
        $print->assertDontSee('CFP-LIST-001');
        $print->assertDontSee('window.print()', false);

        $this->assertCount(202, $this->listSheet($this->actingAs($this->admin)->get('/admin/bookings/export')));
    }

    public function test_unicode_html_and_formulas_are_kept_as_plain_text(): void
    {
        $this->booking(['contact_name' => 'Ñiño <b>Dela Cruz</b> & Co']);
        $this->booking(['contact_name' => '=HYPERLINK("http://evil.test","x")']);

        $export = $this->actingAs($this->owner)->get('/owner/bookings/export?sort=guest_asc');
        $xml = $this->sheetXml($export, 2);
        $this->assertStringNotContainsString('<f>', $xml);
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">=HYPERLINK(&quot;http://evil.test&quot;,&quot;x&quot;)</t>', $xml);
        $this->assertSame(['=HYPERLINK("http://evil.test","x")', 'Ñiño <b>Dela Cruz</b> & Co'], collect($this->listSheet($export))->skip(1)->pluck(2)->values()->all());

        $this->actingAs($this->owner)->get('/owner/bookings/print')
            ->assertSee('Ñiño &lt;b&gt;Dela Cruz&lt;/b&gt; &amp; Co', false)
            ->assertDontSee('<b>Dela Cruz</b>', false);
    }

    public function test_export_is_audit_logged_without_sensitive_values(): void
    {
        $this->booking();

        $this->actingAs($this->admin)->get('/admin/bookings/export?search=0917555&class_type=discovery')->assertOk();

        $log = AuditLog::where('action', 'BOOKINGS_EXPORTED')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertNotNull($log->created_at);
        $this->assertStringContainsString('(1 records)', $log->description);
        $this->assertStringContainsString('class_type=discovery', $log->description);
        $this->assertStringContainsString('search=(used)', $log->description);
        $this->assertStringNotContainsString('0917555', $log->description);

        // Printing is not an export and is not logged
        $this->actingAs($this->admin)->get('/admin/bookings/print');
        $this->assertSame(1, AuditLog::where('action', 'BOOKINGS_EXPORTED')->count());
    }

    public function test_coach_and_guest_cannot_export_or_print(): void
    {
        foreach (['export', 'print'] as $action) {
            $this->actingAs($this->coach)->get("/owner/bookings/{$action}")->assertForbidden();
            $this->actingAs($this->coach)->get("/admin/bookings/{$action}")->assertForbidden();
        }
        auth()->logout();
        $this->get('/admin/bookings/export')->assertRedirect('/login');
        $this->get('/owner/bookings/print')->assertRedirect('/login');
    }

    public function test_static_routes_are_not_treated_as_booking_ids_and_list_buttons_keep_filters(): void
    {
        $this->assertSame('owner.bookings.export', app('router')->getRoutes()->match(request()->create('/owner/bookings/export'))->getName());
        $this->assertSame('admin.bookings.print', app('router')->getRoutes()->match(request()->create('/admin/bookings/print'))->getName());

        $page = $this->actingAs($this->owner)->get('/owner/bookings?status=completed&sort=guest_asc&page=2&per_page=5');
        $page->assertOk();
        $page->assertSee(e(route('owner.bookings.export', ['status' => 'completed', 'sort' => 'guest_asc'])), false);
        $page->assertSee(e(route('owner.bookings.print', ['status' => 'completed', 'sort' => 'guest_asc'])), false);
        $page->assertSee('Print Booking Register');

        $this->actingAs($this->admin)->get('/admin/bookings')->assertSee(e(route('admin.bookings.export')), false);
    }
}
