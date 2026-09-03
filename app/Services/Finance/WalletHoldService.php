<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\WalletHoldType;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Reserving and releasing part of a wallet balance.
 *
 * WHAT A HOLD IS
 * --------------
 * A hold moves money from AVAILABLE to LOCKED inside the same wallet. The total
 * balance is untouched, no value enters or leaves the system, and therefore no
 * ledger entry is written: there would be nothing to post, because both sides of
 * the movement are the same account. The money only really leaves in
 * WithdrawalCompletionService, and that step does post a balanced double entry.
 *
 * THE INVARIANT
 *     balance = available_balance + locked_balance
 *
 * SCHEMA NOTE: `wallets` has NO `available_balance` column. The audited schema
 * stores `balance` and `locked_balance`, and available is derived as
 * balance - locked_balance (Wallet::availableBalance()). So "decrease available"
 * is expressed by increasing locked_balance while balance stays the same - which
 * is exactly what preserves the total. The database enforces the same rule from
 * its side with three CHECK constraints: balance >= 0, locked_balance >= 0 and
 * locked_balance <= balance. available_balance can therefore never go negative:
 * that would require locked_balance > balance, which the database refuses.
 *
 * WHY THIS SERVICE EXISTS NEXT TO WalletService::lockFunds()
 * It does not duplicate it - it delegates to it. WalletService owns the raw
 * balance arithmetic. This service adds the workflow layer around it: the hold
 * type vocabulary written into `locked_reason`, idempotent release, the
 * consumption step used by a payout, and the invariant assertions. There is
 * exactly one implementation of the arithmetic, in WalletService.
 *
 * SCHEMA LIMITATION (reported, not patched): there is no `wallet_holds` table, so
 * `locked_balance` is a single aggregate and the database cannot distinguish two
 * simultaneous holds of different types on one wallet. The per-hold record of
 * record stays the business row that caused the hold (the withdrawal, with its own
 * amount and status), and `locked_reason` carries the WalletHoldType of the most
 * recent reservation. Several independent, individually releasable holds per
 * wallet would need that missing table.
 *
 * LOCKING AND ATOMICITY
 * Every operation runs inside a database transaction and takes the wallet row with
 * `FOR UPDATE` before it reads the balance, so a concurrent caller cannot see the
 * same available balance twice. If the caller has already opened a transaction
 * this joins it; otherwise it opens one. Lock order across the whole finance
 * engine is WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS, and this service only
 * ever takes the first of those, so it can never invert the order.
 *
 * NO DIRECT BALANCE SETTERS. There is no setBalance(), no forceBalance() and no
 * unchecked increment. Every movement is a positive amount in a known direction,
 * verified before and after.
 *
 * ALL ARITHMETIC IS EXACT. Money is a bcmath-backed decimal string value object.
 * No float is created anywhere in this file.
 */
