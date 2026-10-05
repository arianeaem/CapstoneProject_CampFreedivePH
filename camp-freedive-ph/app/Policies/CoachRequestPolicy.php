<?php

namespace App\Policies;

use App\Models\CoachRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** A coach can only see and withdraw their own open-slot requests. */
class CoachRequestPolicy
{
    public function view(User $user, CoachRequest $coachRequest): bool
    {
        return !$user->isCoach() || $coachRequest->coach_id === $user->id;
    }

    public function withdraw(User $user, CoachRequest $coachRequest): Response
    {
        return $coachRequest->coach_id === $user->id
            ? Response::allow()
            : Response::deny('Unauthorized action.');
    }
}
