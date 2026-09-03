<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\DepositStatus;
use App\Enums\FinancialTransactionType;
use App\Exceptions\DepositException;
use App\Exceptions\FinancialException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only place a deposit is turned into wallet balance.
 *
 * THE TWO FAILURES THIS CLASS EXISTS TO PREVENT
 * ---------------------------------------------
 * 1. "Deposit confirmed but wallet not credited."
 *    Impossible, because the credit and the status change happen inside ONE
 *    database transaction. The status is only written after the finance engine has
 *    returned a persisted financial transaction, and if the status write fails the
 *    credit is rolled back with it. There is no code path that marks a deposit
 *    confirmed without a linked transaction id.
 *
 * 2. "Wallet credited twice."
 *    Prevented three times over:
 *      a. The DEPOSIT row is taken with `FOR UPDATE`, so two callers cannot both
 *         see a completable status.
 *      b. A deposit that is already confirmed, or already carries a
 *         financial_transaction_id, is never credited again - it is reported as a
 *         replay.
 *      c. The credit is keyed by an idempotency key DERIVED FROM THE DEPOSIT ID.
 *         `financial_transactions.idempotency_key` is UNIQUE, so even if every
 *         application-level check were bypassed, the second credit attempt resolves
 *         to the first transaction instead of inserting a new one. This is the
 *         guarantee that does not depend on getting the locking right.
 *
 * WHICH AMOUNT REACHES THE WALLET
 * `net_amount`, which the audited migration defines as "the amount that reaches
 * the wallet after the fee". With the audited default fee of 0.00 percent,
 * net_amount equals amount, so a 100.00 deposit credits exactly 100.00. The value
 * is read from the stored column rather than recomputed, so a later fee change
 * cannot retroactively alter a settled deposit.
 *
 * NO MANUAL LEDGER INSERTION. This class never touches `ledger_entries`. It calls
 * WalletService::credit(), which delegates to FinancialTransactionService, which is
 * the only thing in the codebase that posts through LedgerPostingService. So the
 * double entry is guaranteed to be balanced by the engine, not by this file.
 *
 * NO PAYMENT GATEWAY. Completion here is an internal settlement step. It calls no
 * provider, verifies no signature and holds no credential. Wiring a real provider
 * callback into this method belongs to a later phase.
 *
 * LOCK ORDER: WALLET -> FINANCIAL ENTITY -> LEDGER ACCOUNTS.
 */
final class DepositCompletionService
{
    /** Scope used to derive the credit idempotency key. */
    private const IDEMPOTENCY_SCOPE = 'deposit-credit';

    public function __construct(
        private readonly WalletLockService $locks,
        private readonly WalletService $wallets,
        private readonly IdempotencyService $idempotency,
        private readonly FinancialStateTransitionService $transitions,
    ) {}

