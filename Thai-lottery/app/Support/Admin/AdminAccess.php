<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\User;

/**
 * WHY THIS EXISTS
 *
 * The admin panel needs one answer to "may this operator do this?", and the project
 * arrived with two incompatible ones:
 *
 *   - config('permission.permissions') is a catalogue of human-readable phrases
 *     ('manage draws', 'view audit logs', ...) and RolePermissionSeeder grants exactly
 *     those to the five roles.
 *   - App\Policies\BasePolicy builds dot-prefixed abilities instead ('draw.view',
 *     'wallet.update'), which no seeder ever creates. Every such policy therefore
 *     returns false for everyone except super-admin, who passes through the
 *     Gate::before short-circuit in AuthServiceProvider.
 *
 * That mismatch is recorded as a finding in AUDIT-2026-08-31.md. It is deliberately NOT
 * "fixed" by renaming permissions here: the policies are covered by existing tests and
 * drive the API surface, so changing their vocabulary is a separate, breaking decision.
 *
 * Instead the panel authorizes against the seeded catalogue - the vocabulary the roles
 * actually carry - through this one class. Every Filament resource and page asks these
 * methods and nothing else, so the panel's authorization model is auditable in a single
 * file rather than spread across forty resource classes.
 *
 * WHAT IS DELIBERATELY NOT DONE HERE
 * - No permission is created, granted or revoked. This class only reads.
 * - No role hierarchy is invented beyond the super-admin bypass Laravel already applies.
 * - Nothing here decides whether a domain operation is *legal* (a draw can only be
 *   closed from Open, a deposit can only be approved once). That stays in the domain
 *   services; this only decides who is allowed to ask.
 */
final class AdminAccess
{
    /**
     * Roles that may reach the panel at all.
     *
     * 'agent' and 'player' are excluded on purpose: the agent portal is Phase 9 and is
     * not this panel.
     *
     * @var list<string>
     */
    public const PANEL_ROLES = ['super-admin', 'admin', 'auditor'];

    // The seeded permission catalogue, as constants so a typo is a fatal error rather
    // than a silently denied (or silently granted) screen.
    public const VIEW_DASHBOARD = 'view dashboard';

    public const VIEW_DRAWS = 'view draws';

    public const MANAGE_DRAWS = 'manage draws';

    public const PROCESS_DRAWS = 'process draws';

    public const VIEW_RESULTS = 'view results';

    public const PROCESS_SETTLEMENTS = 'process settlements';

    public const MANAGE_PAYOUTS = 'manage payouts';

    public const VIEW_TRANSACTION_HISTORY = 'view transaction history';

    public const VIEW_FINANCIAL_REPORTS = 'view financial reports';

    public const RECONCILE_LEDGER = 'reconcile ledger';

    public const MANAGE_WALLET = 'manage wallet';

    public const MANAGE_USERS = 'manage users';

    public const MANAGE_AGENTS = 'manage agents';

    public const VIEW_COMMISSIONS = 'view commissions';

    public const VIEW_RISK_ALERTS = 'view risk alerts';

    public const MANAGE_RISK_LIMITS = 'manage risk limits';

    public const VIEW_AUDIT_LOGS = 'view audit logs';

    public const MANAGE_SYSTEM_SETTINGS = 'manage system settings';

    /**
     * May this user open the admin panel?
     *
     * Three conditions, all of them necessary: an operator role, an account status that
     * permits login (so a suspended admin loses the panel the moment they are
     * suspended, not at the next session), and the dashboard permission.
     */
    public static function canAccessPanel(?User $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        // Deliberately stricter than UserStatus::canLogin(), which also admits
        // PendingVerification. A player may browse with an unverified email; an operator
        // may not publish an official result or approve a withdrawal from an account
        // nobody has confirmed belongs to them. Only a fully Active account gets in.
        if (! $user->status->canTransact()) {
            return false;
        }

        if (! $user->hasAnyRole(self::PANEL_ROLES)) {
            return false;
        }

        return self::allows($user, self::VIEW_DASHBOARD);
    }

    /**
     * Does this user hold the given seeded permission?
     *
     * super-admin is allowed everything, mirroring the Gate::before rule the rest of
     * the application already relies on.
     */
    public static function allows(?User $user, string $permission): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if (! $user->status->canLogin()) {
            return false;
        }

        if ($user->hasRole('super-admin')) {
            return true;
        }

        return $user->hasPermissionTo($permission, 'web');
    }

    /**
     * Does this user hold at least one of the given permissions?
     *
     * @param  list<string>  $permissions
     */
    public static function allowsAny(?User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (self::allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The authenticated panel user, or null.
     */
    public static function operator(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Convenience wrapper for resources: does the current operator hold this permission?
     */
    public static function current(string $permission): bool
    {
        return self::allows(self::operator(), $permission);
    }

    /**
     * @param  list<string>  $permissions
     */
    public static function currentAny(array $permissions): bool
    {
        return self::allowsAny(self::operator(), $permissions);
    }

    /**
     * A read-only operator is one who may look at money but not move it.
     *
     * The 'auditor' role exists precisely for this, and the panel must never present it
     * a button it is not allowed to press.
     */
    public static function isReadOnly(?User $user = null): bool
    {
        $user ??= self::operator();

        if (! $user instanceof User) {
            return true;
        }

        if ($user->hasRole('super-admin')) {
            return false;
        }

        return $user->hasRole('auditor') && ! $user->hasAnyRole(['admin']);
    }

    /**
     * What this class does and does not decide, for the record.
     *
     * @return array<string, mixed>
     */
    public static function audit(): array
    {
        return [
            'vocabulary' => 'config(permission.permissions)',
            'grants_permissions' => false,
            'writes_anything' => false,
            'super_admin_bypass' => true,
            'panel_roles' => self::PANEL_ROLES,
            'known_divergence' => 'App\\Policies\\BasePolicy uses dot-prefixed abilities that are never seeded.',
        ];
    }
}
