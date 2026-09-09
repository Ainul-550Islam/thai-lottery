<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgentCommission;
use App\Models\User;

class AgentCommissionPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'commission';

    public function viewAny(User $user): bool
    {
        return $user->hasRole(['admin', 'super-admin', 'auditor'])
            || $user->hasPermissionTo('view commissions')
            || $this->can($user, 'viewAny');
    }

    public function view(User $user, AgentCommission $model): bool
    {
        if ($user->hasRole(['admin', 'super-admin', 'auditor'])) {
            return true;
        }

        // Agent can view their own commission records
        if ($this->owns($user, $model)) {
            return true;
        }

        if ($user->agent !== null && (int) $model->agent_id === (int) $user->agent->id) {
            return true;
        }

        return $this->can($user, 'view');
    }
}
