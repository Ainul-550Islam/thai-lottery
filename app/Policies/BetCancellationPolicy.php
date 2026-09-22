<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Bet;
use App\Models\User;

/**
 * Authorization for bet cancellation.
 *
 * THE PLAYER RULE
 * A player may cancel only their OWN bet (ownership, not a permission phrase)
 * and only while the domain rules allow it — status/window/draw belong to
 * BetCancellationService, which re-checks them under the row lock. The policy
 * answers one question: "is this bet yours?"
 *
 * THE OPERATOR RULE
 * An operator with the `bet.update` or `bet.delete` permission phrase may
 * cancel any bet (support cancellations). The permission vocabulary is the
 * BasePolicy one, matching every other policy in the project.
 */
class BetCancellationPolicy extends BasePolicy
{
    protected string $permissionPrefix = 'bet';

    /**
     * The actor may attempt a cancellation of this bet.
     *
     * Domain eligibility (status, window, draw) is NOT answered here — that is
     * the service's authority under the row lock.
     */
    public function cancel(User $user, Bet $bet): bool
    {
        return $this->owns($user, $bet)
            || $this->can($user, 'update')
            || $this->can($user, 'delete');
    }

    /**
     * The actor may see the cancellation outcome of this bet.
     */
    public function view(User $user, Bet $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }
}
