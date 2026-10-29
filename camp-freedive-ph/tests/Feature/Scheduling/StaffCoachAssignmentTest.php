<?php

namespace Tests\Feature\Scheduling;

use App\Models\Batch;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachDateCommitment;
use App\Models\User;
use App\Services\Scheduling\CoachSchedulingException;
use App\Services\Scheduling\CoachSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2/S5: staff confirmation workflows, authorization, self-assignment rejection,
 * date conflicts, assignment/removal, and route-level security.
 */
class StaffCoachAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private CoachSchedulingService $svc;
    private User $admin;
    private User $owner;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CoachSchedulingService::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);
        $this->owner = User::factory()->create(['role' => 'owner', 'status' => 'active', 'must_change_password' => false]);
    }

    private function coach(string $status = 'active'): Coach
    {
        $user = User::factory()->create(['role' => 'coach', 'status' => 'active', 'must_change_password' => false]);
        return Coach::create([
            'user_id' => $user->id, 'full_name' => 'S2 Coach ' . (++$this->seq),
            'email' => "s2coach{$this->seq}@example.test", 'phone' => '09170000001',
            'certification_level' => 'AIDA 4 Master', 'certification_number' => "S2-{$this->seq}",
            'certification_expiry' => now()->addYear(), 'status' => $status,
        ]);
    }

    private function batch(string $start = '2026-12-05', string $end = '2026-12-06'): Batch
    {
        return Batch::create([
            'name' => 'S2 Batch ' . (++$this->seq), 'batch_code' => 'S2-' . $this->seq,
            'start_date' => $start, 'end_date' => $end,
            'status' => 'confirmed', 'lifecycle_status' => 'confirmed',
        ]);
    }

    private function declareAvailability(Coach $coach, string $date): CoachAvailability
    {
        return CoachAvailability::create([
            'coach_id' => $coach->user_id, 'date' => $date, 'status' => 'available',
        ]);
    }

    // ---------------------------------------------------------------- Confirmation

    public function test_staff_can_confirm_declared_availability(): void
    {
        $coach = $this->coach();
        $this->declareAvailability($coach, '2026-12-05');

        $result = $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);

        $this->assertTrue($result->isStaffConfirmed());
        $this->assertSame($this->admin->id, $result->staff_confirmed_by);
    }

    public function test_owner_can_confirm_availability(): void
    {
        $coach = $this->coach();
        $this->declareAvailability($coach, '2026-12-05');

        $result = $this->svc->confirmAvailability($coach, '2026-12-05', $this->owner);
        $this->assertTrue($result->isStaffConfirmed());
    }

    public function test_coach_cannot_confirm_own_availability(): void
    {
        $coach = $this->coach();
        $this->declareAvailability($coach, '2026-12-05');

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('cannot confirm their own');

        $coachUser = User::find($coach->user_id);
        $coachUser->role = 'admin';
        $coachUser->save();
        $this->svc->confirmAvailability($coach, '2026-12-05', $coachUser);
    }

    public function test_confirming_without_declaration_fails(): void
    {
        $coach = $this->coach();

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('no available declaration');
        $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);
    }

    public function test_confirming_already_confirmed_is_idempotent(): void
    {
        $coach = $this->coach();
        $this->declareAvailability($coach, '2026-12-05');

        $first = $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);
        $second = $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);

        $this->assertSame($first->id, $second->id);
    }

    public function test_non_staff_cannot_confirm(): void
    {
        $coach = $this->coach();
        $this->declareAvailability($coach, '2026-12-05');

        $guest = User::factory()->create(['role' => 'coach', 'status' => 'active', 'must_change_password' => false]);

        $this->expectException(CoachSchedulingException::class);
        $this->svc->confirmAvailability($coach, '2026-12-05', $guest);
    }

    // ---------------------------------------------------------------- Revocation

    public function test_staff_can_revoke_confirmed_availability(): void
    {
        $coach = $this->coach();
        $this->declareAvailability($coach, '2026-12-05');
        $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);

        $result = $this->svc->revokeConfirmation($coach, '2026-12-05', $this->owner);

        $this->assertFalse($result->isStaffConfirmed());
    }

    public function test_revocation_blocked_by_active_commitment(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();
        $this->declareAvailability($coach, '2026-12-05');
        $this->declareAvailability($coach, '2026-12-06');
        $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);
        $this->svc->confirmAvailability($coach, '2026-12-06', $this->admin);
        $this->svc->commitCoach($coach, $batch, ['2026-12-05', '2026-12-06'], $this->admin);

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('active commitment');
        $this->svc->revokeConfirmation($coach, '2026-12-05', $this->admin);
    }

    public function test_revoking_unconfirmed_fails(): void
    {
        $coach = $this->coach();
        $this->declareAvailability($coach, '2026-12-05');

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('no confirmed availability');
        $this->svc->revokeConfirmation($coach, '2026-12-05', $this->admin);
    }

    // ---------------------------------------------------------------- Assignment and removal

    public function test_assign_and_remove_coach_from_batch(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();
        $this->declareAvailability($coach, '2026-12-05');
        $this->declareAvailability($coach, '2026-12-06');
        $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);
        $this->svc->confirmAvailability($coach, '2026-12-06', $this->admin);

        $commitments = $this->svc->commitCoach($coach, $batch, ['2026-12-05', '2026-12-06'], $this->admin);
        $this->assertCount(2, $commitments);

        $removed = $this->svc->releaseCoach($coach, $batch);
        $this->assertSame(2, $removed);
        $this->assertSame(0, CoachDateCommitment::where('coach_id', $coach->id)->count());
    }

    public function test_coach_with_conflict_cannot_be_assigned(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch();
        $batch2 = $this->batch('2026-12-05', '2026-12-06');
        $this->declareAvailability($coach, '2026-12-05');
        $this->declareAvailability($coach, '2026-12-06');
        $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);
        $this->svc->confirmAvailability($coach, '2026-12-06', $this->admin);

        $this->svc->commitCoach($coach, $batch1, ['2026-12-05', '2026-12-06'], $this->admin);

        $this->expectException(CoachSchedulingException::class);
        $this->svc->commitCoach($coach, $batch2, ['2026-12-05'], $this->admin);
    }

    public function test_inactive_coach_cannot_be_assigned(): void
    {
        $coach = $this->coach('inactive');
        $batch = $this->batch();

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('not active');
        $this->svc->commitCoach($coach, $batch, ['2026-12-05'], $this->admin);
    }

    // ---------------------------------------------------------------- Coach roster for batch

    public function test_coaches_for_batch_shows_availability_and_commitment_status(): void
    {
        $coach1 = $this->coach();
        $coach2 = $this->coach();
        $batch = $this->batch();

        $this->declareAvailability($coach1, '2026-12-05');
        $this->declareAvailability($coach1, '2026-12-06');
        $this->svc->confirmAvailability($coach1, '2026-12-05', $this->admin);
        $this->svc->confirmAvailability($coach1, '2026-12-06', $this->admin);
        $this->svc->commitCoach($coach1, $batch, ['2026-12-05', '2026-12-06'], $this->admin);

        $roster = $this->svc->coachesForBatch($batch);

        $c1Entry = $roster->firstWhere(fn ($e) => $e['coach']->id === $coach1->id);
        $c2Entry = $roster->firstWhere(fn ($e) => $e['coach']->id === $coach2->id);

        $this->assertTrue($c1Entry['dates']['2026-12-05']['committed_to_this_batch']);
        $this->assertFalse($c2Entry['dates']['2026-12-05']['available']);
    }

    public function test_conflict_batch_shown_in_roster(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch();
        $batch2 = $this->batch('2026-12-05', '2026-12-06');

        $this->declareAvailability($coach, '2026-12-05');
        $this->declareAvailability($coach, '2026-12-06');
        $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);
        $this->svc->confirmAvailability($coach, '2026-12-06', $this->admin);
        $this->svc->commitCoach($coach, $batch1, ['2026-12-05', '2026-12-06'], $this->admin);

        $roster = $this->svc->coachesForBatch($batch2);
        $entry = $roster->firstWhere(fn ($e) => $e['coach']->id === $coach->id);

        $this->assertSame($batch1->name, $entry['dates']['2026-12-05']['conflict_batch']);
    }

    // ---------------------------------------------------------------- Route-level authorization

    public function test_unauthenticated_cannot_access_scheduling(): void
    {
        $batch = $this->batch();
        $this->get("/admin/coaches/scheduling/{$batch->id}")->assertRedirect();
    }

    public function test_coach_role_cannot_access_scheduling(): void
    {
        $coachUser = User::factory()->create(['role' => 'coach', 'status' => 'active', 'must_change_password' => false]);
        $batch = $this->batch();

        $this->actingAs($coachUser)->get("/admin/coaches/scheduling/{$batch->id}")->assertStatus(403);
    }

    public function test_admin_can_access_scheduling_page(): void
    {
        $batch = $this->batch();

        $this->actingAs($this->admin)->get("/admin/coaches/scheduling/{$batch->id}")->assertOk();
    }

    public function test_owner_can_access_scheduling_page(): void
    {
        $batch = $this->batch();

        $this->actingAs($this->owner)->get("/owner/coaches/scheduling/{$batch->id}")->assertOk();
    }

    // ---------------------------------------------------------------- Two-day coverage

    public function test_two_day_batch_requires_both_days_confirmed(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();

        $this->declareAvailability($coach, '2026-12-05');
        $this->svc->confirmAvailability($coach, '2026-12-05', $this->admin);
        // Day 2 not declared/confirmed

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('staff-confirmed availability');
        $this->svc->commitCoach($coach, $batch, ['2026-12-05', '2026-12-06'], $this->admin);
    }

    // ---------------------------------------------------------------- Understaffed identification

    public function test_batch_staffing_state_shows_understaffed_dates(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();
        $booking = \App\Models\Booking::create([
            'batch_id' => $batch->id, 'booking_number' => 'BK-S2-1', 'pin' => '1234',
            'class_type' => 'discovery', 'status' => 'confirmed', 'total_amount' => 4250,
            'downpayment_amount' => 2125, 'balance_amount' => 2125,
            'contact_name' => 'Guest', 'contact_email' => 'guest@example.test', 'contact_phone' => '0917',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);
        \App\Models\BookingParticipant::create([
            'booking_id' => $booking->id, 'name' => 'Participant 1', 'age' => 25, 'price_per_person' => 4250,
        ]);

        $staffing = $this->svc->batchStaffingState($batch);

        $this->assertSame(1, $staffing['required_coaches']);
        $this->assertFalse($staffing['fully_covered']);
        foreach ($staffing['dates'] as $date => $info) {
            $this->assertSame(0, $info['committed_count']);
            $this->assertFalse($info['covered']);
        }
    }
}
