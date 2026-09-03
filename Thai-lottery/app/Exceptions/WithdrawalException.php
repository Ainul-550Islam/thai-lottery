<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\Currency;
use App\Enums\WithdrawalStatus;
use Throwable;

/**
 * A withdrawal workflow rule was violated.
 *
 * DESIGN RULES OBSERVED
 * ---------------------
 * - Extends FinancialException.
 * - No HTTP status code, no rendering, no database query.
 * - Leaks nothing sensitive. In particular it NEVER carries `payout_details`,
 *   which is the encrypted beneficiary payload: putting it in an exception would
 *   push a bank account number straight into the log files. Only internal numeric
 *   ids, statuses, decimal strings and currency codes are ever attached.
 */
class WithdrawalException extends FinancialException
{
    public const ERROR_ALREADY_COMPLETED = 'withdrawal_already_completed';

    public const ERROR_NOT_APPROVABLE = 'withdrawal_not_approvable';

    public const ERROR_NOT_COMPLETABLE = 'withdrawal_not_completable';

    public const ERROR_NOT_REJECTABLE = 'withdrawal_not_rejectable';

    public const ERROR_NOT_CANCELLABLE = 'withdrawal_not_cancellable';

    public const ERROR_AMOUNT_OUT_OF_RANGE = 'withdrawal_amount_out_of_range';

    public const ERROR_CURRENCY_MISMATCH = 'withdrawal_currency_mismatch';

    public const ERROR_WALLET_NOT_DEBITABLE = 'withdrawal_wallet_not_debitable';

    public const ERROR_WALLET_MISMATCH = 'withdrawal_wallet_mismatch';

    public const ERROR_NO_RESERVATION = 'withdrawal_no_reservation';

    public const ERROR_RESERVATION_EXCEEDED = 'withdrawal_reservation_exceeded';

    public const ERROR_ALREADY_RESERVED = 'withdrawal_already_reserved';

    public const ERROR_NET_AMOUNT_INVALID = 'withdrawal_net_amount_invalid';

    public const ERROR_MISSING_TRANSACTION = 'withdrawal_missing_transaction';

    public const ERROR_INCONSISTENT_STATE = 'withdrawal_inconsistent_state';

    /**
     * The payout has already gone out; completing again would create money.
     */
    public static function alreadyCompleted(int $withdrawalId, ?int $financialTransactionId = null): self
    {
        return new self(
            sprintf('Withdrawal %d has already been completed.', $withdrawalId),
            self::ERROR_ALREADY_COMPLETED,
            array_filter([
                'withdrawal_id' => $withdrawalId,
                'financial_transaction_id' => $financialTransactionId,
            ], static fn ($value): bool => $value !== null),
        );
    }

    public static function notApprovable(int $withdrawalId, WithdrawalStatus $current): self
    {
        return new self(
            sprintf('Withdrawal %d cannot be approved from status "%s".', $withdrawalId, $current->value),
            self::ERROR_NOT_APPROVABLE,
            [
                'withdrawal_id' => $withdrawalId,
                'current_status' => $current->value,
            ],
        );
    }

    public static function notCompletable(int $withdrawalId, WithdrawalStatus $current): self
    {
        return new self(
            sprintf('Withdrawal %d cannot be completed from status "%s".', $withdrawalId, $current->value),
            self::ERROR_NOT_COMPLETABLE,
            [
                'withdrawal_id' => $withdrawalId,
                'current_status' => $current->value,
            ],
        );
    }

    public static function notRejectable(int $withdrawalId, WithdrawalStatus $current): self
    {
        return new self(
            sprintf('Withdrawal %d cannot be rejected from status "%s".', $withdrawalId, $current->value),
            self::ERROR_NOT_REJECTABLE,
            [
                'withdrawal_id' => $withdrawalId,
                'current_status' => $current->value,
            ],
        );
    }

    public static function notCancellable(int $withdrawalId, WithdrawalStatus $current): self
    {
        return new self(
            sprintf('Withdrawal %d cannot be cancelled from status "%s".', $withdrawalId, $current->value),
            self::ERROR_NOT_CANCELLABLE,
            [
                'withdrawal_id' => $withdrawalId,
                'current_status' => $current->value,
            ],
        );
    }

