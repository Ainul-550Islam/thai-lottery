<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Bet;
use App\Models\BetAmendment;
use App\Models\User;

/**
 * Authorization for bet amendment.
 *
 * Same ownership-first shape as BetCancellationPolicy: a player amends only
 * their own bet; an operator with the bet permission phrases may amend any.
 * Domain eligibility (window, draw state, valid replacement) belongs to
 * BetAmendmentService and is never answered here.
 */
class BetAmendmentPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'bet';

    /**
     * The actor may attempt an amendment of this bet.
     */
    public function amend(User $user, Bet $bet): bool
    {
        return $this->owns($user, $bet) || $this->can($user, 'update');
    }

    /**
     * The actor may read this amendment record.
     */
    public function view(User $user, BetAmendment $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }

    /**
     * The actor may list amendment records (operator scope).
     */
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'viewAny');
    }
}
