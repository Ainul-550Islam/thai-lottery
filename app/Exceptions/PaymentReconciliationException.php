<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The provider-vs-internal reconciliation lane's pronounced refusals.
 *
 * Routine drift is EVIDENCE (rows + lines), never an exception. The
 * exceptions are the fail-closed vocabulary for callers using
 * assertConsistent() and for irrecoverable lookup faults:
 *
 * - PAYMENT_RECON_MALFORMED         grammar never accepted the ask.
 * - PAYMENT_RECON_NOT_FOUND         a named payment/reference missing.
 * - PAYMENT_RECON_MISSING_TRANSACTION the provider's evidence names a
 *                                       transaction with NO internal paper.
 * - PAYMENT_RECON_AMOUNT_MISMATCH   observed ≠ internal, by exact decimal.
 * - PAYMENT_RECON_STATUS_MISMATCH   observed state and internal state
 *                                       disagree on WHERE the money is.
 * - PAYMENT_RECON_DUPLICATE         same key, different facts — a fork.
 */
final class PaymentReconciliationException extends Exception
{
    public const CODE_MALFORMED = 'PAYMENT_RECON_MALFORMED';

    public const CODE_NOT_FOUND = 'PAYMENT_RECON_NOT_FOUND';

    public const CODE_MISSING_TRANSACTION = 'PAYMENT_RECON_MISSING_TRANSACTION';

    public const CODE_AMOUNT_MISMATCH = 'PAYMENT_RECON_AMOUNT_MISMATCH';

    public const CODE_STATUS_MISMATCH = 'PAYMENT_RECON_STATUS_MISMATCH';

    public const CODE_DUPLICATE = 'PAYMENT_RECON_DUPLICATE';

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
            sprintf('Payment reconciliation refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Payment reconciliation refused: [%s] is not on either side of the paper', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function missingTransaction(string $provider, string $externalReference, array $context = []): self
    {
        return new self(
            sprintf('Payment reconciliation refused: [%s] names reference [%s], but the house has no paper by that name', $provider, $externalReference),
            self::CODE_MISSING_TRANSACTION,
            $context + ['provider' => $provider, 'external_reference' => $externalReference],
        );
    }

    public static function amountMismatch(string $reference, string $expected, string $observed, string $currency, array $context = []): self
    {
        return new self(
            sprintf('Payment reconciliation refused: [%s] internal paper says %s %s, provider evidence says %s', $reference, $expected, $currency, $observed),
            self::CODE_AMOUNT_MISMATCH,
            $context + ['reference' => $reference, 'expected' => $expected, 'observed' => $observed, 'currency' => $currency],
        );
    }

    public static function statusMismatch(string $reference, string $internal, string $observed, array $context = []): self
    {
        return new self(
            sprintf('Payment reconciliation refused: [%s] internal paper says [%s], provider evidence says [%s]', $reference, $internal, $observed),
            self::CODE_STATUS_MISMATCH,
            $context + ['reference' => $reference, 'internal' => $internal, 'observed' => $observed],
        );
    }

    public static function duplicate(string $reconciliationKey, array $context = []): self
    {
        return new self(
            sprintf('Payment reconciliation refused: key [%s] already names a different fact — a fork', substr($reconciliationKey, 0, 16).'…'),
            self::CODE_DUPLICATE,
            $context + ['reconciliation_key_prefix' => substr($reconciliationKey, 0, 16)],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->errorContext;
    }
}
