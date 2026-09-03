<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Wallet;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Wallet balance operations.
 *
 * PUBLIC CONTRACT
 * credit(), debit(), lockFunds(), unlockFunds(), lockWallet(), unlockWallet().
 * Nothing here can set a balance to an arbitrary value: there is no setBalance()
 * and no forceBalance(). Every balance change is expressed as a movement of a
 * positive amount in a known direction, which is what makes the wallet
 * reconcilable against the ledger. A correction is made with an adjustment
 * transaction or a reversal, both of which produce their own ledger entries.
 *
 * NO SILENT MUTATION
 * credit() and debit() do not touch the database themselves. They delegate to
 * FinancialTransactionService, which owns the atomic transaction, the idempotency
 * claim, the row lock and the ledger posting. The balance is only ever moved by
 * applyCredit()/applyDebit(), which are internal, require an already locked
 * wallet, and require an open transaction. So a wallet balance cannot change
 * without a matching balanced double-entry posting.
 *
 * CIRCULAR DEPENDENCY
 * FinancialTransactionService also depends on this class. The container is
 * injected instead of the service so the orchestrator is resolved lazily at call
 * time, which avoids an infinite resolution loop while keeping both classes
 * container-managed.
 *
 * ACCOUNTING VIEW
 * A player wallet is a liability of the operator: money held on behalf of a
 * player. So the player-liability account is credited when the wallet grows and
 * debited when it shrinks, and the counterpart account depends on why the money
 * moved.
 */
final class WalletService
{
    /** System cash / payment-processor asset. */
    public const ACCOUNT_SYSTEM_CASH = '1000';

    /** Funds committed to a pending withdrawal. */
    public const ACCOUNT_WITHDRAWAL_CLEARING = '1100';

    /** Player balances owed by the operator. */
    public const ACCOUNT_PLAYER_LIABILITY = '2000';

    /** Retained earnings / owner's equity, used for adjustments. */
    public const ACCOUNT_ADJUSTMENT_EQUITY = '3000';

    /** Stakes recognised as revenue. */
    public const ACCOUNT_BET_REVENUE = '4000';

    /** Fees recognised as revenue. */
    public const ACCOUNT_FEE_REVENUE = '4100';

    /** Prizes paid to players. */
    public const ACCOUNT_PRIZE_EXPENSE = '5000';

    /** Commission paid to agents. */
    public const ACCOUNT_COMMISSION_EXPENSE = '5100';

    public function __construct(
        private readonly WalletLockService $locks,
        private readonly Container $container,
    ) {
    }

    /**
     * Move money into a wallet, atomically and with a ledger posting.
     *
     * @param  array<string, mixed>  $options  description, metadata, reference_type,
     *                                        reference_id, fee, actor_user_id
     *
     * @throws FinancialException
     */
    public function credit(
        Wallet $wallet,
        Money $amount,
        FinancialTransactionType $type = FinancialTransactionType::Deposit,
        ?string $idempotencyKey = null,
        array $options = [],
    ): \App\Models\FinancialTransaction {
        return $this->transactions()->execute(
            wallet: $wallet,
            amount: $amount,
            type: $type,
            walletSide: LedgerEntryType::Credit,
            idempotencyKey: $idempotencyKey,
            options: $options,
        );
    }

    /**
     * Move money out of a wallet, atomically and with a ledger posting.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InsufficientBalanceException when the available balance is too low
     * @throws FinancialException
     */
    public function debit(
        Wallet $wallet,
        Money $amount,
        FinancialTransactionType $type = FinancialTransactionType::Withdrawal,
        ?string $idempotencyKey = null,
        array $options = [],
    ): \App\Models\FinancialTransaction {
        return $this->transactions()->execute(
            wallet: $wallet,
            amount: $amount,
            type: $type,
            walletSide: LedgerEntryType::Debit,
            idempotencyKey: $idempotencyKey,
            options: $options,
        );
    }

