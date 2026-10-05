<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may manage staff accounts.
 * The owner manages everyone; admins only manage coach accounts.
 */
class UserPolicy
{
    public function create(User $actor): bool
    {
        return $actor->isOwner() || $actor->isAdmin();
    }

    public function update(User $actor, User $target): Response
    {
        return $this->canManage($actor, $target)
            ? Response::allow()
            : Response::deny('Admins can only manage Freediving Coach profiles.');
    }

    public function changeStatus(User $actor, User $target): Response
    {
        return $this->canManage($actor, $target)
            ? Response::allow()
            : Response::deny('Admins can only toggle status of Coach accounts.');
    }

    public function delete(User $actor, User $target): Response
    {
        return $this->canManage($actor, $target)
            ? Response::allow()
            : Response::deny('Admins can only delete Freediving Coach accounts.');
    }

    protected function canManage(User $actor, User $target): bool
    {
        return $actor->isOwner() || !($target->isAdmin() || $target->isOwner());
    }
}
