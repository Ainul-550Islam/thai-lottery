<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

class AgentPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'agent';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny');
    }

    public function view(User $user, Agent $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Agent $model): bool
    {
        return $this->can($user, 'update');
    }

    public function delete(User $user, Agent $model): bool
    {
        return $this->can($user, 'delete');
    }
}
