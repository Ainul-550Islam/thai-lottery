<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinancialTransactionType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\WithdrawalException;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use App\Models\Withdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place a withdrawal actually takes money out of a wallet.
 *
 * WHAT ONE COMPLETION DOES, IN ORDER, INSIDE ONE TRANSACTION
 * ----------------------------------------------------------
 *   1. Lock the WALLET row (`FOR UPDATE`).
 *   2. Lock the WITHDRAWAL row (`FOR UPDATE`).
 *   3. Verify the reservation taken at approval time still covers the amount.
 *   4. Consume the reservation: locked_balance goes down, so the amount becomes
 *      available again for exactly one statement.
 *   5. Debit that amount through the finance engine, which posts the balanced
 *      double entry (DEBIT player liability, CREDIT withdrawal clearing) and
 *      lowers the balance.
 *   6. Mark the withdrawal completed with its transaction id and timestamp.
 *
 * Steps 4 and 5 are in that order on purpose: the database CHECK constraint
 * `locked_balance <= balance` then holds at every intermediate point. Debiting
 * first would transiently leave a wallet whose reserved amount exceeds its
 * balance.
 *
 * Net effect on a wallet holding 200.00 with 80.00 reserved:
 *   balance 200.00 -> 120.00, locked 80.00 -> 0.00, available 120.00 -> 120.00.
 * The available balance is untouched by completion, because the money being spent
 * had already been taken out of it at approval time. That is the whole point of
 * the hold.
 *
 * THE FOUR THINGS THIS CLASS MUST NEVER ALLOW
 *   - Completing twice. The withdrawal row is locked, an already-completed request
 *     is reported as a replay rather than debited again, and the debit is keyed by
 *     an idempotency key derived from the withdrawal id against the UNIQUE
 *     `financial_transactions.idempotency_key`. So even a bypassed check cannot
 *     produce a second debit.
 *   - Consuming more than was reserved. The reservation is compared against the
 *     request amount before anything moves, and WalletHoldService refuses to
 *     release more than is held.
 *   - A negative available balance. Consuming only releases what is actually
 *     reserved, the engine's debit re-checks the available balance under the same
 *     wallet lock, and three database CHECK constraints (balance >= 0,
 *     locked_balance >= 0, locked_balance <= balance) are the final backstop. The
 *     wallet invariant is asserted again after the debit.
 *   - Creating money. Nothing here credits anything. The only balance movement is
 *     a debit, and it is posted as a balanced double entry by the engine.
 *
 * NO MANUAL LEDGER INSERTION and NO DIRECT BALANCE SETTER. This file writes
 * neither `ledger_entries` nor `balance`. It calls WalletService::debit(), which
 * goes through FinancialTransactionService and LedgerPostingService.
 *
 * NO PAYMENT GATEWAY. Completion records that the payout was settled; it does not
 * send it. There is no provider call, no signature check and no credential here.
 * `provider_reference` is accepted as a non-secret reconciliation string only.
 *
 * LOCK ORDER: WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS.
 */
final class WithdrawalCompletionService
{
    /** Scope used to derive the debit idempotency key. */
    private const IDEMPOTENCY_SCOPE = 'withdrawal-debit';

    public function __construct(
        private readonly WalletLockService $locks,
        private readonly WalletService $wallets,
        private readonly WalletHoldService $holds,
        private readonly IdempotencyService $idempotency,
        private readonly FinancialStateTransitionService $transitions,
    ) {}

