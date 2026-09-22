<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The financial-hold lane's pronounced refusals.
 *
 * - FIN_HOLD_MALFORMED           grammar never accepted the ask.
 * - FIN_HOLD_NOT_FOUND           named hold/wallet missing.
 * - FIN_HOLD_DUPLICATE           different ask under the same key — fork.
 * - FIN_HOLD_INVALID_RELEASE     releasing over the row's own held total
 *                                or a dead row.
 * - FIN_HOLD_EXPIRED             the hold is dead already (used so callers
 *                                never act against a tombstone).
 * - FIN_HOLD_AMOUNT_MISMATCH     asked money disagrees with the row's.
 * - FIN_HOLD_SOURCE_MISMATCH     asked source isn't the row's own.
 */
final class FinancialHoldException extends Exception
{
    public const CODE_MALFORMED = 'FIN_HOLD_MALFORMED';

    public const CODE_NOT_FOUND = 'FIN_HOLD_NOT_FOUND';

    public const CODE_DUPLICATE = 'FIN_HOLD_DUPLICATE';

    public const CODE_INVALID_RELEASE = 'FIN_HOLD_INVALID_RELEASE';

    public const CODE_EXPIRED = 'FIN_HOLD_EXPIRED';

    public const CODE_AMOUNT_MISMATCH = 'FIN_HOLD_AMOUNT_MISMATCH';

    public const CODE_SOURCE_MISMATCH = 'FIN_HOLD_SOURCE_MISMATCH';

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
            sprintf('Financial hold refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Financial hold refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function duplicate(string $holdKey, array $context = []): self
    {
        return new self(
            sprintf('Financial hold refused: a different ask presented under key %s...', substr($holdKey, 0, 12)),
            self::CODE_DUPLICATE,
            $context + ['hold_key' => $holdKey],
        );
    }

    public static function invalidRelease(string $holdKey, string $status, array $context = []): self
    {
        return new self(
            sprintf('Financial hold refused: a [%s] row admits no release', $status),
            self::CODE_INVALID_RELEASE,
            $context + ['hold_key' => $holdKey, 'status' => $status],
        );
    }

    public static function expired(string $holdKey, array $context = []): self
    {
        return new self(
            sprintf('Financial hold refused: the hold %s... is already expired', substr($holdKey, 0, 12)),
            self::CODE_EXPIRED,
            $context + ['hold_key' => $holdKey],
        );
    }

    public static function amountMismatch(string $holdKey, string $expected, string $offered, array $context = []): self
    {
        return new self(
            sprintf('Financial hold refused: the row conserves %s, the ask says %s', $expected, $offered),
            self::CODE_AMOUNT_MISMATCH,
            $context + ['hold_key' => $holdKey, 'expected' => $expected, 'offered' => $offered],
        );
    }

    public static function sourceMismatch(string $holdKey, array $context = []): self
    {
        return new self(
            sprintf('Financial hold refused: the source %s... does not name this row', substr($holdKey, 0, 12)),
            self::CODE_SOURCE_MISMATCH,
            $context + ['hold_key' => $holdKey],
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
