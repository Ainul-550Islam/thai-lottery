<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle state of a chart-of-accounts entry.
 *
 * Active - the account accepts new ledger entries.
 * Locked - temporarily closed for posting (reconciliation, period lock, or an
 *          investigation). Existing entries stay readable and untouched.
 * Closed - permanently retired. Historical entries remain, but nothing new may
 *          ever be posted against it again.
 *
 * SCHEMA NOTE (reported, not silently patched)
 * -------------------------------------------
 * The audited Phase 1 ledger_accounts table expresses availability with a
 * boolean `is_active` column plus soft deletes; it has no `status` string
 * column, and App\Models\LedgerAccount is cast accordingly. This enum is
 * therefore a derived domain vocabulary, not a persisted column cast: use
 * fromFlags() to project the two stored booleans onto a single status the
 * services can reason about. Adding a real `status` column would be a migration
 * change, which is out of scope for Phase 2.1.
 *
 * Pure value logic only: no database access, no queries, no model coupling.
 */
enum LedgerAccountStatus: string
{
    case Active = 'active';
    case Locked = 'locked';
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

    /**
     * Project the stored ledger_accounts flags onto a single status.
     *
     * A soft-deleted account is Closed regardless of its is_active flag, because
     * a retired account must never accept a new posting. An account that exists
     * but is flagged inactive is Locked: its history is intact and it can be
     * re-activated by an administrator.
     *
     * @param  bool  $isActive  ledger_accounts.is_active
     * @param  bool  $isSoftDeleted  whether deleted_at is set
     */
    public static function fromFlags(bool $isActive, bool $isSoftDeleted = false): self
    {
        if ($isSoftDeleted) {
            return self::Closed;
        }

        return $isActive ? self::Active : self::Locked;
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Locked => 'Locked',
            self::Closed => 'Closed',
        };
    }

    /**
     * Whether new ledger entries may be posted against the account.
     */
    public function canPost(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether the account may be returned to Active by an administrator.
     */
    public function isRestorable(): bool
    {
        return $this === self::Locked;
    }

    /**
     * Terminal statuses can never change again.
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /**
     * The is_active value that corresponds to this status, for callers that
     * need to write the stored column.
     */
    public function toIsActiveFlag(): bool
    {
        return $this === self::Active;
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Locked => 'orange',
            self::Closed => 'red',
        };
    }
}
