<?php

namespace App\Services\Scheduling;

final class CoachCapacityResult
{
    public function __construct(
        public readonly int $batchId,
        public readonly int $ratio,
        public readonly int $confirmedParticipants,
        public readonly int $provisionalHolds,
        public readonly int $totalEligible,
        public readonly int $requiredCoaches,
        public readonly array $dates,
        public readonly bool $fullyCovered,
        public readonly int $coachSupportedCapacity,
    ) {}

    public function toArray(): array
    {
        return [
            'batch_id' => $this->batchId,
            'ratio' => $this->ratio,
            'confirmed_participants' => $this->confirmedParticipants,
            'provisional_holds' => $this->provisionalHolds,
            'total_eligible' => $this->totalEligible,
            'required_coaches' => $this->requiredCoaches,
            'dates' => $this->dates,
            'fully_covered' => $this->fullyCovered,
            'coach_supported_capacity' => $this->coachSupportedCapacity,
        ];
    }
}
