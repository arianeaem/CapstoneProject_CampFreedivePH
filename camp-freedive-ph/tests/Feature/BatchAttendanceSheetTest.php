<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\ParticipantAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BatchAttendanceSheetTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $admin;
    protected User $coach;
    protected Batch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $make = fn (string $role, string $name) => User::factory()->create(['role' => $role, 'status' => 'active', 'must_change_password' => false, 'name' => $name]);
        $this->owner = $make('owner', 'Olive Owner');
        $this->admin = $make('admin', 'Adam Admin');
        $this->coach = $make('coach', 'Cora Coach');

        $start = Carbon::parse('2026-11-07'); // Saturday
        $this->batch = Batch::create([
            'batch_code' => 'BATCH-ATT-07',
            'name' => 'Attendance Batch',
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDay()->toDateString(),
            'status' => 'confirmed',
            'max_capacity' => 20,
        ]);

        $book = fn (string $number, string $status, array $people) => tap(Booking::create([
            'batch_id' => $this->batch->id,
            'booking_number' => $number,
            'pin' => '4831',
            'contact_name' => 'Contact ' . $number,
            'contact_email' => strtolower($number) . '@example.com',
            'contact_phone' => '09175550101',
            'class_type' => 'discovery',
            'start_date' => $this->batch->start_date->toDateString(),
            'end_date' => $this->batch->end_date->toDateString(),
            'status' => $status,
            'total_amount' => 4250,
            'balance_amount' => 2250,
        ]), function ($booking) use ($people) {
            foreach ($people as $name) {
                BookingParticipant::create(['booking_id' => $booking->id, 'name' => $name, 'age' => 29, 'price_per_person' => 4250, 'health_condition' => 'Asthma, uses inhaler', 'swimmer_status' => 'non_swimmer']);
            }
        });

        $confirmed = $book('CFP-ATT-001', 'confirmed', ['Zara Diver', 'Ben Diver']);
        $book('CFP-ATT-002', 'pending_downpayment', ['Pending Person']);
        $book('CFP-ATT-003', 'cancelled', ['Cancelled Person']);

        ParticipantAssignment::create([
            'participant_id' => $confirmed->participants()->where('name', 'Zara Diver')->value('id'),
            'booking_id' => $confirmed->id,
            'coach_id' => $this->coach->id,
            'batch_id' => $this->batch->id,
            'dive_date' => $this->batch->start_date,
            'assigned_by' => $this->admin->id,
            'assigned_at' => now(),
            'status' => 'assigned',
        ]);
    }

    public function test_owner_and_admin_can_print_attendance_sheet(): void
    {
        foreach (['owner' => $this->owner, 'admin' => $this->admin] as $prefix => $user) {
            $response = $this->actingAs($user)->get("/{$prefix}/batches/{$this->batch->id}/attendance-sheet");

            $response->assertOk();
            $response->assertSee('Attendance sheet');
            $response->assertSee('Batch 07');
            $response->assertSee('BATCH-ATT-07');
            $response->assertSee('Day 1: Saturday, Nov 07, 2026');
            $response->assertSee('Day 2: Sunday, Nov 08, 2026');
            $response->assertSee('Cora Coach');
            $response->assertSee('Instructor sign-off');
            $response->assertSee('Remarks');
            // Two day columns with a box each for both participants
            $this->assertSame(4, substr_count($response->getContent(), 'class="box"'));
            // Assigned participants first, grouped by coach; then unassigned
            $response->assertSeeInOrder(['Zara Diver', 'Cora Coach', 'Ben Diver', 'Not assigned']);
            $response->assertSee('A4 portrait', false);
            $response->assertSee('display: table-header-group', false);
        }
    }

    public function test_sheet_only_lists_active_bookings_and_leaves_out_private_data(): void
    {
        $response = $this->actingAs($this->admin)->get("/admin/batches/{$this->batch->id}/attendance-sheet");

        $response->assertOk();
        $response->assertDontSee('Pending Person');
        $response->assertDontSee('Cancelled Person');
        $response->assertSee('2 participants');

        foreach (['Asthma', 'inhaler', "Can't swim", '4831', '09175550101', '@example.com', 'CFP-ATT-001', 'Contact CFP', '₱', '4,250'] as $private) {
            $response->assertDontSee($private, false);
        }
    }

    public function test_one_day_batch_has_single_day_column(): void
    {
        $this->batch->update(['end_date' => $this->batch->start_date->toDateString()]);

        $response = $this->actingAs($this->owner)->get("/owner/batches/{$this->batch->id}/attendance-sheet");

        $response->assertOk();
        $response->assertSee('Day 1: Saturday, Nov 07, 2026');
        $response->assertDontSee('Day 2');
        $this->assertSame(2, substr_count($response->getContent(), 'class="box"'));
    }

    public function test_large_batch_lists_everyone_with_repeating_header_and_unsplit_rows(): void
    {
        $booking = $this->batch->bookings()->where('status', 'confirmed')->first();
        foreach (range(1, 40) as $n) {
            BookingParticipant::create(['booking_id' => $booking->id, 'name' => "Group Diver {$n}", 'age' => 30, 'price_per_person' => 4250]);
        }

        $response = $this->actingAs($this->admin)->get("/admin/batches/{$this->batch->id}/attendance-sheet");

        $response->assertOk();
        $response->assertSee('42 participants');
        $response->assertSeeInOrder(['Group Diver 1', 'Group Diver 40', 'Instructor sign-off']);
        $this->assertSame(84, substr_count($response->getContent(), 'class="box"'));
        // One header in the markup; print CSS repeats it on every page and keeps rows whole
        $this->assertSame(1, substr_count($response->getContent(), '<th>Participant</th>'));
        $response->assertSee('.rpt thead { display: table-header-group; }', false);
        $response->assertSee('.rpt tr { break-inside: avoid; }', false);
    }

    public function test_coach_and_guest_cannot_print_attendance_sheet(): void
    {
        $this->actingAs($this->coach)->get("/owner/batches/{$this->batch->id}/attendance-sheet")->assertForbidden();
        $this->actingAs($this->coach)->get("/admin/batches/{$this->batch->id}/attendance-sheet")->assertForbidden();

        auth()->logout();
        $this->get("/admin/batches/{$this->batch->id}/attendance-sheet")->assertRedirect('/login');
    }

    public function test_missing_batch_returns_404(): void
    {
        $this->actingAs($this->admin)->get('/admin/batches/999999/attendance-sheet')->assertNotFound();
    }

    public function test_batch_details_page_links_to_attendance_sheet(): void
    {
        $this->actingAs($this->owner)->get("/owner/batches/{$this->batch->id}")
            ->assertOk()
            ->assertSee(route('owner.batches.attendance', $this->batch), false)
            ->assertSee('Print attendance sheet');

        $this->actingAs($this->admin)->get("/admin/batches/{$this->batch->id}")
            ->assertOk()
            ->assertSee(route('admin.batches.attendance', $this->batch), false);
    }
}
