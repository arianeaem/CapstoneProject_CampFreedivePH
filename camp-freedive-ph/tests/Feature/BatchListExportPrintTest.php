<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CoachAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ManagementListHelpers;
use Tests\TestCase;

class BatchListExportPrintTest extends TestCase
{
    use ManagementListHelpers;
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeStaff();
        $this->coach->update(['email' => 'cora.coach@example.com', 'phone' => '09998887777']);
    }

    /** A batch with $pax participants in one confirmed booking, optionally staffed by the coach. */
    private function batch(int $pax = 0, bool $staffed = false, array $attrs = []): Batch
    {
        $n = ++$this->seq;
        $start = Carbon::parse('2026-11-07')->addWeeks($n);
        $batch = Batch::create(array_merge([
            'batch_code' => sprintf('BATCH-%02d', $n), 'name' => "Batch {$n}",
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
            'status' => 'confirmed', 'max_capacity' => 20, 'risk_classification' => 'safe',
        ], $attrs));
        if ($pax > 0) {
            $booking = Booking::create([
                'batch_id' => $batch->id, 'booking_number' => "CFP-B{$n}", 'pin' => '7391', 'contact_name' => "Guest {$n}",
                'contact_email' => "guest{$n}@example.com", 'contact_phone' => '09175550101', 'class_type' => 'discovery',
                'start_date' => $batch->start_date->toDateString(), 'end_date' => $batch->end_date->toDateString(),
                'status' => 'confirmed', 'total_amount' => 4250, 'balance_amount' => 2250,
            ]);
            foreach (range(1, $pax) as $p) {
                BookingParticipant::create(['booking_id' => $booking->id, 'name' => "Diver {$n}-{$p}", 'age' => 30, 'price_per_person' => 4250, 'health_condition' => 'Asthma']);
            }
        }
        if ($staffed) {
            CoachAvailability::create(['coach_id' => $this->coach->id, 'date' => $batch->start_date->toDateString(), 'status' => 'assigned', 'notes' => "Team for {$batch->batch_code}"]);
        }

        return $batch;
    }

    public function test_export_has_batch_columns_and_staffing_figures(): void
    {
        $this->batch(6, true, ['risk_classification' => 'moderate']);

        $response = $this->actingAs($this->admin)->get('/admin/batches/export');

        $this->assertXlsxDownload($response, 'batches');
        [$header, $row] = $this->listSheet($response);
        $this->assertSame(['Batch', 'Code', 'Starts', 'Ends', 'Status', 'Participants', 'Slots', 'Occupancy %', 'Assigned coaches', 'Coach count', 'Coaches needed', 'Enough coaches?', 'Needs attention', 'Sea safety'], $header);
        $this->assertSame(['Batch 01', 'BATCH-01'], array_slice($row, 0, 2));
        // 6 participants, 1 coach, default rule 1 coach : 4 participants
        $this->assertSame(['Confirmed', '6', '20', '30', 'Cora Coach', '1', '2', 'Needs 1 more', 'No', 'Moderate'], array_slice($row, 4));
    }

    public function test_batches_export_has_no_contact_details(): void
    {
        $this->batch(2, true);

        $xml = $this->sheetXml($this->actingAs($this->owner)->get('/owner/batches/export'), 2);
        foreach (['cora.coach@example.com', '09998887777', 'guest1@example.com', '09175550101', '7391', 'Asthma'] as $secret) {
            $this->assertStringNotContainsString($secret, $xml);
        }
    }

    public function test_export_and_print_match_filtered_sorted_list_across_pages(): void
    {
        foreach (range(1, 9) as $i) {
            $this->batch($i, $i % 3 !== 0);
        }
        $query = 'staffing=staffed&sort=capacity_desc';

        $onScreen = collect();
        foreach ([1, 2] as $page) {
            $onScreen = $onScreen->merge(collect($this->actingAs($this->owner)->get("/owner/batches?{$query}&per_page=4&page={$page}")->viewData('batches')->items())->pluck('batch_number'));
        }
        $this->assertSame(['Batch 08', 'Batch 07', 'Batch 05', 'Batch 04', 'Batch 02', 'Batch 01'], $onScreen->all());

        $exported = collect($this->listSheet($this->actingAs($this->owner)->get("/owner/batches/export?{$query}&page=2")))->skip(1)->pluck(0)->values();
        $this->assertSame($onScreen->all(), $exported->all());

        $this->actingAs($this->owner)->get("/owner/batches/print?{$query}")
            ->assertOk()->assertSeeInOrder($onScreen->all())->assertSee('6 matching records')->assertSee('Has a coach');
    }

    public function test_search_coach_status_and_date_filters(): void
    {
        $this->batch(2, true);
        $this->batch(2, false, ['status' => 'completed']);
        $this->batch(2, false, ['start_date' => '2027-03-06', 'end_date' => '2027-03-07']);

        $ids = fn (string $q) => collect($this->listSheet($this->actingAs($this->admin)->get("/admin/batches/export?{$q}")))->skip(1)->pluck(0)->values()->all();
        $this->assertSame(['Batch 01'], $ids('search=cora'));
        $this->assertSame(['Batch 01'], $ids('coach=' . $this->coach->id));
        $this->assertSame(['Batch 02'], $ids('status=completed'));
        $this->assertSame(['Batch 03'], $ids('date_from=2027-03-01&date_to=2027-03-31'));
    }

    public function test_invalid_filters_return_422(): void
    {
        foreach (['date_from=2026-13-01', 'date_from=2027-01-01&date_to=2026-01-01', 'sort=random', 'status=deleted', 'status[]=open', 'coach=abc', 'coach[]=1', 'staffing=maybe'] as $query) {
            foreach (['/admin/batches', '/admin/batches/export', '/admin/batches/print'] as $path) {
                $this->actingAs($this->admin)->get("{$path}?{$query}")->assertStatus(422);
            }
        }
    }

    public function test_print_schedule_is_landscape_without_contact_details(): void
    {
        $this->batch(3, true);

        foreach (['owner' => $this->owner, 'admin' => $this->admin] as $prefix => $user) {
            $response = $this->actingAs($user)->get("/{$prefix}/batches/print");
            $response->assertOk();
            $response->assertSee('Batch schedule');
            $response->assertSee('size: A4 landscape', false);
            $response->assertSeeInOrder(['Batch', 'Dates', 'Status', 'Participants / capacity', 'Assigned instructors', 'Enough coaches?', 'Sea safety']);
            $response->assertSeeInOrder(['Batch 01', '3 / 20', 'Cora Coach', 'Yes', 'Safe']);
            foreach (['cora.coach@example.com', '09998887777', 'guest1@example.com', '09175550101', '7391', '₱', 'Asthma'] as $secret) {
                $response->assertDontSee($secret, false);
            }
        }
    }

    public function test_empty_list_and_print_limit(): void
    {
        $this->actingAs($this->admin)->get('/admin/batches/print')->assertOk()->assertSee('No records match these filters.');
        $this->assertCount(1, $this->listSheet($this->actingAs($this->admin)->get('/admin/batches/export')));

        foreach (range(1, 201) as $i) {
            $this->batch();
        }
        $this->actingAs($this->admin)->get('/admin/batches/print')->assertStatus(422)->assertSee('Too many records to print.')->assertSee('201 records match');
        $this->assertCount(202, $this->listSheet($this->actingAs($this->admin)->get('/admin/batches/export')));
    }

    public function test_export_is_audit_logged(): void
    {
        $this->batch(1);
        $this->actingAs($this->owner)->get('/owner/batches/export?status=confirmed&staffing=pending')->assertOk();

        $log = AuditLog::where('action', 'BATCHES_EXPORTED')->sole();
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertStringContainsString('(1 records)', $log->description);
        $this->assertStringContainsString('status=confirmed, staffing=pending', $log->description);
    }

    public function test_access_routes_and_list_buttons(): void
    {
        foreach (['export', 'print'] as $action) {
            $this->actingAs($this->coach)->get("/owner/batches/{$action}")->assertForbidden();
            $this->actingAs($this->coach)->get("/admin/batches/{$action}")->assertForbidden();
        }
        $this->assertSame('admin.batches.export', app('router')->getRoutes()->match(request()->create('/admin/batches/export'))->getName());

        $this->actingAs($this->admin)->get('/admin/batches?staffing=pending&page=3')
            ->assertSee(e(route('admin.batches.print', ['staffing' => 'pending'])), false)
            ->assertSee('Print Batch Schedule');

        auth()->logout();
        $this->get('/admin/batches/export')->assertRedirect('/login');
    }
}
