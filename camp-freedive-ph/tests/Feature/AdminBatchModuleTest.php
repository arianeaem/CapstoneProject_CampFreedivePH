<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBatchModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_admin_can_access_batch_list(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $response = $this->get('/admin/batches');
        $response->assertStatus(200);
        $response->assertSee('Batches', false);
        $response->assertSee('Batch 1');
        $response->assertSee('Batch 2');
    }

    public function test_batches_can_be_searched_and_filtered_by_coach(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $coach = User::factory()->create(['role' => 'coach', 'status' => 'active', 'name' => 'Zelda Coachman']);

        $withCoach = Batch::create([
            'name' => 'Coached Batch', 'batch_code' => 'ZC-COACHED-01',
            'start_date' => Carbon::today()->addDays(40)->toDateString(), 'end_date' => Carbon::today()->addDays(41)->toDateString(),
            'status' => 'open',
        ]);
        $withoutCoach = Batch::create([
            'name' => 'Uncoached Batch', 'batch_code' => 'ZC-UNCOACHED-02',
            'start_date' => Carbon::today()->addDays(47)->toDateString(), 'end_date' => Carbon::today()->addDays(48)->toDateString(),
            'status' => 'open',
        ]);
        \App\Models\CoachAvailability::create([
            'coach_id' => $coach->id, 'date' => $withCoach->start_date->toDateString(), 'status' => 'assigned', 'notes' => 'Team for ZC-COACHED-01',
        ]);

        $ids = fn ($response) => collect($response->viewData('batches')->items())->pluck('id')->all();

        // Search by (part of) the coach's name, any letter case
        $bySearch = $this->actingAs($admin)->get('/admin/batches?per_page=100&search=zelda');
        $this->assertContains($withCoach->id, $ids($bySearch));
        $this->assertNotContains($withoutCoach->id, $ids($bySearch));

        // Coach filter dropdown
        $byCoach = $this->actingAs($admin)->get('/admin/batches?per_page=100&coach=' . $coach->id);
        $this->assertSame([$withCoach->id], $ids($byCoach));
        $byCoach->assertSee('id="filter-coach"', false);
        $byCoach->assertSee('Zelda Coachman');

        // Normal batch search still works
        $byCode = $this->actingAs($admin)->get('/admin/batches?per_page=100&search=zc-uncoached');
        $this->assertSame([$withoutCoach->id], $ids($byCode));
    }

    public function test_admin_can_access_create_batch_page(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $response = $this->get('/admin/batches/create');
        $response->assertStatus(200);
    }

    public function test_admin_can_view_batch_detail_with_bookings(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::whereHas('bookings')->first();
        $this->assertNotEmpty($batch->bookings);

        $booking = $batch->bookings->first();

        $response = $this->get("/admin/batches/{$batch->id}");
        $response->assertStatus(200);
        $response->assertSee($batch->batch_code);
        $response->assertSee($booking->booking_number);
    }

    public function test_coach_role_is_forbidden_from_admin_batches(): void
    {
        $coach = User::where('email', 'coach.jose@campfreedive.ph')->first();
        $this->actingAs($coach);

        $response = $this->get('/admin/batches');
        $response->assertStatus(403);
    }

    public function test_batch_occupancy_shows_instructor_pending_when_zero_coaches_assigned(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch6 = Batch::where('batch_code', 'Batch 6')->first();

        $response = $this->get('/admin/batches');
        $response->assertStatus(200);
        $response->assertSee($batch6->batch_code);
    }

    public function test_admin_can_create_batch_and_auto_link_bookings(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $booking = Booking::where('booking_number', 'CFP-2026-1002')->first() ?: Booking::first();
        $startDate = Carbon::now()->addDays(120)->format('Y-m-d');
        $endDate = Carbon::now()->addDays(121)->format('Y-m-d');

        $response = $this->post('/admin/batches', [
            'batch_number' => 'Batch 99',
            'start_date' => $startDate,
            'end_date' => $endDate,
            'risk_classification' => 'safe',
            'booking_ids' => [$booking->id],
            'notes' => 'Created via admin form',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('batches', [
            'name' => 'Batch 99',
            'batch_code' => 'Batch 99',
            'status' => 'confirmed',
        ]);

        $this->assertEquals('Batch 99', $booking->fresh()->batch->name);
    }

    public function test_unbatched_bookings_api_returns_correct_json(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $response = $this->getJson('/admin/batches/unbatched-bookings?date=' . Carbon::now()->addDays(3)->format('Y-m-d'));
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'date',
            'suggested_name',
            'suggested_code',
            'count',
            'bookings',
        ]);
    }

    public function test_batch_cancellation_by_camp_cascades_to_bookings_and_triggers_refunds(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::whereHas('bookings')->first();
        $booking = $batch->bookings->first();

        $response = $this->post("/admin/batches/{$batch->id}/status", [
            'status' => 'cancelled_by_camp',
            'note' => 'Severe weather advisory and high marine surge.',
        ]);

        $response->assertRedirect();
        $this->assertEquals('cancelled_by_camp', $batch->fresh()->status);
        $this->assertEquals('cancelled_by_camp', $booking->fresh()->status);

        // Refund request should be created
        $this->assertDatabaseHas('refund_requests', [
            'booking_id' => $booking->id,
            'requested_by' => 'camp_force_majeure',
            'status' => 'pending',
        ]);

        // Audit log created
        $this->assertDatabaseHas('batch_status_logs', [
            'batch_id' => $batch->id,
            'new_status' => 'cancelled_by_camp',
        ]);
    }

    public function test_batch_reschedule_cascades_to_bookings(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::whereHas('bookings')->first();
        $booking = $batch->bookings->first();

        $response = $this->post("/admin/batches/{$batch->id}/status", [
            'status' => 'rescheduled',
            'note' => 'Rescheduled due to resort maintenance.',
        ]);

        $response->assertRedirect();
        $this->assertEquals('rescheduled', $batch->fresh()->status);
        $this->assertEquals('rescheduled', $booking->fresh()->status);
    }

    public function test_batch_completion_cascades_to_bookings(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch = Batch::where('status', 'confirmed')->whereHas('bookings')->first();
        $booking = $batch->bookings->first();

        $response = $this->post("/admin/batches/{$batch->id}/status", [
            'status' => 'completed',
            'note' => 'Weekend session concluded successfully.',
        ]);

        $response->assertRedirect();
        $this->assertEquals('completed', $batch->fresh()->status);
        $this->assertEquals('completed', $booking->fresh()->status);
    }

    public function test_admin_can_move_booking_to_another_batch(): void
    {
        $admin = User::where('email', 'admin@campfreedive.ph')->first();
        $this->actingAs($admin);

        $batch1 = Batch::where('batch_code', 'Batch 1')->first();
        $batch2 = Batch::where('batch_code', 'Batch 2')->first();
        $booking = $batch1->bookings->first() ?: Booking::create([
            'batch_id' => $batch1->id,
            'user_id' => $admin->id,
            'booking_number' => 'BK-' . uniqid(),
            'pin' => '1234',
            'class_type' => 'intro',
            'status' => 'confirmed',
            'total_amount' => 5000,
            'contact_name' => 'Move Lead',
            'contact_email' => 'lead@example.com',
            'contact_phone' => '09123456789',
            'start_date' => $batch1->start_date,
            'end_date' => $batch1->end_date,
        ]);

        $response = $this->post("/admin/batches/{$batch1->id}/move-booking", [
            'booking_id' => $booking->id,
            'target_batch_id' => $batch2->id,
            'reason' => 'Customer requested grouping with friends in Batch 2.',
        ]);

        $response->assertRedirect();
        $this->assertEquals($batch2->id, $booking->fresh()->batch_id);
    }
}
