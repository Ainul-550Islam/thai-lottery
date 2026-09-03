<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'payment';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny');
    }

    public function view(User $user, Payment $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Payment $model): bool
    {
        return $this->can($user, 'update');
    }

    public function delete(User $user, Payment $model): bool
    {
        return $this->can($user, 'delete');
    }
}
