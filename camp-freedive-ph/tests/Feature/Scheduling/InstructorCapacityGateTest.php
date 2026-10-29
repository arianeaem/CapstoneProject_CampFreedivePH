<?php

namespace Tests\Feature\Scheduling;

use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\User;
use App\Services\Payment\Allocation\AuthoritativeInstructorCapacity;
use App\Services\Payment\Allocation\InstructorCapacityVerifier;
use App\Services\Payment\Allocation\UnconfirmedInstructorCapacity;
use App\Services\Scheduling\CoachSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S4/S5: verify the InstructorCapacityVerifier binding remains fail-closed (UnconfirmedInstructorCapacity)
 * and that AuthoritativeInstructorCapacity works correctly when directly instantiated.
 */
class InstructorCapacityGateTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function coach(): Coach
    {
        $user = User::factory()->create(['role' => 'coach', 'status' => 'active', 'must_change_password' => false]);
        return Coach::create([
            'user_id' => $user->id, 'full_name' => 'S4 Coach ' . (++$this->seq),
            'email' => "s4coach{$this->seq}@example.test", 'phone' => '09170000001',
            'certification_level' => 'AIDA 4 Master', 'certification_number' => "S4-{$this->seq}",
            'certification_expiry' => now()->addYear(), 'status' => 'active',
        ]);
    }

    private function batch(): Batch
    {
        return Batch::create([
            'name' => 'S4 Batch ' . (++$this->seq), 'batch_code' => 'S4-' . $this->seq,
            'start_date' => '2026-12-19', 'end_date' => '2026-12-20',
            'status' => 'confirmed', 'lifecycle_status' => 'confirmed',
        ]);
    }

    // ---------------------------------------------------------------- S4.1: Gate remains disabled

    public function test_container_binding_is_unconfirmed_instructor_capacity(): void
    {
        $verifier = app(InstructorCapacityVerifier::class);
        $this->assertInstanceOf(UnconfirmedInstructorCapacity::class, $verifier);
    }

    public function test_default_verifier_always_returns_unverified(): void
    {
        $verifier = app(InstructorCapacityVerifier::class);
        $result = $verifier->verify('2026-12-19', '2026-12-20', 1);

        $this->assertFalse($result->verified);
        $this->assertSame('instructor_schedule_unconfirmed', $result->reason);
    }

    // ---------------------------------------------------------------- S4.2: Authoritative verifier (direct inject)

    public function test_authoritative_verifier_returns_verified_with_sufficient_coaches(): void
    {
        $batch = $this->batch();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);
        $svc = app(CoachSchedulingService::class);

        Booking::create([
            'batch_id' => $batch->id, 'booking_number' => 'BK-S4-1', 'pin' => '1234',
            'class_type' => 'discovery', 'status' => 'confirmed', 'total_amount' => 4250,
            'downpayment_amount' => 2125, 'balance_amount' => 2125,
            'contact_name' => 'Guest', 'contact_email' => 'guest@example.test', 'contact_phone' => '0917',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);
        BookingParticipant::create([
            'booking_id' => Booking::latest('id')->first()->id, 'name' => 'Pax 1', 'age' => 25, 'price_per_person' => 4250,
        ]);

        $coach = $this->coach();
        foreach (['2026-12-19', '2026-12-20'] as $date) {
            CoachAvailability::create(['coach_id' => $coach->user_id, 'date' => $date, 'status' => 'available']);
            $svc->confirmAvailability($coach, $date, $admin);
        }
        $svc->commitCoach($coach, $batch, ['2026-12-19', '2026-12-20'], $admin);

        $verifier = app(AuthoritativeInstructorCapacity::class);
        $result = $verifier->verify('2026-12-19', '2026-12-20', 1);

        $this->assertTrue($result->verified);
    }

    public function test_authoritative_verifier_returns_unverified_when_over_ratio(): void
    {
        $batch = $this->batch();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);
        $svc = app(CoachSchedulingService::class);

        $booking = Booking::create([
            'batch_id' => $batch->id, 'booking_number' => 'BK-S4-2', 'pin' => '1234',
            'class_type' => 'discovery', 'status' => 'confirmed', 'total_amount' => 17000,
            'downpayment_amount' => 8500, 'balance_amount' => 8500,
            'contact_name' => 'Guest', 'contact_email' => 'guest@example.test', 'contact_phone' => '0917',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);
        for ($i = 0; $i < 4; $i++) {
            BookingParticipant::create([
                'booking_id' => $booking->id, 'name' => "Pax S4-{$i}", 'age' => 25, 'price_per_person' => 4250,
            ]);
        }

        $coach = $this->coach();
        foreach (['2026-12-19', '2026-12-20'] as $date) {
            CoachAvailability::create(['coach_id' => $coach->user_id, 'date' => $date, 'status' => 'available']);
            $svc->confirmAvailability($coach, $date, $admin);
        }
        $svc->commitCoach($coach, $batch, ['2026-12-19', '2026-12-20'], $admin);

        $verifier = app(AuthoritativeInstructorCapacity::class);
        $result = $verifier->verify('2026-12-19', '2026-12-20', 1);

        $this->assertFalse($result->verified);
        $this->assertSame('insufficient_coaches', $result->reason);
    }

    public function test_authoritative_verifier_returns_no_batch_for_unknown_dates(): void
    {
        $verifier = app(AuthoritativeInstructorCapacity::class);
        $result = $verifier->verify('2099-01-01', '2099-01-02', 1);

        $this->assertFalse($result->verified);
        $this->assertSame('no_batch', $result->reason);
    }

    // ---------------------------------------------------------------- S4.3: Binding NOT accidentally replaced

    public function test_authoritative_verifier_is_not_the_bound_implementation(): void
    {
        $bound = app(InstructorCapacityVerifier::class);
        $this->assertNotInstanceOf(AuthoritativeInstructorCapacity::class, $bound);
    }
}
