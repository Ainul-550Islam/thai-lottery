<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use Throwable;

/**
 * A deposit workflow rule was violated.
 *
 * DESIGN RULES OBSERVED
 * ---------------------
 * - Extends FinancialException, so a caller can catch every finance failure as
 *   one type and still branch on this subclass.
 * - Carries NO HTTP status code and no rendering logic. Whether this becomes a
 *   422 or a 409 is a transport decision that belongs to a later phase.
 * - Runs NO database query. An exception must be constructible while a
 *   transaction is rolling back, so it only ever stores scalars it was handed.
 * - Leaks no sensitive data. No payout details, no provider credentials, no
 *   customer identity beyond an internal numeric id.
 *
 * Each named constructor carries a stable error code so calling code and log
 * dashboards can branch on a string that will not change when wording changes.
 */
class DepositException extends FinancialException
{
    public const ERROR_ALREADY_CREDITED = 'deposit_already_credited';

    public const ERROR_NOT_APPROVABLE = 'deposit_not_approvable';

    public const ERROR_NOT_COMPLETABLE = 'deposit_not_completable';

    public const ERROR_NOT_REJECTABLE = 'deposit_not_rejectable';

    public const ERROR_AMOUNT_OUT_OF_RANGE = 'deposit_amount_out_of_range';

    public const ERROR_CURRENCY_MISMATCH = 'deposit_currency_mismatch';

    public const ERROR_WALLET_NOT_CREDITABLE = 'deposit_wallet_not_creditable';

    public const ERROR_WALLET_MISMATCH = 'deposit_wallet_mismatch';

    public const ERROR_NET_AMOUNT_INVALID = 'deposit_net_amount_invalid';

    public const ERROR_MISSING_TRANSACTION = 'deposit_missing_transaction';

    public const ERROR_INCONSISTENT_STATE = 'deposit_inconsistent_state';

    /**
     * The deposit has already reached the wallet, so it must not be credited again.
     */
    public static function alreadyCredited(int $depositId, ?int $financialTransactionId = null): self
    {
        return new self(
            sprintf('Deposit %d has already been credited to its wallet.', $depositId),
            self::ERROR_ALREADY_CREDITED,
            array_filter([
                'deposit_id' => $depositId,
                'financial_transaction_id' => $financialTransactionId,
            ], static fn ($value): bool => $value !== null),
        );
    }

    /**
     * The current status does not permit approval.
     */
    public static function notApprovable(int $depositId, DepositStatus $current): self
    {
        return new self(
            sprintf('Deposit %d cannot be approved from status "%s".', $depositId, $current->value),
            self::ERROR_NOT_APPROVABLE,
            [
                'deposit_id' => $depositId,
                'current_status' => $current->value,
            ],
        );
    }

    /**
     * The current status does not permit settlement into the wallet.
     */
    public static function notCompletable(int $depositId, DepositStatus $current): self
    {
        return new self(
            sprintf('Deposit %d cannot be completed from status "%s".', $depositId, $current->value),
            self::ERROR_NOT_COMPLETABLE,
            [
                'deposit_id' => $depositId,
                'current_status' => $current->value,
            ],
        );
    }

    /**
     * The current status does not permit rejection.
     */
    public static function notRejectable(int $depositId, DepositStatus $current): self
    {
        return new self(
            sprintf('Deposit %d cannot be rejected from status "%s".', $depositId, $current->value),
            self::ERROR_NOT_REJECTABLE,
            [
                'deposit_id' => $depositId,
                'current_status' => $current->value,
            ],
        );
    }

    /**
     * The requested amount falls outside the configured deposit limits.
     */
    public static function amountOutOfRange(string $amount, string $minimum, string $maximum, Currency $currency): self
    {
        return new self(
            sprintf(
                'Deposit amount %s %s is outside the permitted range %s - %s.',
                $amount,
                $currency->value,
                $minimum,
                $maximum,
            ),
            self::ERROR_AMOUNT_OUT_OF_RANGE,
            [
                'amount' => $amount,
                'minimum' => $minimum,
                'maximum' => $maximum,
                'currency' => $currency->value,
            ],
        );
    }

    /**
     * The request currency differs from the wallet currency.
     *
     * Never converted implicitly: a silent conversion would invent an exchange
     * rate and corrupt the ledger.
     */
    public static function currencyMismatch(int $walletId, Currency $walletCurrency, Currency $requested): self
    {
        return new self(
            sprintf(
                'Deposit currency %s does not match wallet currency %s. Implicit conversion is not permitted.',
                $requested->value,
                $walletCurrency->value,
            ),
            self::ERROR_CURRENCY_MISMATCH,
            [
                'wallet_id' => $walletId,
                'wallet_currency' => $walletCurrency->value,
                'requested_currency' => $requested->value,
            ],
        );
    }

    /**
     * The wallet's own status forbids receiving money.
     */
    public static function walletNotCreditable(int $walletId, string $walletStatus): self
    {
        return new self(
            sprintf('Wallet %d cannot receive a deposit while its status is "%s".', $walletId, $walletStatus),
            self::ERROR_WALLET_NOT_CREDITABLE,
            [
                'wallet_id' => $walletId,
                'wallet_status' => $walletStatus,
            ],
        );
    }

    /**
     * The wallet handed in is not the wallet the deposit belongs to.
     */
    public static function walletMismatch(int $depositId, int $expectedWalletId, int $givenWalletId): self
    {
        return new self(
            sprintf('Deposit %d belongs to wallet %d, not wallet %d.', $depositId, $expectedWalletId, $givenWalletId),
            self::ERROR_WALLET_MISMATCH,
            [
                'deposit_id' => $depositId,
                'expected_wallet_id' => $expectedWalletId,
                'given_wallet_id' => $givenWalletId,
            ],
        );
    }

    /**
     * amount - fee did not produce a usable net amount.
     */
    public static function netAmountInvalid(string $amount, string $fee, string $netAmount): self
    {
        return new self(
            sprintf('Deposit net amount %s is invalid for amount %s and fee %s.', $netAmount, $amount, $fee),
            self::ERROR_NET_AMOUNT_INVALID,
            [
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
            ],
        );
    }

    /**
     * The engine returned no financial transaction where one was required.
     */
    public static function missingTransaction(int $depositId): self
    {
        return new self(
            sprintf('Deposit %d has no linked financial transaction.', $depositId),
            self::ERROR_MISSING_TRANSACTION,
            ['deposit_id' => $depositId],
        );
    }

    /**
     * The stored row contradicts itself, e.g. confirmed with no transaction link.
     */
    public static function inconsistentState(int $depositId, string $detail): self
    {
        return new self(
            sprintf('Deposit %d is in an inconsistent state: %s', $depositId, $detail),
            self::ERROR_INCONSISTENT_STATE,
            [
                'deposit_id' => $depositId,
                'detail' => $detail,
            ],
        );
    }

    /**
     * Generic escape hatch that still records a stable code.
     *
     * @param  array<string, string|int|float|bool|null>  $context
     */
    public static function withReason(string $message, string $errorCode, array $context = [], ?Throwable $previous = null): self
    {
        return new self($message, $errorCode, $context, $previous);
    }
}