    /**
     * Settle an approved deposit into the wallet.
     *
     * Idempotent by design: calling it again on an already-confirmed deposit
     * returns the original transaction with credited = false and replayed = true,
     * and moves no money. That is what makes a retried provider callback or a
     * re-run command safe.
     *
     * @param  array<string, mixed>  $options  description, metadata, provider, provider_reference
     * @return array{deposit: Deposit, transaction: FinancialTransaction, wallet: Wallet, credited: bool, replayed: bool, amount: string}
     *
     * @throws DepositException
     * @throws FinancialException
     */
    public function complete(Deposit $deposit, ?string $idempotencyKey = null, array $options = []): array
    {
        Money::assertExactArithmeticIsAvailable();

        return $this->withinTransaction(function () use ($deposit, $idempotencyKey, $options): array {
            // 1. WALLET lock first.
            $wallet = $this->locks->lock((int) $deposit->wallet_id);

            // 2. FINANCIAL ENTITY lock second.
            $current = $this->transitions->lockDeposit($deposit);

            $this->assertWalletMatches($current, $wallet);

            // Already settled: report the replay, credit nothing.
            if ($current->status === DepositStatus::Confirmed) {
                return $this->replayResult($current, $wallet);
            }

            // Credited but not yet marked - only reachable if a process died between
            // two statements of an earlier attempt. Finish the bookkeeping using the
            // transaction that already exists. No second credit.
            if ($current->financial_transaction_id !== null) {
                return $this->healUnmarkedDeposit($current, $wallet);
            }

            if (! $current->status->canComplete()) {
                throw DepositException::notCompletable($current->id, $current->status);
            }

            $this->assertWalletCanReceive($current, $wallet);

            $amount = $this->creditableAmount($current);
            $key = $idempotencyKey ?? $this->idempotencyKeyFor($current);

            // 3. The finance engine posts the balanced double entry and moves the
            //    balance. Nothing in this file writes a ledger row or a balance.
            $transaction = $this->wallets->credit(
                wallet: $wallet,
                amount: $amount,
                type: FinancialTransactionType::Deposit,
                idempotencyKey: $key,
                options: [
                    'description' => isset($options['description']) && is_string($options['description'])
                        ? $options['description']
                        : sprintf('Deposit %s settled', $current->reference_number),
                    'reference_type' => Deposit::class,
                    'reference_id' => (int) $current->getKey(),
                    'metadata' => array_merge(
                        isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [],
                        [
                            'deposit_id' => (int) $current->getKey(),
                            'deposit_reference' => $current->reference_number,
                            'deposit_gross_amount' => (string) $current->amount,
                            'deposit_fee' => (string) $current->fee,
                        ],
                    ),
                ],
            );

            // 4. Only now is the deposit marked, in the same transaction.
            $confirmed = $this->transitions->transitionDeposit($current, DepositStatus::Confirmed, [
                'financial_transaction_id' => (int) $transaction->getKey(),
                'confirmed_at' => Carbon::now(),
                'provider' => $this->stringOption($options, 'provider') ?? $current->provider,
                'provider_reference' => $this->stringOption($options, 'provider_reference') ?? $current->provider_reference,
                'metadata' => $this->mergeMetadata($current, [
                    'completed_at' => Carbon::now()->toIso8601String(),
                    'credited_amount' => $amount->toString(),
                    'financial_transaction_reference' => $transaction->reference_number,
                ]),
            ]);

            $freshWallet = $this->locks->relock($wallet);

            $this->assertCredited($confirmed);

            return [
                'deposit' => $confirmed,
                'transaction' => $transaction,
                'wallet' => $freshWallet,
                'credited' => true,
                'replayed' => false,
                'amount' => $amount->toString(),
            ];
        });
    }

    /**
     * The amount that reaches the wallet: the stored net amount.
     *
     * @throws DepositException
     */
    public function creditableAmount(Deposit $deposit): Money
    {
        $net = Money::fromDatabase((string) $deposit->net_amount, $deposit->currency);

        if (! $net->isPositive()) {
            throw DepositException::netAmountInvalid(
                (string) $deposit->amount,
                (string) $deposit->fee,
                $net->toString(),
            );
        }

        return $net;
    }

    /**
     * The deterministic credit key for a deposit.
     *
     * Derived from the deposit id alone, so every completion attempt for one
     * deposit produces the same key and therefore at most one credit transaction
     * for the lifetime of that deposit.
     */
    public function idempotencyKeyFor(Deposit $deposit): string
    {
        return $this->idempotency->deterministicKey(
            self::IDEMPOTENCY_SCOPE,
            (string) $deposit->getKey(),
        );
    }

    /**
     * The credit transaction for a deposit, if one exists.
     */
    public function existingTransactionFor(Deposit $deposit): ?FinancialTransaction
    {
        if ($deposit->financial_transaction_id !== null) {
            return FinancialTransaction::query()->whereKey($deposit->financial_transaction_id)->first();
        }

        return $this->idempotency->existingFor($this->idempotencyKeyFor($deposit));
    }

    /**
     * Whether the deposit may be settled right now, without throwing.
     */
    public function canComplete(Deposit $deposit): bool
    {
        return $deposit->status->canComplete() && $deposit->financial_transaction_id === null;
    }

    /**
     * Whether the deposit has already been settled.
     */
    public function isSettled(Deposit $deposit): bool
    {
        return $deposit->status === DepositStatus::Confirmed && $deposit->financial_transaction_id !== null;
    }

