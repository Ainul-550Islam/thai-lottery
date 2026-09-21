<?php

namespace App\Policies;

use App\Models\Draw;
use App\Models\User;

class DrawPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'draw';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny');
    }

    public function view(User $user, Draw $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Draw $model): bool
    {
        return $this->can($user, 'update');
    }

    /**
     * Closing the betting window is an operational lifecycle action: it gates on the
     * seeded 'manage draws' permission (AdminAccess::MANAGE_DRAWS), which the admin and
     * super-admin roles carry and the player role does not. The domain service still
     * decides whether the transition is *legal* for the draw's current state.
     */
    public function close(User $user, Draw $model): bool
    {
        return \App\Support\Admin\AdminAccess::allows($user, \App\Support\Admin\AdminAccess::MANAGE_DRAWS);
    }

    /**
     * Cancelling a draw is the same operator-gated lifecycle action as closing it.
     */
    public function cancel(User $user, Draw $model): bool
    {
        return \App\Support\Admin\AdminAccess::allows($user, \App\Support\Admin\AdminAccess::MANAGE_DRAWS);
    }

    public function delete(User $user, Draw $model): bool
    {
        return $this->can($user, 'delete');
    }
}
