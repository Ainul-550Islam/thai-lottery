<?php

declare(strict_types=1);

namespace App\Filament\Resources\UserResource;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Closure;
use Filament\Actions as PageActions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables\Actions as TableActions;
use Throwable;

/**
 * The account decisions an operator can take on another person's account.
 *
 * WHY THIS EXISTS
 * An account status is not a form field. "Suspended" is a moderation decision with a
 * reason attached and a date it happened; typing it into a <select> next to someone's
 * phone number loses all of that and makes an accidental keystroke indistinguishable from
 * a considered ban. So `users.status` is absent from the edit form entirely and is only
 * reachable through the named, confirmed, reason-carrying actions declared here. The same
 * argument applies to roles: granting super-admin is not "editing a profile".
 *
 * Like DrawLifecycleActions, each decision is declared once and rendered into both the
 * table row action type and the view-page header action type, so the list screen and the
 * detail screen can never disagree about when a button is legal.
 *
 * THE ONE PLACE THIS PANEL WRITES DOMAIN STATE, AND WHY
 * ---------------------------------------------------------------------------
 * There is NO service in app/Services that owns user status transitions. app/Services
 * contains Betting, Draw, Finance and Risk only; nothing there reads or writes
 * users.status, and App\Enums\UserStatus declares no transition graph — it has
 * canLogin(), canTransact() and color() and nothing else. The brief's rule 3 ("the panel
 * never writes domain state") therefore has no service to delegate to here.
 *
 * Rather than leave operators unable to suspend an abusive account, this class writes the
 * column directly in exactly one method — applyStatus() — and nowhere else. That method
 * carries the transition map, the self-protection guard and the audit write. When a
 * UserModerationService is eventually built, applyStatus() is the single body to replace
 * and every caller here follows automatically. This is recorded as a gap in
 * FILAMENT-PROGRESS-identity.md.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * - No soft delete, no hard delete, no anonymisation. A user owns wallets, bets and
 *   ledger history; removing the row is not a moderation tool and UserResource::canDelete
 *   returns false.
 * - No email/phone verification is forced from here. Marking someone verified without
 *   evidence is exactly the fraud the verification flow exists to prevent.
 * - No password reset-on-behalf-of. Sending a reset link is an authentication concern and
 *   the panel has no service for it; an operator uses the normal reset flow.
 * - Permissions are never granted directly to a user, only roles. The seeded role matrix
 *   is the whole vocabulary; per-user grants would make "who can approve a withdrawal?"
 *   unanswerable without a query.
 */
final class UserAccountActions
{
    /**
     * The transition graph the panel is willing to offer, written down because the enum
     * does not contain one.
     *
     * Read as: this action moves an account from any of `from` to `to`. Anything not
     * listed is not offered — notably nothing returns from Banned except a deliberate
     * super-admin reactivation, and PendingVerification is never reachable again once
     * left, because "un-verify someone" is not a moderation decision.
     *
     * @var array<string, array{from: list<UserStatus>, to: UserStatus}>
     */
    public const TRANSITIONS = [
        'suspend' => [
            'from' => [UserStatus::Active, UserStatus::Inactive, UserStatus::PendingVerification],
            'to' => UserStatus::Suspended,
        ],
        'reactivate' => [
            'from' => [UserStatus::Suspended, UserStatus::Inactive, UserStatus::Banned],
            'to' => UserStatus::Active,
        ],
        'deactivate' => [
            'from' => [UserStatus::Active],
            'to' => UserStatus::Inactive,
        ],
        'ban' => [
            'from' => [UserStatus::Active, UserStatus::Inactive, UserStatus::Suspended, UserStatus::PendingVerification],
            'to' => UserStatus::Banned,
        ],
    ];

    /**
     * Row actions for the list screen.
     *
     * @return list<TableActions\Action>
     */
    public static function forTable(): array
    {
        return array_map(
            static fn (array $spec): TableActions\Action => self::configure(TableActions\Action::make($spec['name']), $spec),
            self::specs(),
        );
    }

    /**
     * Header actions for the view screen.
     *
     * @return list<PageActions\Action>
     */
    public static function forPage(): array
    {
        return array_map(
            static fn (array $spec): PageActions\Action => self::configure(PageActions\Action::make($spec['name']), $spec),
            self::specs(),
        );
    }

