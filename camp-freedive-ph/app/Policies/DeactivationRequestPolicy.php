<?php

namespace App\Policies;

use App\Models\DeactivationRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Only the camp owner can confirm or dismiss a proposed coach deactivation. */
class DeactivationRequestPolicy
{
    public function confirm(User $user, DeactivationRequest $request): Response
    {
        return $user->isOwner()
            ? Response::allow()
            : Response::deny('Unauthorized. Only the Camp Owner can confirm coach deactivations.');
    }

    public function dismiss(User $user, DeactivationRequest $request): Response
    {
        return $user->isOwner()
            ? Response::allow()
            : Response::deny('Unauthorized. Only the Camp Owner can dismiss deactivation requests.');
    }
}
