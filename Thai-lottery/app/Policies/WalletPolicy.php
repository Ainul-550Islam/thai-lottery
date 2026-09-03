<?php

namespace App\Policies;

use App\Models\Wallet;
use App\Models\User;

class WalletPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'wallet';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny');
    }

    public function view(User $user, Wallet $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Wallet $model): bool
    {
        return $this->can($user, 'update');
    }

    public function delete(User $user, Wallet $model): bool
    {
        return $this->can($user, 'delete');
    }
}