    /**
     * Reserve part of the balance so it cannot be spent twice.
     *
     * This moves money from available to locked inside the same wallet. The total
     * balance does not change and no value enters or leaves the system, so no
     * ledger posting is made: there is nothing to post. The CHECK constraints
     * locked_balance >= 0 and locked_balance <= balance are the database-side
     * half of this guarantee.
     *
     * @throws InsufficientBalanceException
     * @throws FinancialException
     */
    public function lockFunds(Wallet $wallet, Money $amount, ?string $reason = null): Wallet
    {
        $amount->assertPositive('amount to reserve');

        return $this->withinTransaction(function () use ($wallet, $amount, $reason): Wallet {
            $locked = $this->locks->relock($wallet);
            $this->assertCurrency($locked, $amount->currency());

            if (! $locked->canDebit()) {
                throw FinancialException::withCode(
                    'wallet_cannot_reserve_funds',
                    sprintf('Wallet %d is %s and cannot reserve funds.', (int) $locked->getKey(), $locked->status->value),
                    ['wallet_id' => (int) $locked->getKey(), 'wallet_status' => $locked->status->value],
                );
            }

            $available = $this->availableBalance($locked);

            if ($available->isLessThan($amount)) {
                throw new InsufficientBalanceException(
                    (int) $locked->getKey(),
                    $amount->toString(),
                    $available->toString(),
                    $locked->currency,
                );
            }

            $lockedBalance = $this->money($locked->locked_balance, $locked->currency)->plus($amount);

            $locked->locked_balance = $lockedBalance->toString();
            $locked->version = (int) $locked->version + 1;

            if ($reason !== null) {
                $locked->locked_reason = $reason;
                $locked->locked_at = Carbon::now();
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * Release previously reserved funds back to the available balance.
     *
     * @throws FinancialException
     */
    public function unlockFunds(Wallet $wallet, Money $amount): Wallet
    {
        $amount->assertPositive('amount to release');

        return $this->withinTransaction(function () use ($wallet, $amount): Wallet {
            $locked = $this->locks->relock($wallet);
            $this->assertCurrency($locked, $amount->currency());

            $currentlyLocked = $this->money($locked->locked_balance, $locked->currency);

            if ($currentlyLocked->isLessThan($amount)) {
                throw FinancialException::withCode(
                    'wallet_release_exceeds_reserved',
                    sprintf(
                        'Wallet %d has %s reserved, which is less than the %s requested for release.',
                        (int) $locked->getKey(),
                        $currentlyLocked->toString(),
                        $amount->toString(),
                    ),
                    [
                        'wallet_id' => (int) $locked->getKey(),
                        'reserved_amount' => $currentlyLocked->toString(),
                        'requested_amount' => $amount->toString(),
                    ],
                );
            }

            $remaining = $currentlyLocked->minus($amount);

            $locked->locked_balance = $remaining->toString();
            $locked->version = (int) $locked->version + 1;

            if ($remaining->isZero()) {
                $locked->locked_reason = null;
                $locked->locked_at = null;
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * Readable alias for lockFunds(): reserves an amount inside the wallet.
     *
     * Provided because "lock" is the term used elsewhere in the codebase for
     * reserving funds. It adds no behaviour of its own; it forwards verbatim.
     *
     * @throws FinancialException
     */
    public function lock(Wallet $wallet, Money $amount, ?string $reason = null): Wallet
    {
        return $this->lockFunds($wallet, $amount, $reason);
    }

    /**
     * Readable alias for unlockFunds(): releases a reserved amount.
     *
     * @throws FinancialException
     */
    public function unlock(Wallet $wallet, Money $amount): Wallet
    {
        return $this->unlockFunds($wallet, $amount);
    }

    /**
     * Administratively freeze the whole wallet.
     *
     * This changes the wallet's status, not its balance, so no ledger posting is
     * involved. A locked wallet fails canCredit()/canDebit() and every subsequent
     * money movement is refused by WalletLockService.
     *
     * @throws FinancialException
     */
    public function lockWallet(Wallet $wallet, string $reason): Wallet
    {
        if (trim($reason) === '') {
            throw FinancialException::withCode(
                'wallet_lock_reason_required',
                'A reason is required when locking a wallet, so the action can be audited.',
            );
        }

        return $this->withinTransaction(function () use ($wallet, $reason): Wallet {
            $locked = $this->locks->relock($wallet);

            if ($locked->status === \App\Enums\WalletStatus::Closed) {
                throw FinancialException::withCode(
                    'wallet_closed',
                    sprintf('Wallet %d is closed and its status cannot be changed.', (int) $locked->getKey()),
                    ['wallet_id' => (int) $locked->getKey()],
                );
            }

            $locked->status = \App\Enums\WalletStatus::Locked;
            $locked->locked_reason = $reason;
            $locked->locked_at = Carbon::now();
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Return a frozen wallet to active use.
     *
     * @throws FinancialException
     */
    public function unlockWallet(Wallet $wallet): Wallet
    {
        return $this->withinTransaction(function () use ($wallet): Wallet {
            $locked = $this->locks->relock($wallet);

            if ($locked->status !== \App\Enums\WalletStatus::Locked) {
                throw FinancialException::withCode(
                    'wallet_not_locked',
                    sprintf(
                        'Wallet %d is %s, not locked, so it cannot be unlocked.',
                        (int) $locked->getKey(),
                        $locked->status->value,
                    ),
                    ['wallet_id' => (int) $locked->getKey(), 'wallet_status' => $locked->status->value],
                );
            }

            $locked->status = \App\Enums\WalletStatus::Active;
            $locked->locked_reason = null;
            $locked->locked_at = null;
            $locked->version = (int) $locked->version + 1;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Spendable balance: balance minus reserved funds.
     */
    public function availableBalance(Wallet $wallet): Money
    {
        return $this->money($wallet->balance, $wallet->currency)
            ->minus($this->money($wallet->locked_balance, $wallet->currency));
    }

    /**
     * Apply a credit to an ALREADY LOCKED wallet inside the caller's transaction.
     *
     * Internal: only FinancialTransactionService and FinancialReversalService call
     * it, and only after taking the row lock and creating the transaction record.
     *
     * @throws FinancialException
     */
    public function applyCredit(Wallet $lockedWallet, Money $amount, FinancialTransactionType $type): Money
    {
        $this->assertInsideTransaction();
        $amount->assertPositive('credit amount');
        $this->assertCurrency($lockedWallet, $amount->currency());

        if (! $lockedWallet->canCredit()) {
            throw FinancialException::withCode(
                'wallet_cannot_be_credited',
                sprintf(
                    'Wallet %d is %s and cannot be credited.',
                    (int) $lockedWallet->getKey(),
                    $lockedWallet->status->value,
                ),
                ['wallet_id' => (int) $lockedWallet->getKey(), 'wallet_status' => $lockedWallet->status->value],
            );
        }

        $balance = $this->money($lockedWallet->balance, $lockedWallet->currency)->plus($amount);

        $this->assertWithinMaximumBalance($lockedWallet, $balance);

        $lockedWallet->balance = $balance->toString();
        $this->accumulateRunningTotal($lockedWallet, $type, $amount);
        $lockedWallet->version = (int) $lockedWallet->version + 1;
        $lockedWallet->save();

        return $balance;
    }

    /**
     * Apply a debit to an ALREADY LOCKED wallet inside the caller's transaction.
     *
     * The affordability check uses the available balance, so reserved funds are
     * never spent, and it is performed under the row lock taken by the caller -
     * that is what makes concurrent overdraw impossible.
     *
     * @throws InsufficientBalanceException
     * @throws FinancialException
     */
    public function applyDebit(Wallet $lockedWallet, Money $amount, FinancialTransactionType $type): Money
    {
        $this->assertInsideTransaction();
        $amount->assertPositive('debit amount');
        $this->assertCurrency($lockedWallet, $amount->currency());

        if (! $lockedWallet->canDebit()) {
            throw FinancialException::withCode(
                'wallet_cannot_be_debited',
                sprintf(
                    'Wallet %d is %s and cannot be debited.',
                    (int) $lockedWallet->getKey(),
                    $lockedWallet->status->value,
                ),
                ['wallet_id' => (int) $lockedWallet->getKey(), 'wallet_status' => $lockedWallet->status->value],
            );
        }

        $available = $this->availableBalance($lockedWallet);

        if ($available->isLessThan($amount)) {
            throw new InsufficientBalanceException(
                (int) $lockedWallet->getKey(),
                $amount->toString(),
                $available->toString(),
                $lockedWallet->currency,
            );
        }

        $balance = $this->money($lockedWallet->balance, $lockedWallet->currency)->minus($amount);

        // Defence in depth: mirrors the wallets.balance >= 0 CHECK constraint.
        if (! (bool) config('finance.wallet.allow_negative_balance', false)) {
            $balance->assertNotNegative(sprintf('resulting balance of wallet %d', (int) $lockedWallet->getKey()));
        }

        $lockedWallet->balance = $balance->toString();
        $this->accumulateRunningTotal($lockedWallet, $type, $amount);
        $lockedWallet->version = (int) $lockedWallet->version + 1;
        $lockedWallet->save();

        return $balance;
    }

    /**
     * Reverse a previously applied movement on an already locked wallet.
     *
     * Used only by FinancialReversalService: a credit is undone with a debit and
     * a debit with a credit. Running totals are intentionally NOT decremented,
     * because total_deposited and friends are lifetime activity counters, and the
     * reversal is itself recorded as activity in the ledger.
     *
     * @throws FinancialException
     */
    public function applyReversal(Wallet $lockedWallet, Money $amount, LedgerEntryType $originalWalletSide): Money
    {
        $this->assertInsideTransaction();
        $amount->assertPositive('reversal amount');
        $this->assertCurrency($lockedWallet, $amount->currency());

        $current = $this->money($lockedWallet->balance, $lockedWallet->currency);

        if ($originalWalletSide === LedgerEntryType::Credit) {
            // The original movement increased the balance, so take it back out.
            $available = $this->availableBalance($lockedWallet);

            if ($available->isLessThan($amount)) {
                throw new InsufficientBalanceException(
                    (int) $lockedWallet->getKey(),
                    $amount->toString(),
                    $available->toString(),
                    $lockedWallet->currency,
                );
            }

            $balance = $current->minus($amount);
        } else {
            $balance = $current->plus($amount);
            $this->assertWithinMaximumBalance($lockedWallet, $balance);
        }

        $balance->assertNotNegative(sprintf('resulting balance of wallet %d', (int) $lockedWallet->getKey()));

        $lockedWallet->balance = $balance->toString();
        $lockedWallet->version = (int) $lockedWallet->version + 1;
        $lockedWallet->save();

        return $balance;
    }

    /**
     * The chart-of-accounts code that faces the player-liability account.
     *
     * The wallet side always hits player liability; this picks what stands on the
     * other side, which is what makes the ledger tell you WHY money moved.
     *
     * @throws FinancialException
     */
    public function counterpartAccountCode(FinancialTransactionType $type, LedgerEntryType $walletSide): string
    {
        return match ($type) {
            // Cash arrives from or leaves to the payment processor.
            FinancialTransactionType::Deposit => self::ACCOUNT_SYSTEM_CASH,
            FinancialTransactionType::Withdrawal => self::ACCOUNT_WITHDRAWAL_CLEARING,

            // Stakes become revenue; a refund gives that revenue back.
            FinancialTransactionType::BetDebit, FinancialTransactionType::BetRefund => self::ACCOUNT_BET_REVENUE,

            // Prizes and commission are costs of doing business.
            FinancialTransactionType::Payout => self::ACCOUNT_PRIZE_EXPENSE,
            FinancialTransactionType::Commission => self::ACCOUNT_COMMISSION_EXPENSE,
            FinancialTransactionType::Fee => self::ACCOUNT_FEE_REVENUE,

            // Settlement moves cash between the operator and the player.
            FinancialTransactionType::Settlement => self::ACCOUNT_SYSTEM_CASH,

            // Manual corrections and internal transfers land on equity.
            FinancialTransactionType::Adjustment, FinancialTransactionType::Transfer => self::ACCOUNT_ADJUSTMENT_EQUITY,

            // A reversal has no intrinsic counterpart: the service reverses the
            // original entries instead of deriving new accounts, so asking for a
            // counterpart here is a programming error.
            FinancialTransactionType::Reversal => throw FinancialException::withCode(
                'ledger_reversal_counterpart_undefined',
                'A reversal posts the opposite of the original entries and has no '
                .'independently derived counterpart account.',
                ['wallet_side' => $walletSide->value],
            ),
        };
    }

    /**
     * The player-liability account code, i.e. the wallet side of every posting.
     */
    public function walletAccountCode(): string
    {
        return self::ACCOUNT_PLAYER_LIABILITY;
    }

    /**
     * Lazily resolved orchestrator; see the note on circular dependencies above.
     */
    private function transactions(): FinancialTransactionService
    {
        /** @var FinancialTransactionService $service */
        $service = $this->container->make(FinancialTransactionService::class);

        return $service;
    }

    /**
     * Run a callback inside a transaction, joining the caller's if one is open.
     *
     * @template TReturn
     *
     * @param  \Closure(): TReturn  $callback
     * @return TReturn
     */
    private function withinTransaction(\Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }

    /**
     * Keep the lifetime activity counters in step with the movement.
     *
     * The column is chosen by the operation type; types that have no counter
     * (adjustment, transfer, reversal, fee) leave the counters untouched.
     */
    private function accumulateRunningTotal(Wallet $wallet, FinancialTransactionType $type, Money $amount): void
    {
        $column = $type->walletTotalColumn();

        if ($column === null) {
            return;
        }

        $current = $this->money($wallet->{$column}, $wallet->currency);
        $wallet->{$column} = $current->plus($amount)->toString();
    }

    /**
     * @throws FinancialException
     */
    private function assertWithinMaximumBalance(Wallet $wallet, Money $balance): void
    {
        $configured = config('finance.wallet.max_balance');

        if ($configured === null || $configured === '') {
            return;
        }

        $maximum = Money::of((string) $configured, $wallet->currency);

        if ($balance->isGreaterThan($maximum)) {
            throw FinancialException::withCode(
                'wallet_maximum_balance_exceeded',
                sprintf(
                    'The resulting balance %s exceeds the configured maximum wallet balance of %s.',
                    $balance->toString(),
                    $maximum->toString(),
                ),
                [
                    'wallet_id' => (int) $wallet->getKey(),
                    'resulting_balance' => $balance->toString(),
                    'maximum_balance' => $maximum->toString(),
                ],
            );
        }
    }

    /**
     * @throws FinancialException
     */
    private function assertCurrency(Wallet $wallet, Currency $currency): void
    {
        if ($wallet->currency !== $currency) {
            throw FinancialException::withCode(
                'wallet_currency_mismatch',
                sprintf(
                    'Wallet %d is denominated in %s and cannot process a %s amount.',
                    (int) $wallet->getKey(),
                    $wallet->currency->value,
                    $currency->value,
                ),
                [
                    'wallet_id' => (int) $wallet->getKey(),
                    'wallet_currency' => $wallet->currency->value,
                    'requested_currency' => $currency->value,
                ],
            );
        }
    }

    /**
     * @throws FinancialException
     */
    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw FinancialException::withCode(
                'wallet_mutation_outside_transaction',
                'Refusing to mutate a wallet balance outside a database transaction: the '
                .'balance change and its ledger entries must commit or roll back together.',
            );
        }
    }

    private function money(string|int|null $amount, Currency $currency): Money
    {
        return Money::fromDatabase($amount === null ? '0' : (string) $amount, $currency);
    }
}
