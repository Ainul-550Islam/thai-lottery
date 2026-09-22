<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The payment-intent lane's pronounced refusals.
 *
 * - PAYMENT_INTENT_MALFORMED          grammar never accepted the ask.
 * - PAYMENT_INTENT_NOT_FOUND          a named intent missing from the paper.
 * - PAYMENT_INTENT_DUPLICATE          same key, DIFFERENT facts — a fork,
 *                                       cried loudly (replay itself is free).
 * - PAYMENT_INTENT_AMOUNT_MISMATCH    bound amounts disagree.
 * - PAYMENT_INTENT_CURRENCY_MISMATCH  bound currencies disagree.
 * - PAYMENT_INTENT_WALLET_MISMATCH    the ask names a wallet the user does
 *                                       not own — identity is derived
 *                                       server-side, never by client claim.
 * - PAYMENT_INTENT_INVALID_TRANSITION the lifecycle map refuses the ask.
 * - PAYMENT_INTENT_EXPIRED            the window closed; no provider
 *                                       evidence means the intent lapses.
 */
final class PaymentIntentException extends Exception
{
    public const CODE_MALFORMED = 'PAYMENT_INTENT_MALFORMED';

    public const CODE_NOT_FOUND = 'PAYMENT_INTENT_NOT_FOUND';

    public const CODE_DUPLICATE = 'PAYMENT_INTENT_DUPLICATE';

    public const CODE_AMOUNT_MISMATCH = 'PAYMENT_INTENT_AMOUNT_MISMATCH';

    public const CODE_CURRENCY_MISMATCH = 'PAYMENT_INTENT_CURRENCY_MISMATCH';

    public const CODE_WALLET_MISMATCH = 'PAYMENT_INTENT_WALLET_MISMATCH';

    public const CODE_INVALID_TRANSITION = 'PAYMENT_INTENT_INVALID_TRANSITION';

    public const CODE_EXPIRED = 'PAYMENT_INTENT_EXPIRED';

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
            sprintf('Payment intent refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $intentKey, array $context = []): self
    {
        return new self(
            sprintf('Payment intent refused: [%s] is not on the paper', $intentKey),
            self::CODE_NOT_FOUND,
            $context + ['intent_key' => $intentKey],
        );
    }

    public static function duplicate(string $idempotencyKey, array $context = []): self
    {
        return new self(
            sprintf('Payment intent refused: key [%s] already names a different fact — a fork, never a merge', $idempotencyKey),
            self::CODE_DUPLICATE,
            $context + ['idempotency_key' => $idempotencyKey],
        );
    }

    public static function amountMismatch(string $intentKey, string $expected, string $offered, array $context = []): self
    {
        return new self(
            sprintf('Payment intent refused: [%s] binds %s, the ask offered %s', $intentKey, $expected, $offered),
            self::CODE_AMOUNT_MISMATCH,
            $context + ['intent_key' => $intentKey, 'expected' => $expected, 'offered' => $offered],
        );
    }

    public static function currencyMismatch(string $intentKey, string $expected, string $offered, array $context = []): self
    {
        return new self(
            sprintf('Payment intent refused: [%s] speaks %s, the ask spoke %s', $intentKey, $expected, $offered),
            self::CODE_CURRENCY_MISMATCH,
            $context + ['intent_key' => $intentKey, 'expected' => $expected, 'offered' => $offered],
        );
    }

    public static function walletMismatch(int $userId, int $walletId, array $context = []): self
    {
        return new self(
            sprintf('Payment intent refused: wallet #%d is not held by user #%d — identity is derived, never claimed', $walletId, $userId),
            self::CODE_WALLET_MISMATCH,
            $context + ['user_id' => $userId, 'wallet_id' => $walletId],
        );
    }

    public static function invalidTransition(string $intentKey, string $from, string $to, array $context = []): self
    {
        return new self(
            sprintf('Payment intent refused: [%s] may not move %s → %s', $intentKey, $from, $to),
            self::CODE_INVALID_TRANSITION,
            $context + ['intent_key' => $intentKey, 'from' => $from, 'to' => $to],
        );
    }

    public static function expired(string $intentKey, array $context = []): self
    {
        return new self(
            sprintf('Payment intent refused: [%s] expired with no provider evidence — the window closed', $intentKey),
            self::CODE_EXPIRED,
            $context + ['intent_key' => $intentKey],
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