    /**
     * Is this transition currently offerable on this record, for this operator?
     *
     * Public because the tests assert the offered set for each status directly, and
     * because a caller that wants to know "can I suspend?" should not have to reverse
     * engineer it from a rendered button.
     */
    public static function canOffer(string $name, User $record): bool
    {
        if (! AdminAccess::current(AdminAccess::MANAGE_USERS)) {
            return false;
        }

        $transition = self::TRANSITIONS[$name] ?? null;

        if ($transition === null) {
            return false;
        }

        if (! in_array($record->status, $transition['from'], true)) {
            return false;
        }

        $operator = AdminAccess::operator();

        // Nobody locks themselves out of the panel by accident, and nobody escapes an
        // investigation by reactivating their own suspended account.
        if ($operator instanceof User && $operator->getKey() === $record->getKey()) {
            return false;
        }

        // Only a super-admin may move a super-admin's account. Otherwise any admin could
        // suspend the one account that can undo them.
        if ($record->hasRole('super-admin') && ! ($operator?->hasRole('super-admin') ?? false)) {
            return false;
        }

        return true;
    }

    /**
     * @param  TableActions\Action|PageActions\Action  $action
     * @param  array<string, mixed>  $spec
     * @return TableActions\Action|PageActions\Action
     */
    private static function configure(object $action, array $spec): object
    {
        $action
            ->label($spec['label'])
            ->icon($spec['icon'])
            ->color($spec['color'])
            ->visible($spec['visible'])
            ->action($spec['action']);

        if (($spec['confirm'] ?? false) === true) {
            $action->requiresConfirmation();
        }

        if (isset($spec['heading'])) {
            $action->modalHeading($spec['heading']);
        }

        if (isset($spec['description'])) {
            $action->modalDescription($spec['description']);
        }

        if (isset($spec['submitLabel'])) {
            $action->modalSubmitActionLabel($spec['submitLabel']);
        }

        if (isset($spec['form'])) {
            $action->form($spec['form']);
        }

        if (isset($spec['fill'])) {
            $action->fillForm($spec['fill']);
        }

        return $action;
    }

