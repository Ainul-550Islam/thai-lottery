<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Wallet lifecycle status.
 *
 * Pending   - created but not yet activated (e.g. registration incomplete).
 * Active    - the only status that permits debits and credits.
 * Locked    - short-lived technical hold while a balance mutation is in flight
 *             or a reconciliation mismatch is being investigated.
 * Frozen    - administrative hold; the balance is visible and preserved but no
 *             movement is allowed until an administrator lifts it.
 * Suspended - compliance or risk hold; incoming credits are still recorded so
 *             money is never lost, but the user cannot spend or withdraw.
 * Closed    - terminal state; no further movement is ever permitted.
 *
 * This enum only describes what a status permits. Balance changes, locking and
 * ledger writing are performed by the wallet engine, never here.
 */
enum WalletStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Locked = 'locked';
    case Frozen = 'frozen';
    case Suspended = 'suspended';
    case Closed = 'closed';

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

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Activation',
            self::Active => 'Active',
            self::Locked => 'Locked',
            self::Frozen => 'Frozen',
            self::Suspended => 'Suspended',
            self::Closed => 'Closed',
        };
    }

    /**
     * Whether any balance movement is allowed at all.
     */
    public function canTransact(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether money may be added to the wallet.
     *
     * Suspended wallets still accept credits (payouts, refunds, deposits already
     * in flight) so that funds owed to the user are never silently dropped.
     */
    public function canCredit(): bool
    {
        return match ($this) {
            self::Active, self::Suspended => true,
            self::Pending, self::Locked, self::Frozen, self::Closed => false,
        };
    }

    /**
     * Whether money may be taken out of the wallet (bets, withdrawals, fees).
     */
    public function canDebit(): bool
    {
        return $this === self::Active;
    }

    public function canWithdraw(): bool
    {
        return $this === self::Active;
    }

    /**
     * Statuses an administrator can lift back to active.
     */
    public function isRestorable(): bool
    {
        return match ($this) {
            self::Pending, self::Locked, self::Frozen, self::Suspended => true,
            self::Active, self::Closed => false,
        };
    }

    /**
     * Terminal statuses can never change again.
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * Whether the hold was applied by an administrator rather than by the system.
     */
    public function isAdministrativeHold(): bool
    {
        return match ($this) {
            self::Frozen, self::Suspended => true,
            self::Pending, self::Active, self::Locked, self::Closed => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Pending => 'yellow',
            self::Locked => 'blue',
            self::Frozen => 'orange',
            self::Suspended => 'orange',
            self::Closed => 'red',
        };
    }
}