    public static function amountOutOfRange(string $amount, string $minimum, string $maximum, Currency $currency): self
    {
        return new self(
            sprintf(
                'Withdrawal amount %s %s is outside the permitted range %s - %s.',
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
     * The request currency differs from the wallet currency. Never converted.
     */
    public static function currencyMismatch(int $walletId, Currency $walletCurrency, Currency $requested): self
    {
        return new self(
            sprintf(
                'Withdrawal currency %s does not match wallet currency %s. Implicit conversion is not permitted.',
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

    public static function walletNotDebitable(int $walletId, string $walletStatus): self
    {
        return new self(
            sprintf('Wallet %d cannot fund a withdrawal while its status is "%s".', $walletId, $walletStatus),
            self::ERROR_WALLET_NOT_DEBITABLE,
            [
                'wallet_id' => $walletId,
                'wallet_status' => $walletStatus,
            ],
        );
    }

    public static function walletMismatch(int $withdrawalId, int $expectedWalletId, int $givenWalletId): self
    {
        return new self(
            sprintf('Withdrawal %d belongs to wallet %d, not wallet %d.', $withdrawalId, $expectedWalletId, $givenWalletId),
            self::ERROR_WALLET_MISMATCH,
            [
                'withdrawal_id' => $withdrawalId,
                'expected_wallet_id' => $expectedWalletId,
                'given_wallet_id' => $givenWalletId,
            ],
        );
    }

    /**
     * The payout was asked to settle without funds ever having been reserved.
     */
    public static function noReservation(int $withdrawalId, int $walletId, string $required, string $locked): self
    {
        return new self(
            sprintf(
                'Withdrawal %d has no sufficient reservation on wallet %d: %s required, %s reserved.',
                $withdrawalId,
                $walletId,
                $required,
                $locked,
            ),
            self::ERROR_NO_RESERVATION,
            [
                'withdrawal_id' => $withdrawalId,
                'wallet_id' => $walletId,
                'required_amount' => $required,
                'locked_amount' => $locked,
            ],
        );
    }

    /**
     * A settlement tried to consume more than the request had reserved.
     */
    public static function reservationExceeded(int $withdrawalId, string $requested, string $reserved): self
    {
        return new self(
            sprintf(
                'Withdrawal %d cannot consume %s because only %s was reserved.',
                $withdrawalId,
                $requested,
                $reserved,
            ),
            self::ERROR_RESERVATION_EXCEEDED,
            [
                'withdrawal_id' => $withdrawalId,
                'requested_amount' => $requested,
                'reserved_amount' => $reserved,
            ],
        );
    }

    /**
     * The request already holds a reservation, so reserving again would lock the
     * amount twice.
     */
    public static function alreadyReserved(int $withdrawalId, WithdrawalStatus $current): self
    {
        return new self(
            sprintf('Withdrawal %d already holds a reservation (status "%s").', $withdrawalId, $current->value),
            self::ERROR_ALREADY_RESERVED,
            [
                'withdrawal_id' => $withdrawalId,
                'current_status' => $current->value,
            ],
        );
    }

    public static function netAmountInvalid(string $amount, string $fee, string $netAmount): self
    {
        return new self(
            sprintf('Withdrawal net amount %s is invalid for amount %s and fee %s.', $netAmount, $amount, $fee),
            self::ERROR_NET_AMOUNT_INVALID,
            [
                'amount' => $amount,
                'fee' => $fee,
                'net_amount' => $netAmount,
            ],
        );
    }

    public static function missingTransaction(int $withdrawalId): self
    {
        return new self(
            sprintf('Withdrawal %d has no linked financial transaction.', $withdrawalId),
            self::ERROR_MISSING_TRANSACTION,
            ['withdrawal_id' => $withdrawalId],
        );
    }

    public static function inconsistentState(int $withdrawalId, string $detail): self
    {
        return new self(
            sprintf('Withdrawal %d is in an inconsistent state: %s', $withdrawalId, $detail),
            self::ERROR_INCONSISTENT_STATE,
            [
                'withdrawal_id' => $withdrawalId,
                'detail' => $detail,
            ],
        );
    }

    /**
     * @param  array<string, string|int|float|bool|null>  $context
     */
    public static function withReason(string $message, string $errorCode, array $context = [], ?Throwable $previous = null): self
    {
        return new self($message, $errorCode, $context, $previous);
    }
}
