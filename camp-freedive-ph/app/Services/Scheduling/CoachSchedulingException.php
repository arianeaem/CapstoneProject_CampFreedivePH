<?php

namespace App\Services\Scheduling;

use App\Models\Coach;
use App\Models\User;
use RuntimeException;

class CoachSchedulingException extends RuntimeException
{
    public static function inactiveCoach(Coach $coach): self
    {
        return new self("Coach {$coach->full_name} is not active (status: {$coach->status}).");
    }

    public static function unauthorizedAssigner(User $user): self
    {
        return new self("User {$user->id} is not authorized to assign coaches (role: {$user->role}, status: {$user->status}).");
    }

    public static function noConfirmedAvailability(Coach $coach, string $date): self
    {
        return new self("Coach {$coach->full_name} does not have staff-confirmed availability on {$date}.");
    }

    public static function dateConflict(Coach $coach, array $dates): self
    {
        $dateList = implode(', ', $dates);

        return new self("Coach {$coach->full_name} already has a commitment on one of: {$dateList}.");
    }

    public static function selfConfirmation(Coach $coach): self
    {
        return new self("Coach {$coach->full_name} cannot confirm their own availability.");
    }

    public static function noAvailabilityToConfirm(Coach $coach, string $date): self
    {
        return new self("Coach {$coach->full_name} has no available declaration on {$date} to confirm.");
    }

    public static function noConfirmationToRevoke(Coach $coach, string $date): self
    {
        return new self("Coach {$coach->full_name} has no confirmed availability on {$date} to revoke.");
    }

    public static function commitmentBlocksRevocation(Coach $coach, string $date): self
    {
        return new self("Coach {$coach->full_name} has an active commitment on {$date}. Remove the commitment before revoking confirmation.");
    }
}
