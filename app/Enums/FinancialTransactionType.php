<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Financial operation vocabulary used by the Phase 2 wallet + ledger engine.
 *
 * WHY THIS EXISTS NEXT TO TransactionType
 * ---------------------------------------
 * The audited Phase 1 schema stores the persisted transaction kind in
 * financial_transactions.type, and App\Models\FinancialTransaction casts that
 * column to App\Enums\TransactionType. That enum is the storage vocabulary and
 * is NOT redefined or replaced here.
 *
 * This enum is the engine-facing vocabulary: it names the operations the wallet
 * and ledger services actually perform and, critically, it knows which side of
 * the player's wallet each operation moves. Every case maps deterministically
 * onto a storable TransactionType through toTransactionType(), so no unknown
 * string can ever reach the database and break the model cast.
 *
 * Mapping note (reported, not silently patched): the audited schema has no
 * dedicated "fee" transaction kind, because financial_transactions already
 * carries a separate `fee` DECIMAL(20,2) column - a fee normally rides on the
 * transaction it belongs to instead of being its own row. Self::Fee therefore
 * persists as TransactionType::Adjustment, and fromTransactionType() maps
 * 'adjustment' back to self::Adjustment. Adding a real Fee case to
 * TransactionType would be a change to a file outside the requested scope.
 *
 * This enum performs no arithmetic, no database access and no ledger logic.
 */
enum FinancialTransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case BetDebit = 'bet_debit';
    case BetRefund = 'bet_refund';
    case Payout = 'payout';
    case Commission = 'commission';
    case Fee = 'fee';
    case Reversal = 'reversal';
    case Adjustment = 'adjustment';
    case Transfer = 'transfer';
    case Settlement = 'settlement';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Canonical engine type for a persisted storage type.
     *
     * Total function: every TransactionType case resolves, so a row read back
     * from financial_transactions can always be interpreted by the engine.
     */
    public static function fromTransactionType(TransactionType $type): self
    {
        return match ($type) {
            TransactionType::Deposit => self::Deposit,
            TransactionType::Withdrawal => self::Withdrawal,
            TransactionType::BetPlacement => self::BetDebit,
            TransactionType::BetRefund => self::BetRefund,
            TransactionType::Payout => self::Payout,
            TransactionType::Commission => self::Commission,
            TransactionType::Settlement => self::Settlement,
            TransactionType::Adjustment => self::Adjustment,
            TransactionType::Transfer => self::Transfer,
            TransactionType::Reversal => self::Reversal,
        };
    }

    /**
     * Storage type written to financial_transactions.type.
     *
     * Total function: there is no code path that can persist a value the
     * FinancialTransaction model cannot cast back.
     */
    public function toTransactionType(): TransactionType
    {
        return match ($this) {
            self::Deposit => TransactionType::Deposit,
            self::Withdrawal => TransactionType::Withdrawal,
            self::BetDebit => TransactionType::BetPlacement,
            self::BetRefund => TransactionType::BetRefund,
            self::Payout => TransactionType::Payout,
            self::Commission => TransactionType::Commission,
            self::Fee => TransactionType::Adjustment,
            self::Reversal => TransactionType::Reversal,
            self::Adjustment => TransactionType::Adjustment,
            self::Transfer => TransactionType::Transfer,
            self::Settlement => TransactionType::Settlement,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Deposit => 'Deposit',
            self::Withdrawal => 'Withdrawal',
            self::BetDebit => 'Bet Debit',
            self::BetRefund => 'Bet Refund',
            self::Payout => 'Payout',
            self::Commission => 'Commission',
            self::Fee => 'Fee',
            self::Reversal => 'Reversal',
            self::Adjustment => 'Adjustment',
            self::Transfer => 'Transfer',
            self::Settlement => 'Settlement',
        };
    }

    /**
     * Which side of the player's wallet this operation moves.
     *
     * Credit  - money arrives in the wallet.
     * Debit   - money leaves the wallet.
     * null    - the direction is not implied by the type and must be supplied
     *           explicitly by the caller (adjustments, transfers, settlements
     *           and reversals can go either way).
     */
    public function walletSide(): ?LedgerEntryType
    {
        return match ($this) {
            self::Deposit, self::BetRefund, self::Payout, self::Commission => LedgerEntryType::Credit,
            self::Withdrawal, self::BetDebit, self::Fee => LedgerEntryType::Debit,
            self::Reversal, self::Adjustment, self::Transfer, self::Settlement => null,
        };
    }

    /**
     * Whether this operation is expected to change a wallet balance at all.
     */
    public function affectsWallet(): bool
    {
        return $this !== self::Settlement;
    }

    /**
     * Whether a caller must supply an explicit direction for this type.
     */
    public function requiresExplicitDirection(): bool
    {
        return $this->walletSide() === null;
    }

    /**
     * Whether an idempotency key is mandatory for this operation.
     *
     * Driven by config/finance.php so configuration and code cannot drift.
     */
    public function requiresIdempotencyKey(): bool
    {
        /** @var array<int, string> $required */
        $required = (array) config('finance.idempotency.required_for', []);

        return in_array($this->toTransactionType()->value, $required, true);
    }

    /**
     * A reversal may never itself be reversed; that rule lives with the type
     * so every service enforces the same answer.
     */
    public function isReversible(): bool
    {
        return $this !== self::Reversal;
    }

    /**
     * Which running total on the wallet this operation contributes to, if any.
     *
     * Returns a wallets column name or null. The wallet service applies it; the
     * enum only states the intent.
     */
    public function walletTotalColumn(): ?string
    {
        return match ($this) {
            self::Deposit => 'total_deposited',
            self::Withdrawal => 'total_withdrawn',
            self::BetDebit => 'total_wagered',
            self::Payout => 'total_won',
            self::BetRefund, self::Commission, self::Fee,
            self::Reversal, self::Adjustment, self::Transfer, self::Settlement => null,
        };
    }
}
