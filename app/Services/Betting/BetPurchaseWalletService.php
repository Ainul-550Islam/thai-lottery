<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Exceptions\BetPurchaseException;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;

/**
 * The purchase pipeline's only door to the Phase 2.1 wallet engine.
 *
 * THIS IS AN ADAPTER, NOT A SECOND WALLET SYSTEM
 * There is exactly one wallet system in this project and it is Phase 2.1. This class
 * owns no balance arithmetic, writes to no wallet column, creates no financial
 * transaction row and posts no ledger entry. Every one of those actions is performed
 * by App\Services\Finance\WalletService and
 * App\Services\Finance\FinancialTransactionService, which already implement the
 * locking, the idempotency claim, the balance guard, the double-entry posting and the
 * balance-invariant assertion. What this class contributes is purchase-specific
 * VOCABULARY and ORDERING:
 *
 *   - it resolves WHICH wallet a bet may spend from, server-side;
 *   - it takes the wallet row lock FIRST, which is what fixes the pipeline's global
 *     lock order and is therefore what prevents deadlocks;
 *   - it re-checks the balance against the LOCKED row before any other work is done,
 *     so an unaffordable bet consumes no risk capacity and creates no rows;
 *   - it translates the engine's exceptions into purchase vocabulary while preserving
 *     the originals as $previous.
 *
 * WHY THE WALLET IS NEVER TAKEN FROM THE REQUEST
 * A client-supplied wallet id is an authorisation bypass: it lets a caller name any
 * wallet in the system as the source of funds. App\DTOs\BetPurchaseData has no wallet
 * field, and resolveWallet() derives the wallet from the authenticated player, the
 * configured betting currency and the wallet's own type and status. There is no code
 * path in this phase that reads a wallet identifier from input.
 *
 * WHY THE BALANCE IS READ TWICE, AND WHY ONLY THE SECOND READ COUNTS
 * The context carries a wallet model that was read WITHOUT a lock, so its balance is
 * stale the instant it is loaded. assertSufficientBalance() is only ever called with
 * the row returned by SELECT ... FOR UPDATE. Deciding affordability from the unlocked
 * read is exactly how two concurrent bets both pass a balance check and drive a wallet
 * negative.
 *
 * WHY THE DEBIT IS THE LAST MUTATION
 * The Phase 2.1 engine performs the debit AND the ledger posting AND the balance
 * assertion as one unit, and it needs the bet's primary key to write the polymorphic
 * reference that links the money to the bet. The debit therefore happens after the bet,
 * the item and the ticket exist. Because the engine opens a nested transaction - a
 * SAVEPOINT inside the purchase transaction - a failure at any later point still
 * unwinds the debit with everything else.
 *
 * NO FLOAT, NO ROUND
 * Every amount that crosses this class is a Money or a decimal string. There is no
 * (float), no (double), no intval(), no floatval() and no round() anywhere in it.
 */
