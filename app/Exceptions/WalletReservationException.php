<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The money-reservation lane's pronounced refusals.
 *
 * - WALLET_RESV_MALFORMED               grammar never accepted the ask.
 * - WALLET_RESV_NOT_FOUND               named wallet/reservation missing.
 * - WALLET_RESV_INSUFFICIENT            available can't carry the ask.
 * - WALLET_RESV_DUPLICATE               different ask under the same key —
 *                                       fork.
 * - WALLET_RESV_INVALID_TRANSITION      the lifecycle map refuses the ask.
 * - WALLET_RESV_CONSERVATION_VIOLATION  the wallet's own totals can't be
 *                                       made to hold — the lane never
 *                                       invents arithmetic.
 */
final class WalletReservationException extends Exception
{
    public const CODE_MALFORMED = 'WALLET_RESV_MALFORMED';

    public const CODE_NOT_FOUND = 'WALLET_RESV_NOT_FOUND';

    public const CODE_INSUFFICIENT = 'WALLET_RESV_INSUFFICIENT';

    public const CODE_DUPLICATE = 'WALLET_RESV_DUPLICATE';

    public const CODE_INVALID_TRANSITION = 'WALLET_RESV_INVALID_TRANSITION';

    public const CODE_CONSERVATION_VIOLATION = 'WALLET_RESV_CONSERVATION_VIOLATION';

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
            sprintf('Wallet reservation refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Wallet reservation refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function insufficient(int $walletId, string $asked, string $available, array $context = []): self
    {
        return new self(
            sprintf('Wallet reservation refused: wallet #%d offers %s, the ask wanted %s', $walletId, $available, $asked),
            self::CODE_INSUFFICIENT,
            $context + ['wallet_id' => $walletId, 'asked' => $asked, 'available' => $available],
        );
    }

    public static function duplicate(string $reservationKey, array $context = []): self
    {
        return new self(
            sprintf('Wallet reservation refused: a different ask presented under key %s...', substr($reservationKey, 0, 12)),
            self::CODE_DUPLICATE,
            $context + ['reservation_key' => $reservationKey],
        );
    }

    public static function invalidTransition(string $reservationKey, string $current, string $asked, array $context = []): self
    {
        return new self(
            sprintf('Wallet reservation refused: row is in [%s], which forbids [%s]', $current, $asked),
            self::CODE_INVALID_TRANSITION,
            $context + ['reservation_key' => $reservationKey, 'current' => $current, 'asked' => $asked],
        );
    }

    public static function conservationViolation(int $walletId, string $detail, array $context = []): self
    {
        return new self(
            sprintf('Wallet reservation refused: conservation cannot be maintained on wallet #%d (%s)', $walletId, $detail),
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
