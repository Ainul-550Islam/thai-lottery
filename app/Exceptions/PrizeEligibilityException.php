<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The eligibility lane's pronounced refusals.
 *
 * - PRIZE_ELIG_MALFORMED           grammar never accepted the ask.
 * - PRIZE_ELIG_NOT_FOUND           named payout/ticket not on the ledger.
 * - PRIZE_ELIG_OWNERSHIP_MISMATCH  the claimant isn't the payout's own
 *                                  authoritative principal.
 * - PRIZE_ELIG_EXPIRED_CLAIM       the claim window is closed.
 * - PRIZE_ELIG_BLOCKED_CLAIMANT    self-excluded / blocked principal.
 * - PRIZE_ELIG_INVALID_TICKET      the ticket's ledger state can't claim.
 */
final class PrizeEligibilityException extends Exception
{
    public const CODE_MALFORMED = 'PRIZE_ELIG_MALFORMED';

    public const CODE_NOT_FOUND = 'PRIZE_ELIG_NOT_FOUND';

    public const CODE_OWNERSHIP_MISMATCH = 'PRIZE_ELIG_OWNERSHIP_MISMATCH';

    public const CODE_EXPIRED_CLAIM = 'PRIZE_ELIG_EXPIRED_CLAIM';

    public const CODE_BLOCKED_CLAIMANT = 'PRIZE_ELIG_BLOCKED_CLAIMANT';

    public const CODE_INVALID_TICKET = 'PRIZE_ELIG_INVALID_TICKET';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $errorContext = [],
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $reason, array $context = []): self
    {
        return new self(
            sprintf('Prize eligibility refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Prize eligibility refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function ownershipMismatch(int $payoutId, array $context = []): self
    {
        return new self(
            sprintf('Prize eligibility refused: the claimant is not the principal of payout #%d', $payoutId),
            self::CODE_OWNERSHIP_MISMATCH,
            $context + ['payout_id' => $payoutId],
        );
    }

    public static function expiredClaim(int $payoutId, array $context = []): self
    {
        return new self(
            sprintf('Prize eligibility refused: the claim window for payout #%d is closed', $payoutId),
            self::CODE_EXPIRED_CLAIM,
            $context + ['payout_id' => $payoutId],
        );
    }

    public static function blockedClaimant(int $userId, array $context = []): self
    {
        return new self(
            sprintf('Prize eligibility refused: claimant #%d is blocked (self-exclusion or hard gate)', $userId),
            self::CODE_BLOCKED_CLAIMANT,
            $context + ['user_id' => $userId],
        );
    }

    public static function invalidTicket(string $ticketState, array $context = []): self
    {
        return new self(
            sprintf('Prize eligibility refused: the ticket is in [%s], which can claim nothing', $ticketState),
            self::CODE_INVALID_TICKET,
            $context + ['ticket_state' => $ticketState],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->errorContext;
    }
}
