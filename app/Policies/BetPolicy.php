<?php

namespace App\Policies;

use App\Models\Bet;
use App\Models\User;

class BetPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'bet';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny');
    }

    public function view(User $user, Bet $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Bet $model): bool
    {
        return $this->can($user, 'update');
    }

    public function delete(User $user, Bet $model): bool
    {
        return $this->can($user, 'delete');
    }
}
