<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Ticket;
use App\Models\TicketShare;
use App\Models\User;

/**
 * Authorization for ticket share links.
 *
 * A share is an owner instrument: only the ticket's owner may create one or
 * revoke one. There is deliberately NO operator permission phrase for creating
 * shares — support staff must not be able to mint a bearer link into someone
 * else's ticket. Operators may VIEW share rows for moderation via the ticket
 * permission vocabulary.
 *
 * Resolving a share by its token has no policy: possession of the token IS the
 * authorization (a bearer link), which is why the token is 256-bit and hashed.
 */
class TicketSharePolicy extends BasePolicy
{
    protected string $permissionPrefix = 'ticket';

    /**
     * The actor may create a share link for this ticket.
     */
    public function share(User $user, Ticket $ticket): bool
    {
        return $this->owns($user, $ticket) && $user->isActive();
    }

    /**
     * The actor may revoke this share link.
     */
    public function revoke(User $user, TicketShare $share): bool
    {
        return $this->owns($user, $share) && $user->isActive();
    }

    /**
     * The actor may read this share row (owner, or ticket-permission operator).
     */
    public function view(User $user, TicketShare $model): bool
    {
        return $this->owns($user, $model) || $this->can($user, 'view');
    }
}
