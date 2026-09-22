<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Payout;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired exactly once per prize claim at the moment the claim turns Approved
 * — the instant the four-eyes claim lane releases a winner's money toward
 * the payout (batch) lane.
 *
 * TWO LEGAL BIRTHS OF THIS EVENT
 * ------------------------------
 *   auto-approved   The claim was submitted BELOW the manual review
 *                   threshold (config lottery.claims.review_threshold) and
 *                   PrizeClaimService::claim() seeded it directly as
 *                   Approved. approvedByUserId is null — no human decided;
 *                   the threshold rule did.
 *   human-approved  The claim sat in UnderReview and an operator released it
 *                   through PrizeClaimService::approve(); approvedByUserId
 *                   names the releasing operator.
 *
 * WHAT LISTENERS MAY RELY ON
 * --------------------------
 *   • Fires at most once per claim: the no-op claim replay path and every
 *     state-machine refusal exit BEFORE this event can fire, and approve()
 *     can only leave UnderReview once.
 *   • The Payout model carries the claim record (metadata.claim) as stamped
 *     by the transition that approved it — listeners read verdict, claimant
 *     and timestamps from there instead of trusting transported copies.
 *   • No money has moved yet when this fires. Approval releases the claim
 *     lane; the batch lane (approvals → batches → execution) moves money
 *     later. Listeners must never credit anyone from this event.
 *
 * NOT BROADCAST
 * -------------
 * This is an internal domain event: its listener is the audit writer. Player
 * notification of "your prize was approved" rides the notification layer
 * and is out of scope here by design — the audit must never depend on the
 * broadcast driver being alive.
 */
class PrizeClaimApproved
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  Payout  $payout  The payout obligation whose claim was
     *                          approved — the event's anchor. Its
     *                          reference_number is the idempotency anchor the
     *                          audit listener keys on.
     * @param  int  $betId  The winning bet the claim asserted ownership of.
     * @param  int  $claimantUserId  The player who made the claim.
     * @param  int|null  $approvedByUserId  The operator who approved, or null
     *                                      when the submission auto-approved
     *                                      under the review threshold.
     * @param  bool  $autoApproved  True exactly when the threshold rule, not
     *                              a human, approved the claim.
     */
    public function __construct(
        public readonly Payout $payout,
        public readonly int $betId,
        public readonly int $claimantUserId,
        public readonly ?int $approvedByUserId,
        public readonly bool $autoApproved,
    ) {}

    /**
     * The prize amount the claim approved, as a 2-decimal string of the
     * anchor currency. Decimals never go through float.
     */
    public function amount(): string
    {
        return (string) $this->payout->amount;
    }

    /**
     * The anchor currency code, e.g. THB.
     */
    public function currency(): string
    {
        return $this->payout->currency->value;
    }

    /**
     * A projection of the event for listeners that persist or forward it:
     * no model objects survive the projection.
     *
     * @return array<string, mixed>
     */
    public function toAuditPayload(): array
    {
        return [
            'payout_id' => (int) $this->payout->getKey(),
            'payout_reference' => (string) $this->payout->reference_number,
            'bet_id' => $this->betId,
            'claimant_user_id' => $this->claimantUserId,
            'approved_by_user_id' => $this->approvedByUserId,
            'auto_approved' => $this->autoApproved,
            'amount' => $this->amount(),
            'currency' => $this->currency(),
            'action' => 'prize_claim_approved',
        ];
    }
}
