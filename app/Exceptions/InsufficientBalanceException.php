<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Currency;
use Throwable;

/**
 * A wallet was asked to release more money than it has available.
 *
 * Thrown by the wallet service when a debit is attempted against an available
 * balance (balance minus locked_balance) that is smaller than the requested
 * amount. Raising this exception is how the engine guarantees a wallet can never
 * reach a negative available balance.
 *
 * The balance comparison itself is NOT performed here. The wallet service does
 * the comparison with exact decimal arithmetic while holding a row lock on the
 * wallet, and this exception only reports the already-decided outcome. An
 * exception that recomputed the balance would read state outside the lock and
 * could report a different number from the one that caused the failure.
 *
 * Amounts are carried as exact decimal strings, never floats, so the reported
 * shortfall matches the ledger to the cent.
 */
final class InsufficientBalanceException extends FinancialException
{
    public const ERROR_CODE = 'insufficient_balance';

    public function __construct(
        private readonly int $walletId,
        private readonly string $requestedAmount,
        private readonly string $availableAmount,
        private readonly Currency $currency,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Wallet %d has %s %s available, which is less than the requested %s %s.',
                $walletId,
                $currency->value,
                $availableAmount,
                $currency->value,
                $requestedAmount,
            ),
            self::ERROR_CODE,
            [
                'wallet_id' => $walletId,
                'currency' => $currency->value,
                'requested_amount' => $requestedAmount,
                'available_amount' => $availableAmount,
            ],
            $previous,
        );
    }

    public function walletId(): int
    {
        return $this->walletId;
    }

    /**
     * The debit that was refused, as an exact decimal string.
     */
    public function requestedAmount(): string
    {
        return $this->requestedAmount;
    }

    /**
     * The spendable balance at the moment of refusal, as an exact decimal string.
     */
    public function availableAmount(): string
    {
        return $this->availableAmount;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * How much was missing, computed with bcmath so the value is exact.
     *
     * Guarded because the shortfall is only meaningful when bcmath is present;
     * without it the engine refuses to do money arithmetic at all.
     */
    public function shortfall(): string
    {
        if (! extension_loaded('bcmath')) {
            return $this->requestedAmount;
        }

        return bcsub($this->requestedAmount, $this->availableAmount, $this->currency->scale());
    }
}
