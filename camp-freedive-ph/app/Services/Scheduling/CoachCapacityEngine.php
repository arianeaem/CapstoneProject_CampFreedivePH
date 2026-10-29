<?php

namespace App\Services\Scheduling;

use App\Enums\BookingStatus;
use App\Models\Batch;
use App\Models\CheckoutIntent;
use App\Models\CoachDateCommitment;

/**
 * S3: Shared, testable capacity engine that determines whether each batch date has
 * sufficient confirmed coach coverage. Uses the same participant-counting rules as
 * SlotReservationService (INACTIVE bookings excluded, checkout holds included)
 * but adds provisional hold accounting for coach-to-student ratio calculations.
 *
 * Does NOT modify financial balances, booking payment states, or coach assignments.
 * Does NOT activate any booking gate — used as a read-only query by S4's verifier.
 */
class CoachCapacityEngine
{
    public function __construct(protected CoachSchedulingService $scheduling) {}

    /**
     * Full capacity assessment for a batch, including provisional holds.
     */
    public function assess(Batch $batch): CoachCapacityResult
    {
        $dates = $this->scheduling->batchDates($batch);
        $ratio = $this->scheduling->ratio();

        $confirmedPax = $this->confirmedParticipantCount($batch);
        $provisionalPax = $this->provisionalHoldCount($batch);
        $totalPax = $confirmedPax + $provisionalPax;
        $required = $this->scheduling->requiredCoachCount($totalPax);

        $perDate = [];
        foreach ($dates as $date) {
            $committed = $this->committedActiveCoaches($batch->id, $date);
            $perDate[$date] = [
                'committed_count' => $committed,
                'required_count' => $required,
                'covered' => $committed >= $required,
                'surplus' => $committed - $required,
            ];
        }

        $coachSupportedCapacity = $this->coachSupportedCapacity($batch, $dates, $ratio);

        return new CoachCapacityResult(
            batchId: $batch->id,
            ratio: $ratio,
            confirmedParticipants: $confirmedPax,
            provisionalHolds: $provisionalPax,
            totalEligible: $totalPax,
            requiredCoaches: $required,
            dates: $perDate,
            fullyCovered: collect($perDate)->every(fn ($d) => $d['covered']),
            coachSupportedCapacity: $coachSupportedCapacity,
        );
    }

    /**
     * Verify whether adding $additionalPax to dates $start..$end is covered by confirmed coaches.
     * Used by the S4 InstructorCapacityVerifier implementation.
     */
    public function verifyCapacity(string $startDate, string $endDate, int $additionalPax): array
    {
        $batch = Batch::whereDate('start_date', $startDate)->whereDate('end_date', $endDate)
            ->whereNotIn('status', ['cancelled_by_camp', 'rescheduled'])->first();

        if (! $batch) {
            return ['verified' => false, 'reason' => 'no_batch', 'details' => ['start_date' => $startDate]];
        }

        $dates = $this->scheduling->batchDates($batch);
        $ratio = $this->scheduling->ratio();
        $currentPax = $this->confirmedParticipantCount($batch) + $this->provisionalHoldCount($batch);
        $totalPax = $currentPax + $additionalPax;
        $required = $this->scheduling->requiredCoachCount($totalPax);

        $insufficientDates = [];
        foreach ($dates as $date) {
            $committed = $this->committedActiveCoaches($batch->id, $date);
            if ($committed < $required) {
                $insufficientDates[$date] = ['committed' => $committed, 'required' => $required];
            }
        }

        if (! empty($insufficientDates)) {
            return [
                'verified' => false,
                'reason' => 'insufficient_coaches',
                'details' => [
                    'batch_id' => $batch->id,
                    'total_pax' => $totalPax,
                    'additional_pax' => $additionalPax,
                    'required_coaches' => $required,
                    'insufficient_dates' => $insufficientDates,
                ],
            ];
        }

        return [
            'verified' => true,
            'reason' => 'verified',
            'details' => [
                'batch_id' => $batch->id,
                'total_pax' => $totalPax,
                'required_coaches' => $required,
                'ratio' => $ratio,
            ],
        ];
    }

    /**
     * Confirmed booking participants (all statuses that take a seat, excluding historical).
     * Aligned with SlotReservationService::getConfirmedPaxCount() exclusion list.
     */
    public function confirmedParticipantCount(Batch $batch): int
    {
        return $batch->bookings()
            ->whereNotIn('status', BookingStatus::values(BookingStatus::INACTIVE))
            ->whereNotIn('status', ['completed', 'no_show'])
            ->withCount('participants')
            ->get()
            ->sum('participants_count');
    }

    /**
     * Provisional checkout holds: pending/paid/payment_review intents with live holds on this batch's start date.
     */
    public function provisionalHoldCount(Batch $batch): int
    {
        if (! $batch->start_date) {
            return 0;
        }

        return (int) CheckoutIntent::holdingCapacity()
            ->whereDate('start_date', $batch->start_date->format('Y-m-d'))
            ->sum('pax_count');
    }

    /**
     * Count of committed coaches on a date who are still active (coach status = active).
     */
    private function committedActiveCoaches(int $batchId, string $date): int
    {
        return CoachDateCommitment::where('batch_id', $batchId)
            ->whereDate('date', $date)
            ->whereHas('coach', fn ($q) => $q->where('status', 'active'))
            ->count();
    }

    /**
     * Maximum additional participants the current coach commitments can support.
     * Takes the minimum committed count across all dates, multiplies by ratio, subtracts current pax.
     */
    private function coachSupportedCapacity(Batch $batch, array $dates, int $ratio): int
    {
        $minCoaches = PHP_INT_MAX;
        foreach ($dates as $date) {
            $committed = $this->committedActiveCoaches($batch->id, $date);
            $minCoaches = min($minCoaches, $committed);
        }
        if ($minCoaches === PHP_INT_MAX) {
            $minCoaches = 0;
        }

        $currentPax = $this->confirmedParticipantCount($batch) + $this->provisionalHoldCount($batch);

        return max(0, ($minCoaches * $ratio) - $currentPax);
    }
}
