<?php

namespace Tests\Feature;

use App\Models\AssignmentReleaseRequest;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CoachAvailability;
use App\Models\CoachOpening;
use App\Models\CoachRequest;
use App\Models\ParticipantAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoachPortalModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $coach;
    protected User $admin;
    protected Batch $upcomingBatch;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Coach
        $this->coach = User::factory()->create([
            'name' => 'Coach Jose Rizal',
            'email' => 'coach.jose@campfreedive.ph',
            'role' => 'coach',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        // 2. Create Admin
        $this->admin = User::factory()->create([
            'name' => 'Admin Maria',
            'email' => 'admin.maria@campfreedive.ph',
            'role' => 'admin',
            'status' => 'active',
            'must_change_password' => false,
        ]);

        // 3. Create Future Batch (7 days in future)
        $batchStart = Carbon::now()->addDays(7)->startOfDay();
        $this->upcomingBatch = Batch::create([
            'name' => 'Next Weekend Open Water Batch',
            'batch_code' => 'Batch 1',
            'start_date' => $batchStart,
            'end_date' => $batchStart->copy()->addDay(),
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
            'risk_classification' => 'very_safe',
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * Test 1: Coach can access Coach Portal pages.
     */
    public function test_coach_can_access_coach_portal_pages(): void
    {
        $responseDash = $this->actingAs($this->coach)->get('/coach');
        $responseDash->assertStatus(200);
        $responseDash->assertSee('Welcome back, Coach Jose Rizal');

        $responseAvail = $this->actingAs($this->coach)->get('/coach/availability');
        $responseAvail->assertStatus(200);
        $responseAvail->assertSee('My Availability Calendar');

        $responseSched = $this->actingAs($this->coach)->get('/coach/schedule');
        $responseSched->assertStatus(200);
        $responseSched->assertSee('My Assigned Schedule');

        $responseReq = $this->actingAs($this->coach)->get('/coach/open-requests');
        $responseReq->assertStatus(200);
        $responseReq->assertSee('Open Dive Slot Requests');
    }

    /**
     * Test 2: Coach is blocked from Admin and Owner routes (403 Forbidden).
     */
    public function test_coach_cannot_access_admin_routes(): void
    {
        $responseAdmin = $this->actingAs($this->coach)->get('/admin');
        $responseAdmin->assertStatus(403);

        $responseBookings = $this->actingAs($this->coach)->get('/admin/bookings');
        $responseBookings->assertStatus(403);

        $responsePricing = $this->actingAs($this->coach)->get('/admin/pricing');
        $responsePricing->assertStatus(403);

        $responseUsers = $this->actingAs($this->coach)->get('/admin/settings/users');
        $responseUsers->assertStatus(403);
    }

    /**
     * Test 3: 2D1N Availability Calendar Toggle.
     */
    public function test_coach_can_toggle_2d1n_availability(): void
    {
        $targetDate = Carbon::now()->addDays(10)->startOfDay();

        $response = $this->actingAs($this->coach)->postJson('/coach/availability/toggle', [
            'date' => $targetDate->format('Y-m-d'),
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        // Verify both target date and paired next date are marked available
        $pairDate = $targetDate->isSunday() ? $targetDate->copy()->subDay() : $targetDate->copy()->addDay();

        $this->assertTrue(CoachAvailability::where('coach_id', $this->coach->id)
            ->whereDate('date', $targetDate->format('Y-m-d'))
            ->where('status', 'available')
            ->exists());

        $this->assertTrue(CoachAvailability::where('coach_id', $this->coach->id)
            ->whereDate('date', $pairDate->format('Y-m-d'))
            ->where('status', 'available')
            ->exists());
    }

    /**
     * Test 4: Bulk Edit Availability Mode.
     */
    public function test_coach_can_bulk_update_availability(): void
    {
        $date1 = Carbon::now()->addDays(5)->format('Y-m-d');
        $date2 = Carbon::now()->addDays(12)->format('Y-m-d');

        $response = $this->actingAs($this->coach)->postJson('/coach/availability/bulk', [
            'dates' => [$date1, $date2],
            'status' => 'available',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertTrue(CoachAvailability::where('coach_id', $this->coach->id)
            ->whereDate('date', $date1)
            ->where('status', 'available')
            ->exists());
    }

    /**
     * Test 5: Emergency Release Request 48-Hour Cutoff Enforcement.
     */
    public function test_emergency_release_request_enforces_48h_cutoff(): void
    {
        // 1. Assign coach to a batch that departs in 24 hours (within cutoff)
        $urgentBatch = Batch::create([
            'name' => 'Urgent Tomorrow Batch',
            'batch_code' => 'Batch 99',
            'start_date' => Carbon::now()->addHours(24),
            'end_date' => Carbon::now()->addHours(48),
            'status' => 'confirmed',
            'lifecycle_status' => 'confirmed',
            'created_by' => $this->admin->id,
        ]);

        $responseBlocked = $this->actingAs($this->coach)->post('/coach/availability/release', [
            'batch_id' => $urgentBatch->id,
            'dive_date' => $urgentBatch->start_date->format('Y-m-d'),
            'reason' => 'Sudden emergency unable to dive.',
        ]);

        $responseBlocked->assertSessionHas('error');
        $this->assertEquals(0, AssignmentReleaseRequest::count());

        // 2. Request release for batch 7 days out (>48 hours) -> Allowed
        $responseAllowed = $this->actingAs($this->coach)->post('/coach/availability/release', [
            'batch_id' => $this->upcomingBatch->id,
            'dive_date' => $this->upcomingBatch->start_date->format('Y-m-d'),
            'reason' => 'Mandatory medical appointment scheduled in Manila.',
        ]);

        $responseAllowed->assertSessionHas('success');
        $this->assertDatabaseHas('assignment_release_requests', [
            'coach_id' => $this->coach->id,
            'batch_id' => $this->upcomingBatch->id,
            'status' => 'pending',
        ]);
    }

    /**
     * Test 6: Coach can request an open slot from broadcast board.
     */
    public function test_coach_can_request_open_slot_and_withdraw(): void
    {
        // Create an open broadcast slot
        $opening = CoachOpening::create([
            'batch_id' => $this->upcomingBatch->id,
            'dive_date' => $this->upcomingBatch->start_date,
            'needed_students_count' => 4,
            'status' => 'open',
            'posted_by' => $this->admin->id,
            'notes' => 'Looking for 1 extra coach for weekend Discovery camp.',
        ]);

        // Submit request
        $responseApply = $this->actingAs($this->coach)->post("/coach/open-requests/{$opening->id}/apply", [
            'notes' => 'I am available and have full gear prepared.',
        ]);

        $responseApply->assertSessionHas('success');
        $this->assertDatabaseHas('coach_requests', [
            'opening_id' => $opening->id,
            'coach_id' => $this->coach->id,
            'status' => 'pending',
        ]);

        $coachRequest = CoachRequest::where('coach_id', $this->coach->id)->first();

        // Withdraw request
        $responseWithdraw = $this->actingAs($this->coach)->delete("/coach/open-requests/{$coachRequest->id}/withdraw");
        $responseWithdraw->assertSessionHas('success');
        $this->assertDatabaseMissing('coach_requests', ['id' => $coachRequest->id]);
    }
}
