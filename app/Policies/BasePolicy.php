<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

abstract class BasePolicy
{
    use HandlesAuthorization;

    /**
     * Permission prefix used to build ability names, e.g. "wallet.view".
     */
    protected string $permissionPrefix = '';

    protected function can(User $user, string $ability): bool
    {
        if ($this->permissionPrefix === '') {
            return false;
        }

        return $user->isActive()
            && $user->can($this->permissionPrefix.'.'.$ability);
    }

    protected function owns(User $user, mixed $model): bool
    {
        return isset($model->user_id) && (int) $model->user_id === (int) $user->getKey();
    }
}