    /**
     * Settle an approved withdrawal: consume the reservation and debit the wallet.
     *
     * Idempotent: a second call on an already-completed withdrawal returns the
     * original transaction with debited = false and moves nothing.
     *
     * @param  array<string, mixed>  $options  description, metadata, provider, provider_reference
     * @return array{withdrawal: Withdrawal, transaction: FinancialTransaction, wallet: Wallet, debited: bool, replayed: bool, amount: string, snapshot: array{balance: string, available_balance: string, locked_balance: string, currency: string, hold_type: string|null}}
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    public function complete(Withdrawal $withdrawal, ?string $idempotencyKey = null, array $options = []): array
    {
        Money::assertExactArithmeticIsAvailable();

        return $this->withinTransaction(function () use ($withdrawal, $idempotencyKey, $options): array {
            // 1. WALLET lock first.
            $wallet = $this->locks->lock((int) $withdrawal->wallet_id);

            // 2. FINANCIAL ENTITY lock second.
            $current = $this->transitions->lockWithdrawal($withdrawal);

            $this->assertWalletMatches($current, $wallet);

            if ($current->status === WithdrawalStatus::Completed) {
                return $this->replayResult($current, $wallet);
            }

            // Debited but never marked: finish the bookkeeping, do not debit again.
            if ($current->financial_transaction_id !== null) {
                return $this->healUnmarkedWithdrawal($current, $wallet);
            }

            if (! $current->status->canComplete()) {
                throw WithdrawalException::notCompletable($current->id, $current->status);
            }

            $this->assertCurrencyMatches($current, $wallet);

            if (! $wallet->canDebit()) {
                throw WithdrawalException::walletNotDebitable((int) $wallet->getKey(), $wallet->status->value);
            }

            $amount = $this->amountOf($current);

            // 3. The reservation must still cover the amount.
            $reserved = $this->holds->heldAmount($wallet);

            if ($reserved->isLessThan($amount)) {
                throw WithdrawalException::noReservation(
                    (int) $current->getKey(),
                    (int) $wallet->getKey(),
                    $amount->toString(),
                    $reserved->toString(),
                );
            }

            // 4. Consume it. Releases exactly the request amount, never more.
            $unlockedWallet = $this->holds->consume($wallet, $amount);

            // 5. Debit through the engine. Posts the balanced double entry.
            $key = $idempotencyKey ?? $this->idempotencyKeyFor($current);

            $transaction = $this->wallets->debit(
                wallet: $unlockedWallet,
                amount: $amount,
                type: FinancialTransactionType::Withdrawal,
                idempotencyKey: $key,
                options: [
                    'description' => isset($options['description']) && is_string($options['description'])
                        ? $options['description']
                        : sprintf('Withdrawal %s settled', $current->reference_number),
                    'fee' => (string) $current->fee,
                    'reference_type' => Withdrawal::class,
                    'reference_id' => (int) $current->getKey(),
                    'metadata' => array_merge(
                        isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [],
                        [
                            'withdrawal_id' => (int) $current->getKey(),
                            'withdrawal_reference' => $current->reference_number,
                            'withdrawal_net_amount' => (string) $current->net_amount,
                            'reservation_consumed' => $amount->toString(),
                        ],
                    ),
                ],
            );

            // 6. Mark it, in the same transaction.
            $now = Carbon::now();

            $completed = $this->transitions->transitionWithdrawal($current, WithdrawalStatus::Completed, [
                'financial_transaction_id' => (int) $transaction->getKey(),
                'completed_at' => $now,
                'provider' => $this->stringOption($options, 'provider') ?? $current->provider,
                'provider_reference' => $this->stringOption($options, 'provider_reference') ?? $current->provider_reference,
                'metadata' => $this->mergeMetadata($current, [
                    'completed_at' => $now->toIso8601String(),
                    'debited_amount' => $amount->toString(),
                    'financial_transaction_reference' => $transaction->reference_number,
                ]),
            ]);

            $finalWallet = $this->locks->relock($unlockedWallet);

            // Final safety net: the invariant must still hold and the available
            // balance must not be negative.
            $this->holds->assertInvariant($finalWallet);
            $this->assertSettled($completed);

            return [
                'withdrawal' => $completed,
                'transaction' => $transaction,
                'wallet' => $finalWallet,
                'debited' => true,
                'replayed' => false,
                'amount' => $amount->toString(),
                'snapshot' => $this->holds->snapshot($finalWallet),
            ];
        });
    }

    /**
     * The amount debited from the wallet: the gross request amount.
     *
     * The beneficiary receives `net_amount`; the difference is the operator's fee,
     * which is recorded on the financial transaction's own `fee` column.
     */
    public function amountOf(Withdrawal $withdrawal): Money
    {
        return Money::fromDatabase((string) $withdrawal->amount, $withdrawal->currency);
    }

    /**
     * The deterministic debit key for a withdrawal.
     */
    public function idempotencyKeyFor(Withdrawal $withdrawal): string
    {
        return $this->idempotency->deterministicKey(
            self::IDEMPOTENCY_SCOPE,
            (string) $withdrawal->getKey(),
        );
    }

    public function existingTransactionFor(Withdrawal $withdrawal): ?FinancialTransaction
    {
        if ($withdrawal->financial_transaction_id !== null) {
            return FinancialTransaction::query()->whereKey($withdrawal->financial_transaction_id)->first();
        }

        return $this->idempotency->existingFor($this->idempotencyKeyFor($withdrawal));
    }

    /**
     * Whether the payout may be settled right now, without throwing.
     */
    public function canComplete(Withdrawal $withdrawal): bool
    {
        return $withdrawal->status->canComplete() && $withdrawal->financial_transaction_id === null;
    }

    public function isSettled(Withdrawal $withdrawal): bool
    {
        return $withdrawal->status === WithdrawalStatus::Completed && $withdrawal->financial_transaction_id !== null;
    }