final class BetPurchaseWalletService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLockService $locks,
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Derive the wallet a player's bet may spend from.
     *
     * Selection is deterministic - primary type, matching currency, ordered by key -
     * so a player with several wallets always spends from the same one and a retry
     * cannot land on a different wallet than the original attempt.
     *
     * @throws BetPurchaseException
     */
    public function resolveWallet(User $user, Currency $currency): Wallet
    {
        $userId = (int) $user->getKey();

        $query = Wallet::query()
            ->where('user_id', $userId)
            ->where('currency', $currency->value)
            ->orderBy('id');

        // The project declares whether an active wallet is mandatory. The declared
        // value is honoured rather than assumed, and when it is true a suspended or
        // frozen wallet is refused here instead of failing deeper inside the engine.
        if ($this->requiresActiveWallet()) {
            $query->where('status', WalletStatus::Active->value);
        }

        $wallet = (clone $query)->where('type', WalletType::Primary->value)->first()
            ?? $query->first();

        if (! $wallet instanceof Wallet) {
            throw BetPurchaseException::walletUnavailable(
                $userId,
                $currency,
                $this->requiresActiveWallet()
                    ? 'the player has no active wallet in this currency'
                    : 'the player has no wallet in this currency',
            );
        }

        if (! $wallet->canDebit()) {
            throw BetPurchaseException::walletUnavailable(
                $userId,
                $currency,
                sprintf('the wallet status is %s and cannot be debited', $wallet->status->value),
                ['wallet_id' => (int) $wallet->getKey(), 'wallet_status' => $wallet->status->value],
            );
        }

        return $wallet;
    }

    /**
     * Take the wallet row lock. This is the FIRST lock every purchase path acquires.
     *
     * Delegated to Phase 2.1's WalletLockService, which issues SELECT ... FOR UPDATE
     * and refuses to run outside a transaction. Using it rather than writing a second
     * lock helper means there is one definition of "locked wallet" in the project, and
     * it means the currency is verified as part of taking the lock.
     *
     * A cache lock, a mutex or any in-memory guard would be worthless here: two PHP
     * processes on two machines share no memory, and only the database can serialise
     * them.
     *
     * @throws BetPurchaseException
     */
    public function lockForDebit(int $walletId, Currency $currency): Wallet
    {
        $this->assertInsideTransaction('lock a wallet for a bet debit');

        try {
            return $this->locks->lockForDebit($walletId, $currency);
        } catch (FinancialException $exception) {
            throw BetPurchaseException::withCode(
                'bet_purchase_wallet_lock_failed',
                sprintf('The wallet could not be locked for this purchase: %s', $exception->getMessage()),
                ['wallet_id' => $walletId, 'currency' => $currency->value],
                $exception,
            );
        }
    }

    /**
     * Refuse the purchase when the LOCKED row cannot fund the stake.
     *
     * Available balance is balance minus locked_balance, computed by Phase 2.1 with
     * bcmath. Held funds are therefore respected: money reserved for a pending
     * withdrawal is not spendable on a bet.
     *
     * @return Money the available balance, for reporting
     *
     * @throws BetPurchaseException
     */
    public function assertSufficientBalance(Wallet $lockedWallet, Money $stake): Money
    {
        $this->assertInsideTransaction('check a wallet balance for a bet debit');

        $available = $this->availableBalance($lockedWallet);

        if ($available->isLessThan($stake)) {
            throw BetPurchaseException::insufficientBalance(
                (int) $lockedWallet->getKey(),
                $stake->toString(),
                $available->toString(),
                $stake->currency(),
            );
        }

        return $available;
    }

    /**
     * Available balance of a wallet, delegated to Phase 2.1.
     */
    public function availableBalance(Wallet $wallet): Money
    {
        return $this->wallets->availableBalance($wallet);
    }

    /**
     * Debit the stake for a bet.
     *
     * The type is FinancialTransactionType::BetDebit, which the Phase 2.1 chart-of-
     * accounts mapping already routes to the bet revenue account, so no account code
     * is chosen here. The polymorphic reference points at the Bet, and Phase 2.1 copies
     * that reference onto the ledger entries it posts, which is what makes
     * Bet::ledgerEntries() resolve without this class ever writing a ledger row.
     *
     * @param  array<string, mixed>  $metadata
     *
     * @throws BetPurchaseException
     */
    public function debitForBet(
        Wallet $lockedWallet,
        Money $stake,
        Bet $bet,
        string $idempotencyKey,
        array $metadata = [],
    ): FinancialTransaction {
        $this->assertInsideTransaction('debit a wallet for a bet');

        $betId = $bet->getKey();

        if (! is_int($betId) && ! is_numeric($betId)) {
            throw BetPurchaseException::invariantViolated(
                'the bet has no primary key, so the wallet debit could not be linked to it',
            );
        }

        try {
            return $this->wallets->debit(
                wallet: $lockedWallet,
                amount: $stake,
                type: FinancialTransactionType::BetDebit,
                idempotencyKey: $idempotencyKey,
                options: [
                    'description' => sprintf('Bet %s', (string) $bet->bet_number),
                    'reference_type' => Bet::class,
                    'reference_id' => (int) $betId,
                    'metadata' => $metadata,
                ],
            );
        } catch (InsufficientBalanceException $exception) {
            // Reached only if the balance changed between the lock and the debit, which
            // the lock makes impossible; kept because a money guard should never rely on
            // an argument about why it cannot fire.
            throw BetPurchaseException::insufficientBalance(
                (int) $lockedWallet->getKey(),
                $stake->toString(),
                $this->availableBalance($lockedWallet)->toString(),
                $stake->currency(),
                [],
                $exception,
            );
        } catch (FinancialException $exception) {
            throw BetPurchaseException::withCode(
                'bet_purchase_wallet_debit_failed',
                sprintf('The wallet debit for this bet failed: %s', $exception->getMessage()),
                [
                    'wallet_id' => (int) $lockedWallet->getKey(),
                    'bet_id' => (int) $betId,
                    'stake' => $stake->toString(),
                ],
                $exception,
            );
        }
    }

    /**
     * Whether the project requires an active wallet to bet.
     */
    public function requiresActiveWallet(): bool
    {
        return $this->config->get('lottery.betting.require_active_wallet') === true;
    }

    /**
     * @throws BetPurchaseException
     */
    private function assertInsideTransaction(string $operation): void
    {
        if (DB::transactionLevel() < 1) {
            throw BetPurchaseException::outsideTransaction($operation);
        }
    }
}
