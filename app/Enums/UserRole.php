<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The five platform roles, mirrored to the spatie/laravel-permission matrix.
 *
 * THE SINGLE SOURCE OF TRUTH FOR ROLE NAMES
 * The backing value of each case is EXACTLY the role name that
 * database/seeders/RolePermissionSeeder.php creates and syncs permissions
 * into. Code that needs to refer to a role references this enum rather than
 * embedding the string, so a role rename ripples to one diff instead of a
 * scavenger hunt through middleware, seeders and tests.
 *
 * PRIVILEGE LADDER (lowest to highest)
 *   player      — commissioned-less end user of the betting surface
 *   agent       — player-facing reseller: dashboard, draws, wallet, commissions
 *   auditor     — read-only financial observer: audit logs, reports,
 *                 reconciliation runs; can never move money or grant access
 *   admin       — operations staff: every configured permission except
 *                 platform-level system settings
 *   super-admin — unrestricted; the only role that manages system settings
 *
 * GUARD
 * All roles are seeded on the 'web' guard; the value doubles as the API
 * surface name as the public API operates the same guard.
 */
enum UserRole: string
{
    case Player = 'player';
    case Agent = 'agent';
    case Auditor = 'auditor';
    case Admin = 'admin';
    case SuperAdmin = 'super-admin';

    public function label(): string
    {
        return match ($this) {
            self::Player => 'Player',
            self::Agent => 'Agent',
            self::Auditor => 'Auditor',
            self::Admin => 'Administrator',
            self::SuperAdmin => 'Super Administrator',
        };
    }

    /**
     * Human one-liner of what the role is FOR, used in operator tooling and
     * permission review screens.
     */
    public function description(): string
    {
        return match ($this) {
            self::Player => 'Buys bets and manages their own wallet.',
            self::Agent => 'Sells to referred players and earns commissions.',
            self::Auditor => 'Inspects finance and audit trails read-only.',
            self::Admin => 'Runs platform operations day to day.',
            self::SuperAdmin => 'Owns unrestricted platform control.',
        };
    }

    /**
     * The permission set granted at seed time, in config('permission.*')
     * vocabulary. Kept beside the role so the intended matrix is readable
     * without cross-referencing the seeder.
     *
     * @return list<string>
     */
    public function seededPermissions(): array
    {
        return match ($this) {
            self::Player => [
                'view dashboard',
                'place bets',
                'view draws',
                'view results',
                'manage wallet',
                'deposit funds',
                'withdraw funds',
                'view transaction history',
            ],
            self::Agent => [
                'view dashboard',
                'view draws',
                'view results',
                'manage wallet',
                'view transaction history',
                'view commissions',
            ],
            self::Auditor => [
                'view dashboard',
                'view audit logs',
                'view financial reports',
                'view transaction history',
                'reconcile ledger',
            ],
            self::Admin, self::SuperAdmin => [], // granted from config matrix minus/plus settings
        };
    }

    /**
     * Whether the role reaches staff-facing surfaces (Filament panel, admin
     * API). Players and agents live on the player surface only.
     */
    public function isStaff(): bool
    {
        return in_array($this, [self::Auditor, self::Admin, self::SuperAdmin], true);
    }

    /**
     * Whether the role can ever mutate money (debit/credit wallets other than
     * its own, approve withdrawals, run settlements). Auditors explicitly
     * cannot — that separation is the whole point of the role.
     */
    public function canOperateMoney(): bool
    {
        return in_array($this, [self::Admin, self::SuperAdmin], true);
    }

    /**
     * Whether the role may manage other users' access (roles, permissions,
     * system settings).
     */
    public function canManageSystem(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Numeric privilege rank for comparisons: higher means more powerful.
     * Useful when answering "may X act on Y" without enumerating cases.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Player => 10,
            self::Agent => 20,
            self::Auditor => 30,
            self::Admin => 40,
            self::SuperAdmin => 50,
        };
    }

    /**
     * Whether $this outranks $other on the privilege ladder.
     */
    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /**
     * Color for admin badges, matching Filament color vocabulary.
     */
    public function color(): string
    {
        return match ($this) {
            self::Player => 'gray',
            self::Agent => 'blue',
            self::Auditor => 'purple',
            self::Admin => 'orange',
            self::SuperAdmin => 'red',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Role names reserved for staff surfaces.
     *
     * @return list<string>
     */
    public static function staffValues(): array
    {
        return [self::Auditor->value, self::Admin->value, self::SuperAdmin->value];
    }
}