final class WalletHoldService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLockService $locks,
    ) {}

    /**
     * Reserve an amount so it cannot be spent twice.
     *
     * Steps, in order: open/join a transaction, lock the wallet row, verify the
     * available balance, increase locked_balance, verify the invariant still holds.
     *
     * @param  array<string, mixed>  $context  non-sensitive detail for the reason trail
     *
     * @throws InsufficientBalanceException when the available balance is too low
     * @throws FinancialException
     */
    public function hold(
        Wallet $wallet,
        Money $amount,
        WalletHoldType $type = WalletHoldType::Withdrawal,
        array $context = [],
    ): Wallet {
        $amount->assertPositive('amount to hold');

        return $this->withinTransaction(function () use ($wallet, $amount, $type): Wallet {
            $locked = $this->locks->lockForDebit((int) $wallet->getKey(), $amount->currency());

            $this->assertCurrency($locked, $amount->currency());

            $available = $this->wallets->availableBalance($locked);

            if ($available->isLessThan($amount)) {
                throw new InsufficientBalanceException(
                    (int) $locked->getKey(),
                    $amount->toString(),
                    $available->toString(),
                    $locked->currency,
                );
            }

            $totalBefore = $this->totalBalance($locked);

            // Delegates the arithmetic to the single implementation in WalletService.
            $updated = $this->wallets->lockFunds($locked, $amount, $type->reasonCode());

            $this->assertTotalPreserved($updated, $totalBefore, 'hold');
            $this->assertInvariant($updated);

            return $updated;
        });
    }

    /**
     * Alias of hold(), for callers that read better with "reserve".
     *
     * @param  array<string, mixed>  $context
     *
     * @throws FinancialException
     */
    public function reserve(
        Wallet $wallet,
        Money $amount,
        WalletHoldType $type = WalletHoldType::Withdrawal,
        array $context = [],
    ): Wallet {
        return $this->hold($wallet, $amount, $type, $context);
    }

    /**
     * Give a reservation back to the available balance.
     *
     * Strict: if less than the requested amount is reserved this throws rather
     * than releasing what it can, because silently releasing a different amount
     * than the caller asked for is how a reservation leak becomes invisible. Use
     * releaseIfHeld() for the idempotent variant.
     *
     * @throws FinancialException
     */
    public function release(Wallet $wallet, Money $amount): Wallet
    {
        $amount->assertPositive('amount to release');

        return $this->withinTransaction(function () use ($wallet, $amount): Wallet {
            $locked = $this->locks->lock((int) $wallet->getKey());

            $this->assertCurrency($locked, $amount->currency());

            $totalBefore = $this->totalBalance($locked);

            $updated = $this->wallets->unlockFunds($locked, $amount);

            $this->assertTotalPreserved($updated, $totalBefore, 'release');
            $this->assertInvariant($updated);

            return $updated;
        });
    }

    /**
     * Idempotent release: safe to call twice.
     *
     * A release that has already happened is not an error - a retried callback or
     * a re-run rejection must not fail, and must not release the amount a second
     * time. So when the wallet no longer holds the amount, this reports
     * released = false and changes nothing.
     *
     * It DOES NOT create money: it never releases more than is actually reserved.
     * It DOES NOT destroy accounting history: no ledger entry is touched or
     * deleted, because a hold has no ledger entry in the first place.
     *
     * @return array{wallet: Wallet, released: bool, amount: string, reserved_before: string, reserved_after: string}
     *
     * @throws FinancialException
     */
    public function releaseIfHeld(Wallet $wallet, Money $amount): array
    {
        $amount->assertPositive('amount to release');

        return $this->withinTransaction(function () use ($wallet, $amount): array {
            $locked = $this->locks->lock((int) $wallet->getKey());

            $this->assertCurrency($locked, $amount->currency());

            $reservedBefore = $this->heldAmount($locked);

            if ($reservedBefore->isLessThan($amount)) {
                // Nothing to give back, or a partial state a previous run already
                // settled. Report it instead of inventing a movement.
                return [
                    'wallet' => $locked,
                    'released' => false,
                    'amount' => $amount->toString(),
                    'reserved_before' => $reservedBefore->toString(),
                    'reserved_after' => $reservedBefore->toString(),
                ];
            }

            $totalBefore = $this->totalBalance($locked);

            $updated = $this->wallets->unlockFunds($locked, $amount);

            $this->assertTotalPreserved($updated, $totalBefore, 'release');
            $this->assertInvariant($updated);

            return [
                'wallet' => $updated,
                'released' => true,
                'amount' => $amount->toString(),
                'reserved_before' => $reservedBefore->toString(),
                'reserved_after' => $this->heldAmount($updated)->toString(),
            ];
        });
    }

    /**
     * Hand a reservation over to the debit that is about to consume it.
     *
     * This is the first half of a payout and MUST be followed, inside the same
     * database transaction, by a debit of the same amount through the finance
     * engine. On its own it only makes the money available again; it does not
     * spend it. WithdrawalCompletionService is the only caller, and it performs
     * both halves in one transaction so no partial state can be observed.
     *
     * The order matters: releasing first and debiting second means
     * locked_balance <= balance holds at every intermediate step, so the database
     * CHECK constraint is never transiently violated.
     *
     * @throws FinancialException
     */
    public function consume(Wallet $wallet, Money $amount): Wallet
    {
        $amount->assertPositive('amount to consume');

        $this->assertInsideTransaction('hold consumption');

        $locked = $this->locks->lock((int) $wallet->getKey());

        $this->assertCurrency($locked, $amount->currency());

        $reserved = $this->heldAmount($locked);

        if ($reserved->isLessThan($amount)) {
            throw FinancialException::withCode(
                'wallet_hold_consumption_exceeds_reserved',
                sprintf(
                    'Wallet %d has %s reserved, which is less than the %s a payout is trying to consume.',
                    (int) $locked->getKey(),
                    $reserved->toString(),
                    $amount->toString(),
                ),
                [
                    'wallet_id' => (int) $locked->getKey(),
                    'reserved_amount' => $reserved->toString(),
                    'requested_amount' => $amount->toString(),
                ],
            );
        }

        $updated = $this->wallets->unlockFunds($locked, $amount);

        $this->assertInvariant($updated);

        return $updated;
    }

    /**
     * The currently reserved amount.
     */
    public function heldAmount(Wallet $wallet): Money
    {
        return Money::fromDatabase((string) $wallet->locked_balance, $wallet->currency);
    }

    /**
     * The spendable amount, derived as balance - locked_balance.
     */
    public function availableAmount(Wallet $wallet): Money
    {
        return $this->wallets->availableBalance($wallet);
    }

    /**
     * The total balance, reserved part included.
     */
    public function totalBalance(Wallet $wallet): Money
    {
        return Money::fromDatabase((string) $wallet->balance, $wallet->currency);
    }

    /**
     * Whether at least this much is reserved.
     */
    public function hasHoldOfAtLeast(Wallet $wallet, Money $amount): bool
    {
        return $this->heldAmount($wallet)->isGreaterThanOrEqualTo($amount);
    }

    /**
     * The hold type recorded on the wallet, derived from `locked_reason`.
     */
    public function currentHoldType(Wallet $wallet): ?WalletHoldType
    {
        if ($this->heldAmount($wallet)->isZero()) {
            return null;
        }

        return WalletHoldType::fromReason($wallet->locked_reason);
    }

    /**
     * Verify balance = available + locked, and that neither part is negative.
     *
     * Cheap, exact and called after every movement. If it ever fails the enclosing
     * transaction is rolled back, so a wallet can never be persisted in a state
     * that breaks the invariant.
     *
     * @throws FinancialException
     */
    public function assertInvariant(Wallet $wallet): void
    {
        $total = $this->totalBalance($wallet);
        $held = $this->heldAmount($wallet);
        $available = $this->availableAmount($wallet);

        if ($total->isNegative()) {
            throw FinancialException::withCode(
                'wallet_invariant_negative_balance',
                sprintf('Wallet %d has a negative balance of %s.', (int) $wallet->getKey(), $total->toString()),
                ['wallet_id' => (int) $wallet->getKey(), 'balance' => $total->toString()],
            );
        }

        if ($held->isNegative()) {
            throw FinancialException::withCode(
                'wallet_invariant_negative_locked_balance',
                sprintf('Wallet %d has a negative locked balance of %s.', (int) $wallet->getKey(), $held->toString()),
                ['wallet_id' => (int) $wallet->getKey(), 'locked_balance' => $held->toString()],
            );
        }

        if ($available->isNegative()) {
            throw FinancialException::withCode(
                'wallet_invariant_negative_available_balance',
                sprintf(
                    'Wallet %d has a negative available balance of %s (balance %s, locked %s).',
                    (int) $wallet->getKey(),
                    $available->toString(),
                    $total->toString(),
                    $held->toString(),
                ),
                [
                    'wallet_id' => (int) $wallet->getKey(),
                    'balance' => $total->toString(),
                    'locked_balance' => $held->toString(),
                    'available_balance' => $available->toString(),
                ],
            );
        }

        if (! $available->plus($held)->equals($total)) {
            throw FinancialException::withCode(
                'wallet_invariant_broken',
                sprintf(
                    'Wallet %d breaks the invariant balance = available + locked: %s != %s + %s.',
                    (int) $wallet->getKey(),
                    $total->toString(),
                    $available->toString(),
                    $held->toString(),
                ),
                [
                    'wallet_id' => (int) $wallet->getKey(),
                    'balance' => $total->toString(),
                    'available_balance' => $available->toString(),
                    'locked_balance' => $held->toString(),
                ],
            );
        }
    }

    /**
     * A snapshot of the three figures, as exact decimal strings.
     *
     * @return array{balance: string, available_balance: string, locked_balance: string, currency: string, hold_type: string|null}
     */
    public function snapshot(Wallet $wallet): array
    {
        $type = $this->currentHoldType($wallet);

        return [
            'balance' => $this->totalBalance($wallet)->toString(),
            'available_balance' => $this->availableAmount($wallet)->toString(),
            'locked_balance' => $this->heldAmount($wallet)->toString(),
            'currency' => $wallet->currency->value,
            'hold_type' => $type?->value,
        ];
    }

    /**
     * A hold never changes the total balance. Verified explicitly rather than
     * assumed, because this is the property that makes a hold safe.
     *
     * @throws FinancialException
     */
    private function assertTotalPreserved(Wallet $wallet, Money $totalBefore, string $operation): void
    {
        $totalAfter = $this->totalBalance($wallet);

        if ($totalAfter->equals($totalBefore)) {
            return;
        }

        throw FinancialException::withCode(
            'wallet_hold_changed_total_balance',
            sprintf(
                'A wallet %s must not change the total balance of wallet %d: %s became %s.',
                $operation,
                (int) $wallet->getKey(),
                $totalBefore->toString(),
                $totalAfter->toString(),
            ),
            [
                'wallet_id' => (int) $wallet->getKey(),
                'operation' => $operation,
                'balance_before' => $totalBefore->toString(),
                'balance_after' => $totalAfter->toString(),
            ],
        );
    }

    /**
     * @throws FinancialException
     */
    private function assertCurrency(Wallet $wallet, Currency $currency): void
    {
        if ($wallet->currency === $currency) {
            return;
        }

        throw FinancialException::withCode(
            'wallet_hold_currency_mismatch',
            sprintf(
                'Wallet %d is denominated in %s and cannot hold an amount in %s. Implicit conversion is not permitted.',
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

    /**
     * @throws FinancialException
     */
    private function assertInsideTransaction(string $operation): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        throw FinancialException::withCode(
            'wallet_hold_outside_transaction',
            sprintf('A %s must run inside a database transaction.', $operation),
            ['operation' => $operation],
        );
    }

    /**
     * Join the caller's transaction, or open one when called standalone.
     */
    private function withinTransaction(\Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }
}
