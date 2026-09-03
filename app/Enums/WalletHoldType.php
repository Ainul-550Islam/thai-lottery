<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why a portion of a wallet's balance is reserved.
 *
 * HOW THIS IS PERSISTED
 * ---------------------
 * SCHEMA LIMITATION (reported, not patched): the audited schema has NO
 * `wallet_holds` table. A wallet reserves funds through two existing columns:
 *
 *     wallets.locked_balance  decimal(20,2)  - the total reserved amount
 *     wallets.locked_reason   string, nullable - free text
 *
 * `locked_balance` is a single aggregate, so the database cannot tell two
 * simultaneous holds of different kinds apart. This enum supplies the controlled
 * vocabulary written into `locked_reason`, so at least the most recent reservation
 * on a wallet is attributable, and the per-hold record of record stays the
 * business row that caused it (the withdrawal row, with its own amount and
 * status). Anything needing several independent, individually releasable holds per
 * wallet would need a real `wallet_holds` table, which this phase does not create.
 *
 * NO MONEY LOGIC LIVES HERE. This enum does not add, subtract, lock or release
 * anything; it only names reasons. All arithmetic belongs to WalletHoldService.
 */
enum WalletHoldType: string
{
    /** Funds reserved for an approved withdrawal awaiting payout. */
    case Withdrawal = 'withdrawal';

    /** Any other reservation the finance engine needs. */
    case OtherFinancialHold = 'other_financial_hold';

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
     * Resolve a stored `locked_reason` back to a type.
     *
     * Unknown or legacy free text degrades to OtherFinancialHold instead of
     * throwing, because a reason string is descriptive metadata and must never be
     * able to block the release of real money.
     */
    public static function fromReason(?string $reason): self
    {
        if ($reason === null) {
            return self::OtherFinancialHold;
        }

        $normalised = strtolower(trim($reason));

        foreach (self::cases() as $case) {
            if ($case->value === $normalised) {
                return $case;
            }
        }

        return self::OtherFinancialHold;
    }

    public function label(): string
    {
        return match ($this) {
            self::Withdrawal => 'Withdrawal Reservation',
            self::OtherFinancialHold => 'Other Financial Hold',
        };
    }

    /**
     * The value stored in `wallets.locked_reason` for this type.
     */
    public function reasonCode(): string
    {
        return $this->value;
    }

    /**
     * Whether a hold of this type is expected to be consumed by a debit later,
     * rather than simply released back to the available balance.
     */
    public function isConsumable(): bool
    {
        return $this === self::Withdrawal;
    }

    /**
     * The polymorphic reference type a hold of this kind points at, when one
     * exists in the audited schema.
     */
    public function referenceType(): ?FinancialReferenceType
    {
        return match ($this) {
            self::Withdrawal => FinancialReferenceType::Withdrawal,
            self::OtherFinancialHold => null,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Withdrawal => 'blue',
            self::OtherFinancialHold => 'gray',
        };
    }
}
