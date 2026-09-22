<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

use App\Enums\PayoutApprovalStatus;

/**
 * The checker's decision over a payout request.
 *
 * A decision is one of exactly two shapes — Approved or Rejected — carried
 * by an actor (checker), with a stated reason when refusing (mandatory on
 * rejection: a refusal without a stated reason is un-auditable), plus the
 * correlation id of its execution slice for trail-joining.
 *
 * The data itself is immutable: an approval that could be silently edited
 * after the fact would defeat the four-eyes control. A DIFFERENT decision is
 * a new row.
 */
final class PayoutApprovalData
{
    /**
     * @param  int  $actorUserId  The checker (approver/rejecter) user id.
     * @param  PayoutApprovalStatus  $decision  Exactly Approved or Rejected; any
     *                                          other status is refused by the
     *                                          service's own guard.
     * @param  string|null  $reason  Mandatory non-empty on Rejected; optional on Approved.
     * @param  array<string, mixed>  $context  Safe diagnostic context (request key, payout id, claimed amount...).
     */
    public function __construct(
        public readonly int $actorUserId,
        public readonly PayoutApprovalStatus $decision,
        public readonly ?string $reason,
        public readonly array $context = [],
    ) {
    }

    public function isApproval(): bool
    {
        return $this->decision === PayoutApprovalStatus::Approved;
    }

    public function isRejection(): bool
    {
        return $this->decision === PayoutApprovalStatus::Rejected;
    }

    /**
     * The decision vocabulary a decision payload may carry: exactly the two
     * checker outcomes. Pending and Completed belong to the machine lane, not
     * a human decision.
     */
    public function decisionIsDecisive(): bool
    {
        return $this->isApproval() || $this->isRejection();
    }

    /**
     * Whether a rejection carries a stated reason. The api's invariant is
     * that a rejection with no reason is malformed and refused by the
     * service; this helper answers what the audit line will name.
     */
    public function reasonIsStated(): bool
    {
        return ! $this->isRejection() || ($this->reason !== null && trim($this->reason) !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'actor_user_id' => $this->actorUserId,
            'decision' => $this->decision->value,
            'reason' => $this->reason,
            'context' => $this->context,
        ];
    }
}
