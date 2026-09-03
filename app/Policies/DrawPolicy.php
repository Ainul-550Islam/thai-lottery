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

    public function delete(User $user, Draw $model): bool
    {
        return $this->can($user, 'delete');
    }
}
