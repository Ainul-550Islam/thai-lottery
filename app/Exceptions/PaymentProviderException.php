<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The provider registry's pronounced refusals.
 *
 * - PAYMENT_PROVIDER_MALFORMED          grammar never accepted the ask.
 * - PAYMENT_PROVIDER_NOT_FOUND          a named provider/method missing.
 * - PAYMENT_PROVIDER_UNAVAILABLE        a named fact exists but may not
 *                                       serve traffic (pending, suspended,
 *                                       disabled — or a retired method).
 * - PAYMENT_PROVIDER_SUSPENDED          traffic was attempted through a
 *                                       suspended provider by name.
 * - PAYMENT_PROVIDER_DISABLED           traffic was attempted through a
 *                                       disabled provider by name.
 * - PAYMENT_PROVIDER_INVALID_TRANSITION the lifecycle map refuses the ask.
 * - PAYMENT_PROVIDER_DUPLICATE          a second registration under the
 *                                       same identity.
 * - PAYMENT_PROVIDER_SECRET_FORBIDDEN   a caller tried to store or read
 *                                       credentials through the registry —
 *                                       the lane has no home for secrets.
 */
final class PaymentProviderException extends Exception
{
    public const CODE_MALFORMED = 'PAYMENT_PROVIDER_MALFORMED';

    public const CODE_NOT_FOUND = 'PAYMENT_PROVIDER_NOT_FOUND';

    public const CODE_UNAVAILABLE = 'PAYMENT_PROVIDER_UNAVAILABLE';

    public const CODE_SUSPENDED = 'PAYMENT_PROVIDER_SUSPENDED';

    public const CODE_DISABLED = 'PAYMENT_PROVIDER_DISABLED';

    public const CODE_INVALID_TRANSITION = 'PAYMENT_PROVIDER_INVALID_TRANSITION';

    public const CODE_DUPLICATE = 'PAYMENT_PROVIDER_DUPLICATE';

    public const CODE_SECRET_FORBIDDEN = 'PAYMENT_PROVIDER_SECRET_FORBIDDEN';

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
            sprintf('Payment provider refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Payment provider refused: [%s] is not in the registry', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function unavailable(string $reference, string $why, array $context = []): self
    {
        return new self(
            sprintf('Payment provider refused: [%s] may not serve traffic — %s', $reference, $why),
            self::CODE_UNAVAILABLE,
            $context + ['reference' => $reference, 'why' => $why],
        );
    }

    public static function suspended(string $code, array $context = []): self
    {
        return new self(
            sprintf('Payment provider refused: [%s] is suspended — the clerk\'s own red light is lit', $code),
            self::CODE_SUSPENDED,
            $context + ['provider' => $code],
        );
    }

    public static function disabled(string $code, array $context = []): self
    {
        return new self(
            sprintf('Payment provider refused: [%s] is struck off the books', $code),
            self::CODE_DISABLED,
            $context + ['provider' => $code],
        );
    }

    public static function invalidTransition(string $reference, string $from, string $to, array $context = []): self
    {
        return new self(
            sprintf('Payment provider refused: [%s] may not move %s → %s', $reference, $from, $to),
            self::CODE_INVALID_TRANSITION,
            $context + ['reference' => $reference, 'from' => $from, 'to' => $to],
        );
    }

    public static function duplicate(string $code, array $context = []): self
    {
        return new self(
            sprintf('Payment provider refused: [%s] is already registered', $code),
            self::CODE_DUPLICATE,
            $context + ['provider' => $code],
        );
    }

    public static function secretForbidden(string $key, array $context = []): self
    {
        return new self(
            sprintf('Payment provider refused: [%s] smells like a secret — the registry stores none, ever', $key),
            self::CODE_SECRET_FORBIDDEN,
            $context + ['key' => $key],
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
