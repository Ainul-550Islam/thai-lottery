<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused prize claim.
 *
 * Thrown when a player's assertion "this winning bet/ticket belongs to me;
 * pay me" cannot be granted: the winning side does not exist, has already
 * been claimed, belongs to someone else, has no payable prize, or the claim
 * itself is in a state from which the attempted step is undefined.
 *
 * WHY NOT PayoutException
 * A claim is the PLAYER-side assertion; a payout is the OPERATIONS-side
 * discharge. The same winning bet can have its claim REFUSED while its payout
 * is perfectly valid (e.g. double-claim attempt: the first claim is right,
 * the second refused), and a payout failure says nothing about claim rights.
 * The two exceptions therefore carry different identities and different
 * compensation semantics: a refused claim manages nothing (nothing moved
 * yet), while a payout failure compensates a wallet.
 *
 * CONTEXT CONTRACT: ids, status strings and decimal amounts only. Never a
 * stack trace, SQL, credential or personal datum.
 */
class PrizeClaimException extends RuntimeException
{
    public const CODE_NO_WINNING_BET = 'CLAIM_NO_WINNING_BET';

    public const CODE_NOT_OWNER = 'CLAIM_NOT_OWNER';

    public const CODE_ALREADY_CLAIMED = 'CLAIM_ALREADY_CLAIMED';

    public const CODE_STATE_FORBIDS = 'CLAIM_STATE_FORBIDS';

    public const CODE_NOTHING_TO_CLAIM = 'CLAIM_NOTHING_TO_CLAIM';

    public const CODE_WINDOW_CLOSED = 'CLAIM_WINDOW_CLOSED';

    public const CODE_ALREADY_RUNNING = 'CLAIM_ALREADY_RUNNING';

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
     * The bet the claim names either does not exist or is not WON, so there
     * is no prize to assert over.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function noWinningBet(int $betId, ?string $districtReason = null, array $context = []): self
    {
        return new self(
            sprintf(
                'Bet #%d %s; there is no prize to claim.',
                $betId,
                $districtReason ?? 'is not a recorded winning bet',
            ),
            self::CODE_NO_WINNING_BET,
            $context + ['bet_id' => $betId],
        );
    }

    /**
     * The claimant is not the owner of the winning bet. A claim that names
     * someone else's win is fraudulent and is refused the same way — loudly.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notOwner(int $betId, int $claimantId, int $ownerId, array $context = []): self
    {
        return new self(
            sprintf(
                'Bet #%d belongs to user #%d; user #%d has no claim over it.',
                $betId,
                $ownerId,
                $claimantId,
            ),
            self::CODE_NOT_OWNER,
            $context + [
                'bet_id' => $betId,
                'claimant_user_id' => $claimantId,
                'owner_user_id' => $ownerId,
            ],
        );
    }

    /**
     * A live claim already occupies this prize. The second one is a double
     * claim, refused deterministically rather than raced.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyClaimed(int $betId, int $existingClaimId, array $context = []): self
    {
        return new self(
            sprintf(
                'The prize for bet #%d is already occupied by claim #%d; claiming twice would pay twice.',
                $betId,
                $existingClaimId,
            ),
            self::CODE_ALREADY_CLAIMED,
            $context + [
                'bet_id' => $betId,
                'existing_claim_id' => $existingClaimId,
            ],
        );
    }

    /**
     * The claim is in a status from which the attempted step is undefined.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function stateForbids(int $claimId, string $status, string $attemptedStep, array $context = []): self
    {
        return new self(
            sprintf('Claim #%d is %s and cannot be %s.', $claimId, $status, $attemptedStep),
            self::CODE_STATE_FORBIDS,
            $context + [
                'claim_id' => $claimId,
                'claim_status' => $status,
                'attempted_step' => $attemptedStep,
            ],
        );
    }

    /**
     * The winning bet's prize computes to zero. There is an assertion of
     * ownership but nothing to discharge, so a claim row would only fake a
     * payable obligation.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function nothingToClaim(int $betId, array $context = []): self
    {
        return new self(
            sprintf('Bet #%d won a prize of zero; there is nothing to claim.', $betId),
            self::CODE_NOTHING_TO_CLAIM,
            $context + ['bet_id' => $betId],
        );
    }

    /**
     * The claim window for this draw's prizes has closed. The prize is
     * reported as lapsed, never rejected silently.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function windowClosed(int $betId, string $windowClosesAt, array $context = []): self
    {
        return new self(
            sprintf(
                'The claim window for the prizes of bet #%d closed at %s; the prize is lapsed, not payable.',
                $betId,
                $windowClosesAt,
            ),
            self::CODE_WINDOW_CLOSED,
            $context + [
                'bet_id' => $betId,
                'window_closes_at' => $windowClosesAt,
            ],
        );
    }

    /**
     * The claim service was entered inside a caller's transaction: its
     * rollback guarantee must belong to it alone, otherwise a caller could
     * swallow the exception and commit a partially executed claim.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyRunning(int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf(
                'Prize claims own their transaction boundary; the caller is already at transaction level %d.',
                $transactionLevel,
            ),
            self::CODE_ALREADY_RUNNING,
            $context + ['transaction_level' => $transactionLevel],
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