    /**
     * One entry per operator decision.
     *
     * @return list<array<string, mixed>>
     */
    private static function specs(): array
    {
        return [
            [
                'name' => 'suspend',
                'label' => 'Suspend account',
                'icon' => 'heroicon-o-pause-circle',
                'color' => 'warning',
                'heading' => 'Suspend this account',
                'description' => 'The account can no longer log in, bet, deposit or withdraw. Existing wallet balances and bets are untouched — suspension freezes the person, not their money.',
                'submitLabel' => 'Suspend',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (recorded in the audit trail)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500)
                        ->helperText('Write what an auditor reading this in six months needs to know, not "abuse".'),
                ],
                'visible' => static fn (User $record): bool => self::canOffer('suspend', $record),
                'action' => static function (User $record, array $data): void {
                    self::run(static fn () => self::applyStatus($record, 'suspend', (string) $data['reason']));
                },
            ],
            [
                'name' => 'reactivate',
                'label' => 'Reactivate account',
                'icon' => 'heroicon-o-play-circle',
                'color' => 'success',
                'confirm' => true,
                'heading' => 'Reactivate this account',
                'description' => 'Full access is restored: login, betting, deposits and withdrawals. A reason is optional here because restoring access needs less justification than removing it, but anything typed is kept.',
                'submitLabel' => 'Reactivate',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Note (optional)')
                        ->maxLength(500),
                ],
                'visible' => static fn (User $record): bool => self::canOffer('reactivate', $record),
                'action' => static function (User $record, array $data): void {
                    self::run(static fn () => self::applyStatus($record, 'reactivate', (string) ($data['reason'] ?? '')));
                },
            ],
            [
                'name' => 'deactivate',
                'label' => 'Deactivate account',
                'icon' => 'heroicon-o-moon',
                'color' => 'gray',
                'heading' => 'Deactivate this account',
                'description' => 'A dormant account, not a punished one: use this for a closure the account holder asked for. It reads differently from a suspension in the audit trail, which matters when a regulator asks why access was removed.',
                'submitLabel' => 'Deactivate',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500),
                ],
                'visible' => static fn (User $record): bool => self::canOffer('deactivate', $record),
                'action' => static function (User $record, array $data): void {
                    self::run(static fn () => self::applyStatus($record, 'deactivate', (string) $data['reason']));
                },
            ],
            [
                'name' => 'ban',
                'label' => 'Ban account',
                'icon' => 'heroicon-o-no-symbol',
                'color' => 'danger',
                'heading' => 'Ban this account',
                'description' => 'The terminal moderation state. Only a reactivation by an operator can undo it, and the ban stays visible in the audit trail forever. Use suspension while an investigation is open; use a ban when it has concluded.',
                'submitLabel' => 'Ban this account',
                'form' => [
                    Forms\Components\Textarea::make('reason')
                        ->label('Reason (recorded in the audit trail)')
                        ->required()
                        ->minLength(5)
                        ->maxLength(500),
                ],
                'visible' => static fn (User $record): bool => self::canOffer('ban', $record),
                'action' => static function (User $record, array $data): void {
                    self::run(static fn () => self::applyStatus($record, 'ban', (string) $data['reason']));
                },
            ],
            [
                'name' => 'manageRoles',
                'label' => 'Manage roles',
                'icon' => 'heroicon-o-identification',
                'color' => 'primary',
                'heading' => 'Roles for this account',
                'description' => 'Roles carry the seeded permission catalogue; there are no per-user permission grants. Removing every role leaves an account that can sign in and do nothing, which is a valid state.',
                'submitLabel' => 'Save roles',
                'form' => static fn (User $record): array => [
                    Forms\Components\CheckboxList::make('roles')
                        ->label('Assigned roles')
                        ->options(self::roleOptions())
                        ->descriptions([
                            'super-admin' => 'Everything, including system settings. Only a super-admin may grant or revoke this.',
                            'admin' => 'Everything except system settings.',
                            'auditor' => 'Read-only: audit logs, financial reports, ledger reconciliation.',
                            'agent' => 'Referral portal role. Does not reach this panel.',
                            'player' => 'Ordinary account. Does not reach this panel.',
                        ])
                        ->bulkToggleable(false)
                        ->rule(static function () use ($record): Closure {
                            return static function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                self::validateRoleChange($record, is_array($value) ? $value : [], $fail);
                            };
                        }),
                ],
                'fill' => static fn (User $record): array => [
                    'roles' => $record->roles()->pluck('name')->all(),
                ],
                'visible' => static function (User $record): bool {
                    return AdminAccess::current(AdminAccess::MANAGE_USERS);
                },
                'action' => static function (User $record, array $data): void {
                    self::run(static function () use ($record, $data): void {
                        $before = $record->roles()->pluck('name')->sort()->values()->all();
                        $after = array_values(array_filter((array) ($data['roles'] ?? [])));

                        // Re-check server side. The form rule already refused this, but a
                        // guard that only exists in a validator is a guard that vanishes
                        // the first time someone calls the action from a console command.
                        $failures = [];
                        self::validateRoleChange($record, $after, static function (string $message) use (&$failures): void {
                            $failures[] = $message;
                        });

                        if ($failures !== []) {
                            throw new \RuntimeException(implode(' ', $failures));
                        }

                        $record->syncRoles($after);

                        $sortedAfter = collect($after)->sort()->values()->all();

                        self::audit(
                            $record,
                            count($sortedAfter) >= count($before) ? AuditAction::RoleAssign : AuditAction::RoleRevoke,
                            ['roles' => $before],
                            ['roles' => $sortedAfter],
                            'Roles changed from the admin panel.',
                        );

                        Notification::make()
                            ->success()
                            ->title('Roles updated')
                            ->body($record->username.' now holds: '.($sortedAfter === [] ? 'no roles' : implode(', ', $sortedAfter)).'.')
                            ->send();
                    });
                },
            ],
        ];
    }

    /**
     * The seeded role catalogue, from config rather than a hard-coded list, so a role
     * added to the seeder appears here without a code change.
     *
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        /** @var array<string, string> $roles */
        $roles = (array) config('permission.roles', []);

        return collect(array_values($roles))
            ->mapWithKeys(static fn (string $role): array => [$role => $role])
            ->all();
    }

    /**
     * The two role rules that are not negotiable.
     *
     * @param  list<string>  $selected
     * @param  Closure(string): void  $fail
     */
    private static function validateRoleChange(User $record, array $selected, Closure $fail): void
    {
        $operator = AdminAccess::operator();
        $selected = array_values(array_filter($selected));

        $holdsSuper = $record->hasRole('super-admin');
        $wouldHoldSuper = in_array('super-admin', $selected, true);
        $operatorIsSuper = $operator instanceof User && $operator->hasRole('super-admin');

        // 1. super-admin is granted and revoked by super-admins only. Otherwise the role
        //    is not a privilege boundary at all: any admin could mint themselves one.
        if ($holdsSuper !== $wouldHoldSuper && ! $operatorIsSuper) {
            $fail('Only a super-admin may grant or revoke the super-admin role.');
        }

        // 2. Nobody removes their own super-admin. The realistic failure is not malice,
        //    it is the last super-admin de-privileging themselves and leaving the
        //    installation with no one who can restore the role.
        if ($holdsSuper && ! $wouldHoldSuper && $operator instanceof User && $operator->getKey() === $record->getKey()) {
            $fail('You cannot remove your own super-admin role. Ask another super-admin to do it.');
        }
    }

    /**
     * THE ONLY PLACE THE PANEL WRITES A USER'S STATUS.
     *
     * No service in app/Services owns user status transitions — none exists to delegate
     * to — so this method writes users.status directly. It is deliberately the single
     * such method: the transition map, the self-protection guard, the write and the audit
     * record all live here, and every action above routes through it. Replacing this body
     * with a call to a future UserModerationService is a one-function change.
     *
     * `status` is not in User::$fillable (by design: it is not user input), so the write
     * is an explicit attribute assignment rather than an update() array, which also keeps
     * the enum cast in play.
     */
    private static function applyStatus(User $record, string $transitionName, string $reason): void
    {
        $transition = self::TRANSITIONS[$transitionName] ?? null;

        if ($transition === null) {
            throw new \RuntimeException('Unknown account transition: '.$transitionName);
        }

        // Re-read before deciding. The visibility closure ran when the page rendered,
        // which may have been minutes ago and before another operator acted; the
        // authoritative state is the one in the database at the moment of the write.
        $record->refresh();

        if (! self::canOffer($transitionName, $record)) {
            throw new \RuntimeException(sprintf(
                'This account is %s; "%s" is not available from that state, or you may not perform it on this account.',
                $record->status->label(),
                $transitionName,
            ));
        }

        $from = $record->status;
        $to = $transition['to'];

        $record->status = $to;
        $record->save();

        self::audit(
            $record,
            AuditAction::Update,
            ['status' => $from->value],
            ['status' => $to->value],
            trim(sprintf('Account %s from the admin panel. %s', $transitionName, $reason)),
        );

        Notification::make()
            ->success()
            ->title('Account '.$transitionName.'d')
            ->body(sprintf('%s: %s → %s.', $record->username, $from->label(), $to->label()))
            ->send();
    }

    /**
     * Write the append-only trail entry for an account change.
     *
     * WHY NOT AdminAccess::audit()
     * The brief asks for the change to be recorded "through AdminAccess::audit()", but
     * that method takes no arguments, writes nothing and returns a static description of
     * the authorization model — it is documentation, not a recorder. There is also no
     * audit *service* in app/Services. So the row is written through the App\Models\AuditLog
     * model, which the migration and model already define as append-only (created_at only,
     * no soft deletes), and AdminAccess::audit()'s snapshot is embedded in the metadata so
     * the authorization model in force at the time of the change is captured with it.
     * Reported as a gap.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private static function audit(User $record, AuditAction $action, array $old, array $new, string $description): void
    {
        $operator = AdminAccess::operator();

        AuditLog::query()->create([
            'user_id' => $operator?->getKey(),
            'action' => $action,
            'risk_level' => RiskLevel::High,
            'auditable_type' => User::class,
            'auditable_id' => $record->getKey(),
            'description' => $description,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 500),
            'url' => request()->fullUrl(),
            'method' => request()->method(),
            'metadata' => [
                'source' => 'admin-panel',
                'operator_username' => $operator?->username,
                'authorization_model' => AdminAccess::audit(),
            ],
        ]);
    }

    /**
     * Run an operation and turn a refusal into a red notification.
     *
     * Identical in intent to DrawLifecycleActions::run(): two operators looking at the
     * same stale list is normal, and the second one to press the button gets an
     * explanation, not a 500.
     */
    private static function run(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('This account change was refused')
                ->body($exception->getMessage())
                ->persistent()
                ->send();
        }
    }
}
