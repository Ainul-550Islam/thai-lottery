<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use App\Models\User;

/**
 * Authorization rules for payout actions.
 *
 * BOUNDARY OF THIS CLASS
 * A policy decides WHO may do WHAT against a target — never HOW. The
 * business workflow (batch execution, transfer, settlement, reversal) lives
 * inside the Payout/Payment service lanes; embedding even a hint of it here
 * would smear one financial decision across two control layers, which the
 * four-eyes batches were written to prevent.
 *
 * BOUNDARY BETWEEN OPERATOR AND OWNER
 * - The OWNER of a payout (the player the obligation belongs to) may SEE the
 *   sanitized representation of their own obligation, and may PETITION its
 *   cancellation while it is still at rest (Pending).
 * - An OPERATOR (admin / super-admin) may see any payout and may petition
 *   cancellation from the fuller set of pre-disbursement states, because the
 *   operations court needs the view of every stage of an obligation to
 *   adjudicate it.
 *
 * Neither role performs any money movement through these rules — they merely
 * admit a principal to an HTTP surface whose controllers hand off to the
 * service lanes.
 */
final class PayoutPolicy
{
    /**
     * May this principal list payout obligations at all?
     *
     * Index is the caller's OWN list — no restriction beyond being an
     * authenticated principal in good standing (enforced upstream by the
     * `active` middleware), so the answer here is always yes. The per-row
     * restriction rides on view() below.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * May this principal view this particular payout?
     *
     * The owner, or an operator-scoped principal. Ownership is by strict id
     * comparison; an operator NEVER gains ownership by virtue of being an
     * operator — the two principals remain distinguishable for audit.
     */
    public function view(User $user, Payout $payout): bool
    {
        if ($this->isOwner($user, $payout)) {
            return true;
        }

        return $user->isAdmin() || $user->isSuperAdmin();
    }

    /**
     * May this principal petition the cancellation of this payout?
     *
     * Only the owner, and only while the obligation is at rest in the one
     * pre-disbursement state where nothing has started moving. Once the
     * payout enters a processing, completed, failed or reversed state, the
     * HTTP surface may not even SPEAK of cancelling it — financial courts
     * own that conversation end to end.
     *
     * NOT embedding the workflow: the policy does not mutate status and does
     * not approve, reject or release any hold. It answers admittance only.
     */
    public function cancel(User $user, Payout $payout): bool
    {
        if (! $this->isOwner($user, $payout)) {
            return false;
        }

        return $payout->status === PayoutStatus::Pending;
    }

    /**
     * May this principal perform the operator-side actions on this payout —
     * view its operational metadata, its batch membership and its transfer
     * lane? The HTTP surface for these is a separate (admin-only) group; the
     * rule here is the smallest possible: an operator principal, no more.
     */
    public function operateOn(User $user, Payout $payout): bool
    {
        return $user->isAdmin() || $user->isSuperAdmin();
    }

    /**
     * Strict ownership — the payout's `user_id` must equal, never merely
     * resemble, the principal's id.
     */
    private function isOwner(User $user, Payout $payout): bool
    {
        return (int) $payout->user_id === (int) $user->getAuthIdentifier();
    }
}
