<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The ledger-adjustment lane's pronounced refusals.
 *
 * - LEDGER_ADJ_MALFORMED               grammar never accepted the ask.
 * - LEDGER_ADJ_NOT_FOUND               named wallet/operator missing.
 * - LEDGER_ADJ_UNAUTHORIZED            the operator isn't allowed to
 *                                      correct money.
 * - LEDGER_ADJ_MISSING_EVIDENCE        evidence token couldn't produce
 *                                      the audit-opener grammar (never
 *                                      invent authority).
 * - LEDGER_ADJ_DUPLICATE               different ask under the same key —
 *                                      fork.
 * - LEDGER_ADJ_CONSERVATION_VIOLATION  the wallet/ledger invariant
 *                                      cannot be made to hold — refused,
 *                                      never rewritten.
 */
final class LedgerAdjustmentException extends Exception
{
    public const CODE_MALFORMED = 'LEDGER_ADJ_MALFORMED';

    public const CODE_NOT_FOUND = 'LEDGER_ADJ_NOT_FOUND';

    public const CODE_UNAUTHORIZED = 'LEDGER_ADJ_UNAUTHORIZED';

    public const CODE_MISSING_EVIDENCE = 'LEDGER_ADJ_MISSING_EVIDENCE';

    public const CODE_DUPLICATE = 'LEDGER_ADJ_DUPLICATE';

    public const CODE_CONSERVATION_VIOLATION = 'LEDGER_ADJ_CONSERVATION_VIOLATION';

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
            sprintf('Ledger adjustment refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Ledger adjustment refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function unauthorized(int $operatorUserId, array $context = []): self
    {
        return new self(
            sprintf('Ledger adjustment refused: operator #%d has no authority over money correction', $operatorUserId),
            self::CODE_UNAUTHORIZED,
            $context + ['operator_user_id' => $operatorUserId],
        );
    }

    public static function missingEvidence(string $sourceEvidence, array $context = []): self
    {
        return new self(
            sprintf('Ledger adjustment refused: the evidence token %s... does not produce audit-opener grammar', substr($sourceEvidence, 0, 12)),
            self::CODE_MISSING_EVIDENCE,
            $context + ['source_evidence' => $sourceEvidence],
        );
    }

    public static function duplicate(string $adjustmentKey, array $context = []): self
    {
        return new self(
            sprintf('Ledger adjustment refused: a different ask presented under key %s...', substr($adjustmentKey, 0, 12)),
            self::CODE_DUPLICATE,
            $context + ['adjustment_key' => $adjustmentKey],
        );
    }

    public static function conservationViolation(int $walletId, string $detail, array $context = []): self
    {
        return new self(
            sprintf('Ledger adjustment refused: conservation cannot be maintained on wallet #%d (%s)', $walletId, $detail),
            self::CODE_CONSERVATION_VIOLATION,
            $context + ['wallet_id' => $walletId, 'detail' => $detail],
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
