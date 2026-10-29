<?php

namespace Tests\Feature\Scheduling;

use App\Enums\BookingStatus;
use App\Models\Batch;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CheckoutIntent;
use App\Models\Coach;
use App\Models\CoachAvailability;
use App\Models\User;
use App\Services\Scheduling\CoachCapacityEngine;
use App\Services\Scheduling\CoachCapacityResult;
use App\Services\Scheduling\CoachSchedulingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S3/S5: authoritative capacity engine — participant counting, provisional holds,
 * per-date coverage, coach-supported capacity, and verifyCapacity for S4.
 */
class CoachCapacityEngineTest extends TestCase
{
    use RefreshDatabase;

    private CoachCapacityEngine $engine;
    private CoachSchedulingService $svc;
    private User $admin;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(CoachCapacityEngine::class);
        $this->svc = app(CoachSchedulingService::class);
        $this->admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'must_change_password' => false]);
    }

    private function coach(): Coach
    {
        $user = User::factory()->create(['role' => 'coach', 'status' => 'active', 'must_change_password' => false]);
        return Coach::create([
            'user_id' => $user->id, 'full_name' => 'S3 Coach ' . (++$this->seq),
            'email' => "s3coach{$this->seq}@example.test", 'phone' => '09170000001',
            'certification_level' => 'AIDA 4 Master', 'certification_number' => "S3-{$this->seq}",
            'certification_expiry' => now()->addYear(), 'status' => 'active',
        ]);
    }

    private function batch(string $start = '2026-12-12', string $end = '2026-12-13'): Batch
    {
        return Batch::create([
            'name' => 'S3 Batch ' . (++$this->seq), 'batch_code' => 'S3-' . $this->seq,
            'start_date' => $start, 'end_date' => $end,
            'status' => 'confirmed', 'lifecycle_status' => 'confirmed',
        ]);
    }

    private function booking(Batch $batch, string $status = 'confirmed', int $pax = 1): Booking
    {
        $booking = Booking::create([
            'batch_id' => $batch->id, 'booking_number' => 'BK-S3-' . (++$this->seq), 'pin' => '1234',
            'class_type' => 'discovery', 'status' => $status, 'total_amount' => 4250 * $pax,
            'downpayment_amount' => 2125 * $pax, 'balance_amount' => 2125 * $pax,
            'contact_name' => 'Guest', 'contact_email' => 'guest@example.test', 'contact_phone' => '0917',
            'start_date' => $batch->start_date, 'end_date' => $batch->end_date,
        ]);
        for ($i = 0; $i < $pax; $i++) {
            BookingParticipant::create([
                'booking_id' => $booking->id, 'name' => "Pax {$this->seq}-{$i}", 'age' => 25, 'price_per_person' => 4250,
            ]);
        }

        return $booking;
    }

    private function assignCoach(Coach $coach, Batch $batch): void
    {
        $dates = $this->svc->batchDates($batch);
        foreach ($dates as $date) {
            CoachAvailability::create([
                'coach_id' => $coach->user_id, 'date' => $date, 'status' => 'available',
            ]);
            $this->svc->confirmAvailability($coach, $date, $this->admin);
        }
        $this->svc->commitCoach($coach, $batch, $dates, $this->admin);
    }

    // ---------------------------------------------------------------- Participant counting

    public function test_confirmed_participant_count_excludes_inactive_and_historical(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 3);
        $this->booking($batch, 'cancelled_by_guest', 2);
        $this->booking($batch, 'cancelled_by_camp', 1);
        $this->booking($batch, 'completed', 2);
        $this->booking($batch, 'no_show', 1);

        $count = $this->engine->confirmedParticipantCount($batch);
        $this->assertSame(3, $count);
    }

    public function test_going_statuses_are_counted(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 2);
        $this->booking($batch, 'reschedule_requested', 1);
        $this->booking($batch, 'cancellation_requested', 1);

        $this->assertSame(4, $this->engine->confirmedParticipantCount($batch));
    }

    // ---------------------------------------------------------------- Provisional holds

    public function test_provisional_hold_count_includes_active_checkout_intents(): void
    {
        $batch = $this->batch();

        $intentBase = [
            'class_type' => 'discovery', 'pickup_option' => 'none', 'payload' => [],
            'start_date' => $batch->start_date->format('Y-m-d'),
            'end_date' => $batch->end_date->format('Y-m-d'),
        ];

        CheckoutIntent::create(array_merge($intentBase, [
            'reference' => 'TEST-S3-HOLD1', 'status' => 'pending',
            'pax_count' => 3, 'total_amount' => 12750, 'downpayment_amount' => 6375,
            'hold_expires_at' => now()->addMinutes(10),
        ]));

        CheckoutIntent::create(array_merge($intentBase, [
            'reference' => 'TEST-S3-HOLD2', 'status' => 'expired',
            'pax_count' => 2, 'total_amount' => 8500, 'downpayment_amount' => 4250,
            'hold_expires_at' => now()->subMinute(),
        ]));

        $this->assertSame(3, $this->engine->provisionalHoldCount($batch));
    }

    // ---------------------------------------------------------------- Capacity assessment

    public function test_assess_shows_uncovered_batch_with_no_coaches(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 4);

        $result = $this->engine->assess($batch);

        $this->assertInstanceOf(CoachCapacityResult::class, $result);
        $this->assertSame(4, $result->confirmedParticipants);
        $this->assertSame(1, $result->requiredCoaches);
        $this->assertFalse($result->fullyCovered);
        $this->assertSame(0, $result->coachSupportedCapacity);
    }

    public function test_assess_shows_covered_batch_with_sufficient_coaches(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 4);
        $coach = $this->coach();
        $this->assignCoach($coach, $batch);

        $result = $this->engine->assess($batch);

        $this->assertTrue($result->fullyCovered);
        $this->assertSame(1, $result->requiredCoaches);
        foreach ($result->dates as $date => $info) {
            $this->assertTrue($info['covered']);
            $this->assertSame(1, $info['committed_count']);
        }
    }

    public function test_assess_accounts_for_provisional_holds_in_required_coaches(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 3);

        CheckoutIntent::create([
            'reference' => 'TEST-S3-PROV', 'status' => 'pending',
            'start_date' => $batch->start_date->format('Y-m-d'),
            'end_date' => $batch->end_date->format('Y-m-d'),
            'pax_count' => 2, 'class_type' => 'discovery', 'pickup_option' => 'none',
            'payload' => [], 'total_amount' => 8500, 'downpayment_amount' => 4250,
            'hold_expires_at' => now()->addMinutes(10),
        ]);

        $result = $this->engine->assess($batch);

        $this->assertSame(3, $result->confirmedParticipants);
        $this->assertSame(2, $result->provisionalHolds);
        $this->assertSame(5, $result->totalEligible);
        $this->assertSame(2, $result->requiredCoaches);
    }

    public function test_coach_supported_capacity_uses_minimum_coverage(): void
    {
        $batch = $this->batch();
        $coach1 = $this->coach();
        $coach2 = $this->coach();
        $this->assignCoach($coach1, $batch);
        $this->assignCoach($coach2, $batch);

        $result = $this->engine->assess($batch);
        $ratio = $this->svc->ratio();

        $this->assertSame(2 * $ratio, $result->coachSupportedCapacity);
    }

    public function test_coach_supported_capacity_subtracts_current_pax(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 2);
        $coach = $this->coach();
        $this->assignCoach($coach, $batch);

        $result = $this->engine->assess($batch);

        $this->assertSame($this->svc->ratio() - 2, $result->coachSupportedCapacity);
    }

    // ---------------------------------------------------------------- verifyCapacity (S4 interface)

    public function test_verify_capacity_returns_verified_when_sufficient(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 2);
        $coach = $this->coach();
        $this->assignCoach($coach, $batch);

        $result = $this->engine->verifyCapacity(
            $batch->start_date->format('Y-m-d'),
            $batch->end_date->format('Y-m-d'),
            1,
        );

        $this->assertTrue($result['verified']);
        $this->assertSame('verified', $result['reason']);
    }

    public function test_verify_capacity_returns_insufficient_when_over_ratio(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 4);
        $coach = $this->coach();
        $this->assignCoach($coach, $batch);

        $result = $this->engine->verifyCapacity(
            $batch->start_date->format('Y-m-d'),
            $batch->end_date->format('Y-m-d'),
            1,
        );

        $this->assertFalse($result['verified']);
        $this->assertSame('insufficient_coaches', $result['reason']);
        $this->assertNotEmpty($result['details']['insufficient_dates']);
    }

    public function test_verify_capacity_returns_no_batch_for_unknown_dates(): void
    {
        $result = $this->engine->verifyCapacity('2099-01-01', '2099-01-02', 1);
        $this->assertFalse($result['verified']);
        $this->assertSame('no_batch', $result['reason']);
    }

    public function test_verify_capacity_skips_cancelled_batches(): void
    {
        Batch::create([
            'name' => 'Cancelled', 'batch_code' => 'S3-CANCEL',
            'start_date' => '2026-12-20', 'end_date' => '2026-12-21',
            'status' => 'cancelled_by_camp', 'lifecycle_status' => 'cancelled',
        ]);

        $result = $this->engine->verifyCapacity('2026-12-20', '2026-12-21', 1);
        $this->assertFalse($result['verified']);
        $this->assertSame('no_batch', $result['reason']);
    }

    // ---------------------------------------------------------------- toArray serialization

    public function test_capacity_result_to_array(): void
    {
        $batch = $this->batch();
        $result = $this->engine->assess($batch);
        $arr = $result->toArray();

        $this->assertArrayHasKey('batch_id', $arr);
        $this->assertArrayHasKey('ratio', $arr);
        $this->assertArrayHasKey('fully_covered', $arr);
        $this->assertArrayHasKey('coach_supported_capacity', $arr);
    }

    // ---------------------------------------------------------------- Inactive coach exclusion

    public function test_inactive_coach_commitment_not_counted(): void
    {
        $batch = $this->batch();
        $this->booking($batch, 'confirmed', 2);

        $coach = $this->coach();
        $this->assignCoach($coach, $batch);

        $coach->update(['status' => 'inactive']);

        $result = $this->engine->assess($batch);
        $this->assertFalse($result->fullyCovered);
        foreach ($result->dates as $info) {
            $this->assertSame(0, $info['committed_count']);
        }
    }
}
