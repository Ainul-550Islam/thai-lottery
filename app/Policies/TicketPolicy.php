<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'ticket';

    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny');
    }

    public function view(User $user, Ticket $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'create');
    }

    public function update(User $user, Ticket $model): bool
    {
        return $this->can($user, 'update');
    }

    public function delete(User $user, Ticket $model): bool
    {
        return $this->can($user, 'delete');
    }
}