    /**
     * Report an already-settled withdrawal without moving money.
     *
     * @return array{withdrawal: Withdrawal, transaction: FinancialTransaction, wallet: Wallet, debited: bool, replayed: bool, amount: string, snapshot: array{balance: string, available_balance: string, locked_balance: string, currency: string, hold_type: string|null}}
     *
     * @throws WithdrawalException
     */
    private function replayResult(Withdrawal $withdrawal, Wallet $wallet): array
    {
        $transaction = $this->existingTransactionFor($withdrawal);

        if (! $transaction instanceof FinancialTransaction) {
            throw WithdrawalException::inconsistentState(
                (int) $withdrawal->getKey(),
                'the withdrawal is completed but no financial transaction exists for it',
            );
        }

        return [
            'withdrawal' => $withdrawal,
            'transaction' => $transaction,
            'wallet' => $wallet,
            'debited' => false,
            'replayed' => true,
            'amount' => Money::fromDatabase((string) $transaction->amount, $withdrawal->currency)->toString(),
            'snapshot' => $this->holds->snapshot($wallet),
        ];
    }

    /**
     * Finish the bookkeeping for a withdrawal whose debit exists but whose status
     * was never written. Debits nothing.
     *
     * @return array{withdrawal: Withdrawal, transaction: FinancialTransaction, wallet: Wallet, debited: bool, replayed: bool, amount: string, snapshot: array{balance: string, available_balance: string, locked_balance: string, currency: string, hold_type: string|null}}
     *
     * @throws WithdrawalException
     * @throws FinancialException
     */
    private function healUnmarkedWithdrawal(Withdrawal $withdrawal, Wallet $wallet): array
    {
        $transaction = FinancialTransaction::query()->whereKey($withdrawal->financial_transaction_id)->first();

        if (! $transaction instanceof FinancialTransaction) {
            throw WithdrawalException::inconsistentState(
                (int) $withdrawal->getKey(),
                'the withdrawal points at a financial transaction that does not exist',
            );
        }

        if (! $withdrawal->status->canTransitionTo(WithdrawalStatus::Completed)) {
            throw WithdrawalException::notCompletable($withdrawal->id, $withdrawal->status);
        }

        $completed = $this->transitions->transitionWithdrawal($withdrawal, WithdrawalStatus::Completed, [
            'completed_at' => $withdrawal->completed_at ?? Carbon::now(),
            'metadata' => $this->mergeMetadata($withdrawal, [
                'reconciled_at' => Carbon::now()->toIso8601String(),
                'reconciliation_note' => 'Status completed from an existing debit transaction; no new debit was made.',
            ]),
        ]);

        return [
            'withdrawal' => $completed,
            'transaction' => $transaction,
            'wallet' => $wallet,
            'debited' => false,
            'replayed' => true,
            'amount' => Money::fromDatabase((string) $transaction->amount, $withdrawal->currency)->toString(),
            'snapshot' => $this->holds->snapshot($wallet),
        ];
    }

    /**
     * A completed withdrawal must always carry its transaction link and timestamp.
     *
     * @throws WithdrawalException
     */
    private function assertSettled(Withdrawal $withdrawal): void
    {
        if ($withdrawal->financial_transaction_id !== null && $withdrawal->completed_at !== null) {
            return;
        }

        throw WithdrawalException::inconsistentState(
            (int) $withdrawal->getKey(),
            'the withdrawal was marked completed without a linked transaction or completion timestamp',
        );
    }

    /**
     * @throws WithdrawalException
     */
    private function assertCurrencyMatches(Withdrawal $withdrawal, Wallet $wallet): void
    {
        if ($withdrawal->currency === $wallet->currency) {
            return;
        }

        throw WithdrawalException::currencyMismatch(
            (int) $wallet->getKey(),
            $wallet->currency,
            $withdrawal->currency,
        );
    }

    /**
     * @throws WithdrawalException
     */
    private function assertWalletMatches(Withdrawal $withdrawal, Wallet $wallet): void
    {
        if ((int) $withdrawal->wallet_id === (int) $wallet->getKey()) {
            return;
        }

        throw WithdrawalException::walletMismatch(
            (int) $withdrawal->getKey(),
            (int) $withdrawal->wallet_id,
            (int) $wallet->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $additions
     * @return array<string, mixed>
     */
    private function mergeMetadata(Withdrawal $withdrawal, array $additions): array
    {
        $existing = is_array($withdrawal->metadata) ? $withdrawal->metadata : [];

        return array_merge($existing, $additions);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        return isset($options[$key]) && is_string($options[$key]) && $options[$key] !== ''
            ? $options[$key]
            : null;
    }

    private function withinTransaction(\Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $callback();
        }

        return DB::transaction($callback);
    }
}
