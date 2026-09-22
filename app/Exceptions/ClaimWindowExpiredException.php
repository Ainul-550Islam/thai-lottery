<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A claim attempted against an EXPIRED or CLOSED prize claim window.
 *
 * THE DEDICATED GATE
 * ------------------
 * GLO parity: prizes that were never asserted don't stay payable forever —
 * the claim window legislates their lawful expiry. This exception is the ONE
 * shape all such refuse takes: a claim arriving after the window's deadline
 * must not be honest about money that is already lapsed, and the refusal is
 * the claim-flow's authority to refuse, NOT a generic domain 400.
 *
 * Sweeping (ClaimWindowService / CloseExpiredClaimWindowsJob) closes the
 * window; claiming (PrizeClaimService / PrizeClaimValidationService) refuses
 * on it. Carrying its own exception means the refusal explaintable as
 * domain-level ("your window closed") rather than engineering-level
 * ("assertion failed").
 */
class ClaimWindowExpiredException extends RuntimeException
{
    public const CODE_WINDOW_EXPIRED = 'CLAIM_WINDOW_EXPIRED';

    public const CODE_WINDOW_CLOSED = 'CLAIM_WINDOW_ALREADY_CLOSED';

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
     * The claim was attempted after the deadline: lapsed money presented as
     * lawfully claimable. The sweep may not even have closed it yet — time
     * alone closes a claim; the sweep only writes the stamp.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function expired(int $betId, string $windowClosesAt, array $context = []): self
    {
        return new self(
            sprintf(
                'The claim window for the prize of bet #%d closed at %s; the money is lapsed and this claim is refused.',
                $betId,
                $windowClosesAt,
            ),
            self::CODE_WINDOW_EXPIRED,
            $context + ['bet_id' => $betId, 'window_closes_at' => $windowClosesAt],
        );
    }

    /**
     * The window carries the sweeper's closed stamp — the ledger already
     * turned the obligation's face away long before any such claim arrived.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyClosed(int $betId, string $closedAt, array $context = []): self
    {
        return new self(
            sprintf(
                'The claim window for the prize of bet #%d was closed at %s; the obligation no longer exists.',
                $betId,
                $closedAt,
            ),
            self::CODE_WINDOW_CLOSED,
            $context + ['bet_id' => $betId, 'closed_at' => $closedAt],
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
