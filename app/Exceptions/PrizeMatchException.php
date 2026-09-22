<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The matching court's pronounced refusals.
 *
 * - PRIZE_MATCH_MALFORMED        grammar never accepted the ask.
 * - PRIZE_MATCH_NOT_FOUND        named draw/bet/ticket not on the ledger.
 * - PRIZE_MATCH_WRONG_DRAW       bet and draw do not belong together.
 * - PRIZE_MATCH_INVALID_TIER     the named tier isn't in the draw's own
 *                                result lane.
 * - PRIZE_MATCH_AMOUNT_MISMATCH  the claimed amount disagrees with the
 *                                ledger's own calculation.
 * - PRIZE_MATCH_DUPLICATE        a DIFFERENT ask under the same key — fork.
 * - PRIZE_MATCH_NOT_WINNER       the bet isn't even on Won footing.
 */
final class PrizeMatchException extends Exception
{
    public const CODE_MALFORMED = 'PRIZE_MATCH_MALFORMED';

    public const CODE_NOT_FOUND = 'PRIZE_MATCH_NOT_FOUND';

    public const CODE_WRONG_DRAW = 'PRIZE_MATCH_WRONG_DRAW';

    public const CODE_INVALID_TIER = 'PRIZE_MATCH_INVALID_TIER';

    public const CODE_AMOUNT_MISMATCH = 'PRIZE_MATCH_AMOUNT_MISMATCH';

    public const CODE_DUPLICATE = 'PRIZE_MATCH_DUPLICATE';

    public const CODE_NOT_WINNER = 'PRIZE_MATCH_NOT_WINNER';

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
            sprintf('Prize match refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Prize match refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function wrongDraw(int $betId, int $betDrawId, int $askedDrawId, array $context = []): self
    {
        return new self(
            sprintf('Prize match refused: bet #%d belongs to draw #%d, not draw #%d', $betId, $betDrawId, $askedDrawId),
            self::CODE_WRONG_DRAW,
            $context + ['bet_id' => $betId, 'bet_draw_id' => $betDrawId, 'asked_draw_id' => $askedDrawId],
        );
    }

    public static function invalidTier(string $tier, int $drawId, array $context = []): self
    {
        return new self(
            sprintf('Prize match refused: tier [%s] is not in the result lane of draw #%d', $tier, $drawId),
            self::CODE_INVALID_TIER,
            $context + ['tier' => $tier, 'draw_id' => $drawId],
        );
    }

    public static function amountMismatch(int $betId, string $expected, string $presented, array $context = []): self
    {
        return new self(
            sprintf('Prize match refused: the ledger owes %s for bet #%d, the ask says %s', $expected, $betId, $presented),
            self::CODE_AMOUNT_MISMATCH,
            $context + ['bet_id' => $betId, 'expected' => $expected, 'presented' => $presented],
        );
    }

    public static function duplicate(string $matchKey, array $context = []): self
    {
        return new self(
            sprintf('Prize match refused: a different ask presented under key %s...', substr($matchKey, 0, 12)),
            self::CODE_DUPLICATE,
            $context + ['match_key' => $matchKey],
        );
    }

    public static function notWinner(int $betId, string $status, array $context = []): self
    {
        return new self(
            sprintf('Prize match refused: bet #%d is in [%s] — winners only', $betId, $status),
            self::CODE_NOT_WINNER,
            $context + ['bet_id' => $betId, 'status' => $status],
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
