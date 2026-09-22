<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A failed PAYMENT verification.
 *
 * Thrown when the system is asked to prove that a payment the player claims
 * to have made (a PromptPay scan, a bKash peer push, a bank transfer) is the
 * payment that actually arrived — by reference, amount, currency and source —
 * and cannot prove it: the reference is unknown, the amount disagrees, the
 * payment is in a state where money is not yet available, or the proof itself
 * was already consumed by another claim.
 *
 * WHY NOT FinancialException
 * Verification failures are the PRODUCT surface a player or support operator
 * sees ("we cannot recognize your payment"), and they must be classifiable
 * without parsing the finance engine's internal invariants. A verification
 * that reaches a wrong-inner-invariant (unbalanced ledger etc.) is still
 * thrown as FinancialException by the engine and never translated into a
 * player-facing "payment not found" — this class is exactly the wrapper that
 * keeps the two vocabularies apart.
 *
 * SECURITY: context carries ids, references and decimal amounts. Never a
 * credential, secret, signature or personal datum.
 */
class PaymentVerificationException extends RuntimeException
{
    public const CODE_REFERENCE_UNKNOWN = 'PAYMENT_REFERENCE_UNKNOWN';

    public const CODE_AMOUNT_MISMATCH = 'PAYMENT_AMOUNT_MISMATCH';

    public const CODE_CURRENCY_MISMATCH = 'PAYMENT_CURRENCY_MISMATCH';

    public const CODE_NOT_SETTLEABLE = 'PAYMENT_NOT_SETTLEABLE';

    public const CODE_ALREADY_CONSUMED = 'PAYMENT_ALREADY_CONSUMED';

    public const CODE_PROOF_INVALID = 'PAYMENT_PROOF_INVALID';

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
     * The reference the claim names does not map to any recorded payment.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function referenceUnknown(string $reference, string $method, array $context = []): self
    {
        return new self(
            sprintf('No %s payment carries reference [%s]; the claim cannot be recognized.', $method, $reference),
            self::CODE_REFERENCE_UNKNOWN,
            $context + ['reference' => $reference, 'method' => $method],
        );
    }

    /**
     * The found payment settles a different amount than claimed. Paying
     * either number would be wrong; the pair is reported.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function amountMismatch(string $reference, string $expected, string $actual, array $context = []): self
    {
        return new self(
            sprintf(
                'Payment [%s] is recorded for %s but the claim asserts %s; the mismatch is reported, never reconciled silently.',
                $reference,
                $actual,
                $expected,
            ),
            self::CODE_AMOUNT_MISMATCH,
            $context + [
                'reference' => $reference,
                'expected_amount' => $expected,
                'actual_amount' => $actual,
            ],
        );
    }

    /**
     * The found payment is denominated in another currency than claimed.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function currencyMismatch(string $reference, string $expectedCurrency, string $actualCurrency, array $context = []): self
    {
        return new self(
            sprintf(
                'Payment [%s] settles in %s but the claim is made in %s; cross-currency verification is refused.',
                $reference,
                $actualCurrency,
                $expectedCurrency,
            ),
            self::CODE_CURRENCY_MISMATCH,
            $context + [
                'reference' => $reference,
                'expected_currency' => $expectedCurrency,
                'actual_currency' => $actualCurrency,
            ],
        );
    }

    /**
     * The payment exists but its state says the money is not freely usable
     * yet (pending, reversed, disputed, refunded).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notSettleable(string $reference, string $status, array $context = []): self
    {
        return new self(
            sprintf('Payment [%s] is %s and its money is not settleable right now.', $reference, $status),
            self::CODE_NOT_SETTLEABLE,
            $context + ['reference' => $reference, 'payment_status' => $status],
        );
    }

    /**
     * The payment's verification proof was already consumed by an earlier
     * verification/consumption: one payment mints credit exactly once.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyConsumed(string $reference, int $consumerDepositId, array $context = []): self
    {
        return new self(
            sprintf(
                'Payment [%s] was already consumed by deposit #%d; consuming it again would mint the same money twice.',
                $reference,
                $consumerDepositId,
            ),
            self::CODE_ALREADY_CONSUMED,
            $context + ['reference' => $reference, 'consumer_deposit_id' => $consumerDepositId],
        );
    }

    /**
     * The player-supplied proof (transaction id, slip reference, QR payload
     * reference) fails the format or integrity checks for its payment method.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function proofInvalid(string $method, string $reason, array $context = []): self
    {
        return new self(
            sprintf('The %s proof supplied is invalid: %s.', $method, $reason),
            self::CODE_PROOF_INVALID,
            $context + ['method' => $method, 'reason' => $reason],
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
