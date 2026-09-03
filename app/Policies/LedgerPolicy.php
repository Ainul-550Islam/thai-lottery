<?php

namespace App\Policies;

use App\Models\LedgerEntry;
use App\Models\User;

class LedgerPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'ledger';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny');
    }

    public function view(User $user, LedgerEntry $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, LedgerEntry $model): bool
    {
        return $this->can($user, 'update');
    }

    public function delete(User $user, LedgerEntry $model): bool
    {
        return $this->can($user, 'delete');
    }
}
