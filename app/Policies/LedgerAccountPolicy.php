<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LedgerAccount;
use App\Models\User;

class LedgerAccountPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'ledger_account';

    public function viewAny(User $user): bool
    {
        return $user->hasRole(['admin', 'super-admin', 'financial_auditor', 'risk_officer']);
    }

    public function view(User $user, LedgerAccount $model): bool
    {
        return $user->hasRole(['admin', 'super-admin', 'financial_auditor', 'risk_officer']);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['admin', 'super-admin']);
    }

    public function update(User $user, LedgerAccount $model): bool
    {
        return $user->hasRole(['admin', 'super-admin']);
    }

    public function delete(User $user, LedgerAccount $model): bool
    {
        return $user->hasRole(['admin', 'super-admin']);
    }
}
