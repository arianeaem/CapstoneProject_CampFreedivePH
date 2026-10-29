<?php

namespace Tests\Feature\Scheduling;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\CoachDateCommitment;
use App\Models\User;
use App\Services\Scheduling\CoachSchedulingException;
use App\Services\Scheduling\CoachSchedulingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * S1: authoritative coach scheduling foundation — schema, domain validation, conflict
 * prevention, ratio calculation, two-day coverage, legacy handling.
 */
class CoachSchedulingFoundationTest extends TestCase
{
    use RefreshDatabase;

    private CoachSchedulingService $svc;
    private User $admin;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CoachSchedulingService::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);
    }

    private function coach(string $status = 'active'): Coach
    {
        $user = User::factory()->create(['role' => 'coach', 'status' => 'active', 'must_change_password' => false]);

        return Coach::create([
            'user_id' => $user->id, 'full_name' => 'Coach ' . (++$this->seq),
            'email' => "coach{$this->seq}@example.test", 'phone' => '09170000001',
            'certification_level' => 'AIDA 4 Master', 'certification_number' => "AIDA-{$this->seq}",
            'certification_expiry' => now()->addYear(), 'status' => $status,
        ]);
    }

    private function batch(string $startDate = '2026-12-05', string $endDate = '2026-12-06'): Batch
    {
        return Batch::create([
            'name' => 'S1 Batch ' . (++$this->seq), 'batch_code' => 'S1-' . $this->seq,
            'start_date' => $startDate, 'end_date' => $endDate,
            'status' => 'confirmed', 'lifecycle_status' => 'confirmed',
        ]);
    }

    private function confirmAvailability(Coach $coach, string $date): CoachAvailability
    {
        return CoachAvailability::create([
            'coach_id' => $coach->user_id, 'date' => $date, 'status' => 'available',
            'staff_confirmed_by' => $this->admin->id, 'staff_confirmed_at' => now(),
        ]);
    }

    private function selfDeclaredAvailability(Coach $coach, string $date): CoachAvailability
    {
        return CoachAvailability::create([
            'coach_id' => $coach->user_id, 'date' => $date, 'status' => 'available',
        ]);
    }

    private function booking(Batch $batch, int $pax = 1, string $status = 'confirmed'): Booking
    {
        $booking = Booking::create([
            'batch_id' => $batch->id, 'booking_number' => 'BK-S1-' . (++$this->seq), 'pin' => '1234',
            'class_type' => 'discovery', 'status' => $status, 'total_amount' => 4250 * $pax,
            'downpayment_amount' => 2125 * $pax, 'balance_amount' => 2125 * $pax,
            'contact_name' => 'Guest ' . $this->seq, 'contact_email' => "guest{$this->seq}@example.test",
            'contact_phone' => '09170000002',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);
        for ($i = 0; $i < $pax; $i++) {
            BookingParticipant::create([
                'booking_id' => $booking->id, 'name' => "Participant {$this->seq}-{$i}",
                'age' => 25, 'price_per_person' => 4250,
            ]);
        }

        return $booking;
    }

    // ---------------------------------------------------------------- Migration safety

    public function test_migration_adds_staff_confirmation_columns_to_availabilities(): void
    {
        $this->assertTrue(
            \Schema::hasColumns('coach_availabilities', ['staff_confirmed_by', 'staff_confirmed_at']),
            'coach_availabilities has staff confirmation columns'
        );
    }

    public function test_migration_creates_coach_date_commitments_table(): void
    {
        $this->assertTrue(\Schema::hasTable('coach_date_commitments'));
        $this->assertTrue(\Schema::hasColumns('coach_date_commitments', [
            'coach_id', 'batch_id', 'date', 'assigned_by', 'assigned_at', 'notes',
        ]));
    }

    public function test_existing_availability_records_are_not_staff_confirmed(): void
    {
        $coach = $this->coach();
        $avail = $this->selfDeclaredAvailability($coach, '2026-12-05');

        $this->assertNull($avail->staff_confirmed_by);
        $this->assertNull($avail->staff_confirmed_at);
        $this->assertFalse($avail->isStaffConfirmed());
    }

    // ---------------------------------------------------------------- Staff confirmation

    public function test_staff_confirmed_availability_is_distinguishable(): void
    {
        $coach = $this->coach();
        $confirmed = $this->confirmAvailability($coach, '2026-12-05');
        $selfDeclared = $this->selfDeclaredAvailability($coach, '2026-12-06');

        $this->assertTrue($confirmed->isStaffConfirmed());
        $this->assertFalse($selfDeclared->isStaffConfirmed());
        $this->assertSame($this->admin->id, $confirmed->staff_confirmed_by);
    }

    // ---------------------------------------------------------------- Ratio calculation

    public function test_ratio_calculation_for_boundary_participant_counts(): void
    {
        $cases = [0 => 0, 1 => 1, 4 => 1, 5 => 2, 8 => 2, 9 => 3];
        foreach ($cases as $pax => $expected) {
            $this->assertSame($expected, $this->svc->requiredCoachCount($pax), "pax={$pax}");
        }
    }

    // ---------------------------------------------------------------- Commitment creation

    public function test_commit_coach_creates_per_date_records(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();
        $this->confirmAvailability($coach, '2026-12-05');
        $this->confirmAvailability($coach, '2026-12-06');

        $commitments = $this->svc->commitCoach($coach, $batch, ['2026-12-05', '2026-12-06'], $this->admin);

        $this->assertCount(2, $commitments);
        $this->assertSame(2, CoachDateCommitment::where('batch_id', $batch->id)->count());
        foreach ($commitments as $c) {
            $this->assertSame($coach->id, $c->coach_id);
            $this->assertSame($batch->id, $c->batch_id);
            $this->assertSame($this->admin->id, $c->assigned_by);
        }
    }

    // ---------------------------------------------------------------- Conflict prevention

    public function test_same_coach_same_date_conflict_rejected(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch();
        $batch2 = $this->batch('2026-12-05', '2026-12-06');
        $this->confirmAvailability($coach, '2026-12-05');
        $this->confirmAvailability($coach, '2026-12-06');

        $this->svc->commitCoach($coach, $batch1, ['2026-12-05'], $this->admin);

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('already has a commitment');
        $this->svc->commitCoach($coach, $batch2, ['2026-12-05'], $this->admin);
    }

    public function test_different_coaches_same_date_allowed(): void
    {
        $coach1 = $this->coach();
        $coach2 = $this->coach();
        $batch = $this->batch();
        $this->confirmAvailability($coach1, '2026-12-05');
        $this->confirmAvailability($coach2, '2026-12-05');

        $this->svc->commitCoach($coach1, $batch, ['2026-12-05'], $this->admin);
        $this->svc->commitCoach($coach2, $batch, ['2026-12-05'], $this->admin);

        $this->assertSame(2, CoachDateCommitment::where('batch_id', $batch->id)->whereDate('date', '2026-12-05')->count());
    }

    public function test_one_coach_non_overlapping_dates_allowed(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch('2026-12-05', '2026-12-06');
        $batch2 = $this->batch('2026-12-12', '2026-12-13');
        $this->confirmAvailability($coach, '2026-12-05');
        $this->confirmAvailability($coach, '2026-12-06');
        $this->confirmAvailability($coach, '2026-12-12');
        $this->confirmAvailability($coach, '2026-12-13');

        $c1 = $this->svc->commitCoach($coach, $batch1, ['2026-12-05', '2026-12-06'], $this->admin);
        $c2 = $this->svc->commitCoach($coach, $batch2, ['2026-12-12', '2026-12-13'], $this->admin);

        $this->assertCount(2, $c1);
        $this->assertCount(2, $c2);
    }

    // ---------------------------------------------------------------- Availability guards

    public function test_missing_availability_fails_closed(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();
        // No availability declared at all

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('does not have staff-confirmed availability');
        $this->svc->commitCoach($coach, $batch, ['2026-12-05'], $this->admin);
    }

    public function test_self_declared_availability_not_trusted_for_commitment(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();
        $this->selfDeclaredAvailability($coach, '2026-12-05');

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('does not have staff-confirmed availability');
        $this->svc->commitCoach($coach, $batch, ['2026-12-05'], $this->admin);
    }

    // ---------------------------------------------------------------- Active coach guard

    public function test_inactive_coach_cannot_be_committed(): void
    {
        $coach = $this->coach('inactive');
        $batch = $this->batch();

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('not active');
        $this->svc->commitCoach($coach, $batch, ['2026-12-05'], $this->admin);
    }

    // ---------------------------------------------------------------- Authorization

    public function test_non_admin_cannot_assign(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();
        $this->confirmAvailability($coach, '2026-12-05');
        $regularUser = User::factory()->create(['role' => 'coach', 'status' => 'active', 'must_change_password' => false]);

        $this->expectException(CoachSchedulingException::class);
        $this->expectExceptionMessage('not authorized');
        $this->svc->commitCoach($coach, $batch, ['2026-12-05'], $regularUser);
    }

    // ---------------------------------------------------------------- Two-day coverage

    public function test_two_day_batch_requires_commitments_for_both_dates(): void
    {
        $coach = $this->coach();
        $batch = $this->batch('2026-12-05', '2026-12-06');
        $this->confirmAvailability($coach, '2026-12-05');
        $this->confirmAvailability($coach, '2026-12-06');
        $this->booking($batch, 4);

        // Only commit day 1
        $this->svc->commitCoach($coach, $batch, ['2026-12-05'], $this->admin);
        $state = $this->svc->batchStaffingState($batch);

        $this->assertFalse($state['fully_covered']);
        $this->assertTrue($state['dates']['2026-12-05']['covered']);
        $this->assertFalse($state['dates']['2026-12-06']['covered']);

        // Now commit day 2
        $this->svc->commitCoach($coach, $batch, ['2026-12-06'], $this->admin);
        $state = $this->svc->batchStaffingState($batch);
        $this->assertTrue($state['fully_covered']);
    }

    // ---------------------------------------------------------------- Batch dates

    public function test_batch_dates_handles_variable_length_camps(): void
    {
        $twoDayBatch = $this->batch('2026-12-05', '2026-12-06');
        $this->assertSame(['2026-12-05', '2026-12-06'], $this->svc->batchDates($twoDayBatch));

        $oneDayBatch = $this->batch('2026-12-10', '2026-12-10');
        $this->assertSame(['2026-12-10'], $this->svc->batchDates($oneDayBatch));

        $threeDayBatch = $this->batch('2026-12-15', '2026-12-17');
        $this->assertSame(['2026-12-15', '2026-12-16', '2026-12-17'], $this->svc->batchDates($threeDayBatch));
    }

    // ---------------------------------------------------------------- Staffing state

    public function test_batch_staffing_state_reflects_participant_count_and_ratio(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 5);

        $state = $this->svc->batchStaffingState($batch);

        $this->assertSame(5, $state['eligible_participants']);
        $this->assertSame(4, $state['ratio']);
        $this->assertSame(2, $state['required_coaches']);
        $this->assertFalse($state['fully_covered']);
    }

    public function test_cancelled_bookings_excluded_from_eligible_count(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 4, 'confirmed');
        $this->booking($batch, 2, 'cancelled_by_guest');
        $this->booking($batch, 1, 'no_show');

        $this->assertSame(4, $this->svc->eligibleParticipantCount($batch));
    }

    // ---------------------------------------------------------------- Release

    public function test_release_coach_deletes_commitment_rows(): void
    {
        $coach = $this->coach();
        $batch = $this->batch();
        $this->confirmAvailability($coach, '2026-12-05');
        $this->confirmAvailability($coach, '2026-12-06');
        $this->svc->commitCoach($coach, $batch, ['2026-12-05', '2026-12-06'], $this->admin);

        $this->assertSame(2, CoachDateCommitment::where('batch_id', $batch->id)->count());
        $deleted = $this->svc->releaseCoach($coach, $batch);
        $this->assertSame(2, $deleted);
        $this->assertSame(0, CoachDateCommitment::where('batch_id', $batch->id)->count());
    }

    public function test_released_date_can_be_reassigned(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch('2026-12-05', '2026-12-06');
        $batch2 = $this->batch('2026-12-05', '2026-12-06');
        $this->confirmAvailability($coach, '2026-12-05');
        $this->confirmAvailability($coach, '2026-12-06');

        $this->svc->commitCoach($coach, $batch1, ['2026-12-05', '2026-12-06'], $this->admin);
        $this->svc->releaseCoach($coach, $batch1);
        $commitments = $this->svc->commitCoach($coach, $batch2, ['2026-12-05', '2026-12-06'], $this->admin);

        $this->assertCount(2, $commitments);
        $this->assertSame($batch2->id, $commitments[0]->batch_id);
    }

    // ---------------------------------------------------------------- Legacy records not silently trusted

    public function test_legacy_availability_without_confirmation_is_not_trusted(): void
    {
        $coach = $this->coach();
        $this->selfDeclaredAvailability($coach, '2026-12-05');

        $this->assertFalse($this->svc->hasConfirmedAvailability($coach, '2026-12-05'));
    }

    // ---------------------------------------------------------------- DB constraint on conflict

    public function test_database_rejects_duplicate_coach_date_at_constraint_level(): void
    {
        $coach = $this->coach();
        $batch1 = $this->batch();
        $batch2 = $this->batch('2026-12-05', '2026-12-06');

        CoachDateCommitment::create([
            'coach_id' => $coach->id, 'batch_id' => $batch1->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        CoachDateCommitment::create([
            'coach_id' => $coach->id, 'batch_id' => $batch2->id,
            'date' => '2026-12-05', 'assigned_by' => $this->admin->id,
        ]);
    }
}
