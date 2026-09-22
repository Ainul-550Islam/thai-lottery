<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The same winning ticket/prize claimed twice.
 *
 * THE IDEMPOTENCY-OWNED REFUSAL
 * -----------------------------
 * The modern route for a double claim is the claim service's own idempotency:
 * it replays the recorded claim for the SAME claimant, which is how player
 * retry became a curable accident instead of a fraud indicator. The exception
 * remaining is the SHAPE that IS a fraud: a SECOND claimant asserting over a
 * prize already claimed by someone else, or the same claimant claiming the
 * same prize through a second channel (claim + ticket verification paths
 * converging on one bet).
 *
 * CARRIES EVIDENCE, NOT VERDICTS
 * The exception states both identities (prior claimant, current claimant,
 * prior claim id) because the only lawful outcome of a duplicate assertion is
 * forensic review; a silently refused duplicate would hide an attempted
 * steal. Sensitive data exclusion applies — ids and states only, never names
 * or account numbers.
 */
class DuplicatePrizeClaimException extends RuntimeException
{
    public const CODE_CLAIMED_BY_OTHER = 'CLAIM_CLAIMED_BY_OTHER';

    public const CODE_CLAIMED_BY_SAME = 'CLAIM_DUPLICATE_CHANNEL';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A claim already occupies the prize and the claimant is NOT the same
     * user. This is not the player retry path; it is a second soul asserting
     * over a paid-out lane.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function claimedByOther(int $betId, int $priorClaimId, int $priorClaimantId, int $currentClaimantId, array $context = []): self
    {
        return new self(
            sprintf(
                'The prize for bet #%d is already claimed by user #%d; user #%d asserting it now is a duplicate claim and is refused.',
                $betId,
                $priorClaimantId,
                $currentClaimantId,
            ),
            self::CODE_CLAIMED_BY_OTHER,
            $context + [
                'bet_id' => $betId,
                'prior_claim_id' => $priorClaimId,
                'prior_claimant_user_id' => $priorClaimantId,
                'current_claimant_user_id' => $currentClaimantId,
            ],
        );
    }

    /**
     * Same claimant, second channel/attempt of THE SAME prize: the service's
     * replay path DID resolve the claim onto itself successfully in normal
     * flow; reaching this factory means the caller deliberately bypassed
     * idempotency (e.g. user-facing newer-claim supersession attempt). Refuse
     * loudly the same way records-only fraud is refused.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicateChannel(int $betId, int $priorClaimId, string $priorChannel, string $currentChannel, array $context = []): self
    {
        return new self(
            sprintf(
                'Bet #%d was claimed through the %s channel already as claim #%d; a duplicate via the %s channel is refused.',
                $betId,
                $priorChannel,
                $priorClaimId,
                $currentChannel,
            ),
            self::CODE_CLAIMED_BY_SAME,
            $context + [
                'bet_id' => $betId,
                'prior_claim_id' => $priorClaimId,
                'prior_channel' => $priorChannel,
                'current_channel' => $currentChannel,
            ],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
