<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused or broken PAYOUT DOCUMENT (statement) step.
 *
 * COVERAGE
 * --------
 * Everything the statement lane may refuse: generating against money that
 * never completed (the statement is fact ABOUT completion — fabricating it
 * early is the same as forging it), a money inconsistency in the source
 * rows (gross ≠ tax + net: the statement's own equation broken upstream),
 * a duplicate document identity (anchors are derived, so a duplicate can
 * only arrive with different content — the forbidden case), or a state-
 * machine step the lifecycle forbids (issuing from Cancelled, archiving
 * pre-Issued paper).
 *
 * WHAT IT IS NOT
 * --------------
 * TaxCalculationException is the arithmetic lane. This is the PAPER lane:
 * a mistake here means paper that cannot lawfully exist, and the severity
 * of paper is different from the severity of arithmetic. Callers catch
 * each separately.
 *
 * Context: document keys, payout references, statuses, decimal strings.
 * Never PII payloads, credentials, stacks or SQL.
 */
class PayoutDocumentException extends RuntimeException
{
    public const CODE_MONEY_OPERAND_MISSING = 'PAYOUT_DOCUMENT_MONEY_OPERAND_MISSING';

    public const CODE_MONEY_INCONSISTENT = 'PAYOUT_DOCUMENT_MONEY_INCONSISTENT';

    public const CODE_PAYOUT_NOT_SETTLED = 'PAYOUT_DOCUMENT_PAYOUT_NOT_SETTLED';

    public const CODE_DUPLICATE_DOCUMENT = 'PAYOUT_DOCUMENT_DUPLICATE_DOCUMENT';

    public const CODE_STATE_FORBIDS = 'PAYOUT_DOCUMENT_STATE_FORBIDS';

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
     * A money operand the statement must cite is missing from the source
     * rows entirely (e.g. the payout never had its net stamped).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function moneyOperandMissing(string $payoutReference, string $operand, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout statement for [%s] cannot be built: operand [%s] is missing from the source rows; guessing a money number is worse than no paper.',
                $payoutReference,
                $operand,
            ),
            self::CODE_MONEY_OPERAND_MISSING,
            $context + ['payout_reference' => $payoutReference, 'operand' => $operand],
        );
    }

    /**
     * gross ≠ tax + net on the source rows. The statement would notarize
     * broken arithmetic.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function moneyInconsistent(
        string $payoutReference,
        string $gross,
        string $tax,
        string $net,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Payout statement for [%s]: gross %s ≠ tax %s + net %s on the source rows; the paper cannot exist until the ledger is reconciled.',
                $payoutReference,
                $gross,
                $tax,
                $net,
            ),
            self::CODE_MONEY_INCONSISTENT,
            $context + [
                'payout_reference' => $payoutReference,
                'gross' => $gross,
                'tax' => $tax,
                'net' => $net,
            ],
        );
    }

    /**
     * The payout never settled. The statement is fact about completion.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function payoutNotSettled(string $payoutReference, string $status, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout statement for [%s]: payout is %s, not completed; a statement is a completion document and cannot be manufactured ahead of the money.',
                $payoutReference,
                $status,
            ),
            self::CODE_PAYOUT_NOT_SETTLED,
            $context + ['payout_reference' => $payoutReference, 'payout_status' => $status],
        );
    }

    /**
     * The document key already exists with DIFFERENT content. Derived keys
     * make an identical-content duplicate a replay (joined silently by the
     * service); only different content on one key is thrown here.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicateDocument(string $documentKey, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout statement [%s] already exists with different content; replacing issued fact silently is refused.',
                $documentKey,
            ),
            self::CODE_DUPLICATE_DOCUMENT,
            $context + ['document_key' => $documentKey],
        );
    }

    /**
     * The document lifecycle forbids the step.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function stateForbids(string $documentKey, string $current, string $attempted, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout statement [%s]: %s → %s is not a lawful step of the document lifecycle.',
                $documentKey,
                $current,
                $attempted,
            ),
            self::CODE_STATE_FORBIDS,
            $context + ['document_key' => $documentKey, 'current' => $current, 'attempted' => $attempted],
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
