<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The reconciliation lane's pronounced refusals.
 *
 * - DRAW_RECON_MALFORMED        grammar never accepted the pack.
 * - DRAW_RECON_NOT_FOUND        named draw/reconciliation missing.
 * - DRAW_RECON_RESULT_MISMATCH  the winning numbers on the ledger don't
 *                               match what the pack asserts as the result.
 * - DRAW_RECON_PRIZE_MISMATCH   prize-settlement lane disagreement beyond
 *                               tolerance.
 * - DRAW_RECON_TICKET_MISMATCH  winning-ticket lane disagreement.
 * - DRAW_RECON_UNRESOLVED_DRIFT asked to treat a drifted reconciliation as
 *                               usable WITHOUT a human resolution first.
 */
final class DrawReconciliationException extends Exception
{
    public const CODE_MALFORMED = 'DRAW_RECON_MALFORMED';

    public const CODE_NOT_FOUND = 'DRAW_RECON_NOT_FOUND';

    public const CODE_RESULT_MISMATCH = 'DRAW_RECON_RESULT_MISMATCH';

    public const CODE_PRIZE_MISMATCH = 'DRAW_RECON_PRIZE_MISMATCH';

    public const CODE_TICKET_MISMATCH = 'DRAW_RECON_TICKET_MISMATCH';

    public const CODE_UNRESOLVED_DRIFT = 'DRAW_RECON_UNRESOLVED_DRIFT';

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
            sprintf('Draw reconciliation refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $drawReference, array $context = []): self
    {
        return new self(
            sprintf('Draw reconciliation refused: [%s] is not on the ledger', $drawReference),
            self::CODE_NOT_FOUND,
            $context + ['draw_reference' => $drawReference],
        );
    }

    public static function resultMismatch(int $drawId, array $context = []): self
    {
        return new self(
            sprintf('Draw reconciliation refused: the asserted numbers do not match the draw #%d result lane', $drawId),
            self::CODE_RESULT_MISMATCH,
            $context + ['draw_id' => $drawId],
        );
    }

    public static function prizeMismatch(int $drawId, string $expected, string $actual, array $context = []): self
    {
        return new self(
            sprintf('Draw reconciliation refused: draw #%d prize lane disagrees (%s vs %s)', $drawId, $expected, $actual),
            self::CODE_PRIZE_MISMATCH,
            $context + ['draw_id' => $drawId, 'expected' => $expected, 'actual' => $actual],
        );
    }

    public static function ticketMismatch(int $drawId, string $expected, string $actual, array $context = []): self
    {
        return new self(
            sprintf('Draw reconciliation refused: draw #%d winning-ticket lane disagrees (%s vs %s)', $drawId, $expected, $actual),
            self::CODE_TICKET_MISMATCH,
            $context + ['draw_id' => $drawId, 'expected' => $expected, 'actual' => $actual],
        );
    }

    public static function unresolvedDrift(int $drawId, array $context = []): self
    {
        return new self(
            sprintf('Draw reconciliation refused: drift on draw #%d is still waiting for the judge', $drawId),
            self::CODE_UNRESOLVED_DRIFT,
            $context + ['draw_id' => $drawId],
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