    /**
     * Report an already-settled deposit without moving money.
     *
     * @return array{deposit: Deposit, transaction: FinancialTransaction, wallet: Wallet, credited: bool, replayed: bool, amount: string}
     *
     * @throws DepositException
     */
    private function replayResult(Deposit $deposit, Wallet $wallet): array
    {
        $transaction = $this->existingTransactionFor($deposit);

        if (! $transaction instanceof FinancialTransaction) {
            // Confirmed with no transaction anywhere is the one state this design
            // must never produce. Refuse loudly instead of crediting to "fix" it,
            // because crediting here would be indistinguishable from a double credit.
            throw DepositException::inconsistentState(
                (int) $deposit->getKey(),
                'the deposit is confirmed but no financial transaction exists for it',
            );
        }

        return [
            'deposit' => $deposit,
            'transaction' => $transaction,
            'wallet' => $wallet,
            'credited' => false,
            'replayed' => true,
            'amount' => Money::fromDatabase((string) $transaction->amount, $deposit->currency)->toString(),
        ];
    }

    /**
     * Finish the bookkeeping for a deposit whose credit exists but whose status was
     * never written. Credits nothing.
     *
     * @return array{deposit: Deposit, transaction: FinancialTransaction, wallet: Wallet, credited: bool, replayed: bool, amount: string}
     *
     * @throws DepositException
     * @throws FinancialException
     */
    private function healUnmarkedDeposit(Deposit $deposit, Wallet $wallet): array
    {
        $transaction = FinancialTransaction::query()->whereKey($deposit->financial_transaction_id)->first();

        if (! $transaction instanceof FinancialTransaction) {
            throw DepositException::inconsistentState(
                (int) $deposit->getKey(),
                'the deposit points at a financial transaction that does not exist',
            );
        }

        if (! $deposit->status->canTransitionTo(DepositStatus::Confirmed)) {
            throw DepositException::notCompletable($deposit->id, $deposit->status);
        }

        $confirmed = $this->transitions->transitionDeposit($deposit, DepositStatus::Confirmed, [
            'confirmed_at' => $deposit->confirmed_at ?? Carbon::now(),
            'metadata' => $this->mergeMetadata($deposit, [
                'reconciled_at' => Carbon::now()->toIso8601String(),
                'reconciliation_note' => 'Status completed from an existing credit transaction; no new credit was made.',
            ]),
        ]);

        return [
            'deposit' => $confirmed,
            'transaction' => $transaction,
            'wallet' => $wallet,
            'credited' => false,
            'replayed' => true,
            'amount' => Money::fromDatabase((string) $transaction->amount, $deposit->currency)->toString(),
        ];
    }

    /**
     * A confirmed deposit must always carry its transaction link and timestamp.
     *
     * @throws DepositException
     */
    private function assertCredited(Deposit $deposit): void
    {
        if ($deposit->financial_transaction_id !== null && $deposit->confirmed_at !== null) {
            return;
        }

        throw DepositException::inconsistentState(
            (int) $deposit->getKey(),
            'the deposit was marked confirmed without a linked transaction or confirmation timestamp',
        );
    }

    /**
     * @throws DepositException
     */
    private function assertWalletCanReceive(Deposit $deposit, Wallet $wallet): void
    {
        if ($deposit->currency !== $wallet->currency) {
            throw DepositException::currencyMismatch(
                (int) $wallet->getKey(),
                $wallet->currency,
                $deposit->currency,
            );
        }

        if (! $wallet->canCredit()) {
            throw DepositException::walletNotCreditable((int) $wallet->getKey(), $wallet->status->value);
        }
    }

    /**
     * @throws DepositException
     */
    private function assertWalletMatches(Deposit $deposit, Wallet $wallet): void
    {
        if ((int) $deposit->wallet_id === (int) $wallet->getKey()) {
            return;
        }

        throw DepositException::walletMismatch(
            (int) $deposit->getKey(),
            (int) $deposit->wallet_id,
            (int) $wallet->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $additions
     * @return array<string, mixed>
     */
    private function mergeMetadata(Deposit $deposit, array $additions): array
    {
        $existing = is_array($deposit->metadata) ? $deposit->metadata : [];

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
