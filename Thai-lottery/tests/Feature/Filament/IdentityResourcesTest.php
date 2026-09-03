<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\AgentStatus;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\RiskLevel;
use App\Enums\UserStatus;
use App\Filament\Resources\AgentResource;
use App\Filament\Resources\AgentResource\Pages\ListAgents;
use App\Filament\Resources\AgentResource\Pages\ViewAgent;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Filament\Resources\UserResource\UserAccountActions;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The identity and compliance screens: who a person is, what they may do, and what was
 * done to them.
 *
 * WHY THIS EXISTS
 * Three properties are worth more here than any amount of column formatting, and each one
 * is a way the panel could quietly become dangerous:
 *
 *   1. The audit trail must be unwritable. Not "unwritable unless you are an admin" —
 *      unwritable, including for the super-admin whose whole definition elsewhere is
 *      "allowed everything". A trail with an edit button is a trail nobody can rely on in
 *      an investigation, so the guarantee is asserted against the most privileged user in
 *      the system rather than the least.
 *   2. Privilege must not be self-issuing. An admin who can hand themselves super-admin
 *      has made every other permission check in the application decorative, and a
 *      super-admin who can drop their own role can strand an installation with nobody
 *      able to restore it. Both directions are asserted.
 *   3. Status is a decision with a reason, not a dropdown. The set of transitions offered
 *      on a suspended account is asserted exactly, because an operator who is shown
 *      "suspend" on an already-suspended account learns to distrust the whole screen.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE
 * The seeded permission matrix itself (RolePermissionSeeder's own concern) and panel
 * access at the door (AdminPanelAccessTest). This file only tests what the three
 * resources do once someone is inside.
 */
final class IdentityResourcesTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = \DB::connection()->getDatabaseName();

        $this->assertContains(
            basename((string) $database),
            self::ALLOWED_DATABASES,
            'Refusing to run destructive tests against database: '.$database,
        );

        $this->seed(RolePermissionSeeder::class);
    }

    private function operator(string $role = 'admin', UserStatus $status = UserStatus::Active): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->assignRole($role);

        return $user->fresh();
    }

    private function subject(UserStatus $status = UserStatus::Active, ?string $role = 'player'): User
    {
        $user = User::factory()->create(['status' => $status]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user->fresh();
    }

    /**
     * Agent has no factory and the money columns are real, so the row is built explicitly
     * with honest values rather than faker noise.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function agent(User $user, array $attributes = []): Agent
    {
        return Agent::query()->create(array_merge([
            'agent_code' => 'AG-'.str_pad((string) $user->getKey(), 6, '0', STR_PAD_LEFT),
            'user_id' => $user->getKey(),
            'status' => AgentStatus::Active,
            'currency' => Currency::THB,
            'commission_rate' => '0.0250',
            'total_referrals' => 12,
            'total_commission_earned' => '15000.50',
            'total_commission_paid' => '9000.25',
            'approved_at' => now()->subMonth(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function auditLog(array $attributes = []): AuditLog
    {
        // created_at is not fillable (the model is append-only and lets the framework
        // stamp it), so a back-dated fixture is written with a follow-up query rather
        // than by loosening the model.
        $createdAt = $attributes['created_at'] ?? null;
        unset($attributes['created_at']);

        $entry = AuditLog::query()->create(array_merge([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => User::class,
            'auditable_id' => 1,
            'description' => 'Account suspended from the admin panel. Chargeback investigation.',
            'old_values' => ['status' => 'active'],
            'new_values' => ['status' => 'suspended'],
            'ip_address' => '203.0.113.9',
            'method' => 'POST',
            'url' => 'http://localhost/admin/users/1',
            'metadata' => ['source' => 'admin-panel'],
        ], $attributes));

        if ($createdAt !== null) {
            AuditLog::query()->whereKey($entry->getKey())->update(['created_at' => $createdAt]);
            $entry->refresh();
        }

        return $entry;
    }

    // =========================================================================
    // UserResource — rendering and permissions.
    // =========================================================================

    public function test_user_list_renders_for_an_admin_and_shows_a_record(): void
    {
        $subject = $this->subject();

        $this->actingAs($this->operator())
            ->get(UserResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($subject->username);
    }

    public function test_user_view_page_renders(): void
    {
        $subject = $this->subject();

        $this->actingAs($this->operator())
            ->get(UserResource::getUrl('view', ['record' => $subject]))
            ->assertSuccessful()
            ->assertSee($subject->email);
    }

    public function test_user_edit_page_renders(): void
    {
        $subject = $this->subject();

        $this->actingAs($this->operator())
            ->get(UserResource::getUrl('edit', ['record' => $subject]))
            ->assertSuccessful();
    }

    public function test_player_is_forbidden_from_the_user_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(UserResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_auditor_is_forbidden_from_the_user_resource(): void
    {
        // The seeded auditor role holds 'view audit logs' but not 'manage users'. A
        // read-only compliance operator has no business browsing phone numbers.
        $this->actingAs($this->operator('auditor'))
            ->get(UserResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_users_can_never_be_deleted_or_created_from_the_panel(): void
    {
        $subject = $this->subject();

        $this->actingAs($this->operator('super-admin'));

        $this->assertFalse(UserResource::canDelete($subject), 'Users own financial history and must not be deletable.');
        $this->assertFalse(UserResource::canDeleteAny());
        $this->assertFalse(UserResource::canCreate());
    }

    // =========================================================================
    // UserResource — the profile form and its password rules.
    // =========================================================================

    public function test_editing_a_profile_saves_identity_fields(): void
    {
        $subject = $this->subject();

        Livewire::actingAs($this->operator())
            ->test(EditUser::class, ['record' => $subject->getKey()])
            ->fillForm([
                'name' => 'Corrected Name',
                'username' => 'corrected_username',
                'email' => 'corrected@example.test',
                'phone' => '+8801700000001',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $subject->refresh();

        $this->assertSame('Corrected Name', $subject->name);
        $this->assertSame('corrected_username', $subject->username);
        $this->assertSame('corrected@example.test', $subject->email);
    }

    public function test_the_edit_form_never_exposes_the_stored_password_hash(): void
    {
        $subject = $this->subject();

        Livewire::actingAs($this->operator())
            ->test(EditUser::class, ['record' => $subject->getKey()])
            ->assertFormFieldExists('password')
            ->assertFormSet(['password' => null]);
    }

    public function test_saving_with_a_blank_password_leaves_the_credential_untouched(): void
    {
        $subject = $this->subject();
        $originalHash = $subject->password;

        Livewire::actingAs($this->operator())
            ->test(EditUser::class, ['record' => $subject->getKey()])
            ->fillForm(['name' => 'Still The Same Person', 'password' => null])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($originalHash, $subject->fresh()->password);
    }

    public function test_setting_a_password_hashes_it_once_through_the_model_cast(): void
    {
        $subject = $this->subject();

        Livewire::actingAs($this->operator())
            ->test(EditUser::class, ['record' => $subject->getKey()])
            ->fillForm(['password' => 'a-brand-new-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = $subject->fresh()->password;

        $this->assertNotSame('a-brand-new-password', $stored, 'The password must not be stored in plain text.');
        $this->assertTrue(
            Hash::check('a-brand-new-password', $stored),
            'The password must be hashed exactly once — a double hash would lock the account out.',
        );
    }

    public function test_the_edit_form_offers_no_status_or_role_field(): void
    {
        $subject = $this->subject();

        // Status and roles are decisions with a reason and an audit line, so they must not
        // be reachable as ordinary form state.
        $fields = array_keys(UserResource::form(new \Filament\Forms\Form(
            Livewire::actingAs($this->operator())->test(EditUser::class, ['record' => $subject->getKey()])->instance()
        ))->getFlatFields(withHidden: true));

        $this->assertNotContains('status', $fields);
        $this->assertNotContains('roles', $fields);
    }

    // =========================================================================
    // UserResource — status actions.
    // =========================================================================

    public function test_an_active_account_offers_suspend_deactivate_and_ban_but_not_reactivate(): void
    {
        $subject = $this->subject(UserStatus::Active);

        Livewire::actingAs($this->operator())
            ->test(ListUsers::class)
            ->assertTableActionVisible('suspend', record: $subject)
            ->assertTableActionVisible('deactivate', record: $subject)
            ->assertTableActionVisible('ban', record: $subject)
            ->assertTableActionHidden('reactivate', record: $subject);
    }

    public function test_a_suspended_account_offers_exactly_reactivate_and_ban(): void
    {
        $subject = $this->subject(UserStatus::Suspended);

        $this->actingAs($this->operator());

        $offered = array_values(array_filter(
            array_keys(UserAccountActions::TRANSITIONS),
            fn (string $name): bool => UserAccountActions::canOffer($name, $subject),
        ));

        $this->assertSame(['reactivate', 'ban'], $offered);

        Livewire::actingAs($this->operator())
            ->test(ListUsers::class)
            ->assertTableActionVisible('reactivate', record: $subject)
            ->assertTableActionVisible('ban', record: $subject)
            ->assertTableActionHidden('suspend', record: $subject)
            ->assertTableActionHidden('deactivate', record: $subject);
    }

    public function test_a_banned_account_offers_only_reactivate(): void
    {
        $subject = $this->subject(UserStatus::Banned);

        $this->actingAs($this->operator());

        $offered = array_values(array_filter(
            array_keys(UserAccountActions::TRANSITIONS),
            fn (string $name): bool => UserAccountActions::canOffer($name, $subject),
        ));

        $this->assertSame(['reactivate'], $offered);
    }

    public function test_suspending_requires_a_reason(): void
    {
        $subject = $this->subject(UserStatus::Active);

        Livewire::actingAs($this->operator())
            ->test(ViewUser::class, ['record' => $subject->getKey()])
            ->callAction('suspend', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(UserStatus::Active, $subject->fresh()->status);
    }

    public function test_suspending_writes_the_status_and_an_audit_entry(): void
    {
        $operator = $this->operator();
        $subject = $this->subject(UserStatus::Active);

        Livewire::actingAs($operator)
            ->test(ViewUser::class, ['record' => $subject->getKey()])
            ->callAction('suspend', data: ['reason' => 'Chargeback investigation opened by finance.'])
            ->assertHasNoActionErrors();

        $this->assertSame(UserStatus::Suspended, $subject->fresh()->status);

        $entry = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $subject->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'A status change must leave an audit entry.');
        $this->assertSame($operator->getKey(), $entry->user_id);
        $this->assertSame(['status' => 'active'], $entry->old_values);
        $this->assertSame(['status' => 'suspended'], $entry->new_values);
        $this->assertStringContainsString('Chargeback investigation', (string) $entry->description);
    }

    public function test_reactivating_a_suspended_account_restores_active(): void
    {
        $subject = $this->subject(UserStatus::Suspended);

        Livewire::actingAs($this->operator())
            ->test(ViewUser::class, ['record' => $subject->getKey()])
            ->callAction('reactivate', data: ['reason' => 'Investigation closed, no finding.'])
            ->assertHasNoActionErrors();

        $this->assertSame(UserStatus::Active, $subject->fresh()->status);
    }

    public function test_a_stale_screen_cannot_apply_an_illegal_transition(): void
    {
        $subject = $this->subject(UserStatus::Active);
        $operator = $this->operator();

        // Two operators, one account: the page rendered while the account was Active and
        // offered "suspend", but by the time the button is pressed someone else has banned
        // it. The guard must refuse with a notification rather than downgrade a ban to a
        // suspension, and must not 500.
        $page = Livewire::actingAs($operator)
            ->test(ViewUser::class, ['record' => $subject->getKey()])
            ->assertActionVisible('suspend');

        User::query()->whereKey($subject->getKey())->update(['status' => UserStatus::Banned->value]);

        $page->callAction('suspend', data: ['reason' => 'Posted from a stale screen.'])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertSame(UserStatus::Banned, $subject->fresh()->status);
        $this->assertSame(
            0,
            AuditLog::query()->where('auditable_id', $subject->getKey())->count(),
            'A refused transition must not leave an audit entry.',
        );
    }

    public function test_an_operator_cannot_change_their_own_status(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator);

        $this->assertFalse(UserAccountActions::canOffer('suspend', $operator));
        $this->assertFalse(UserAccountActions::canOffer('ban', $operator));
        $this->assertFalse(UserAccountActions::canOffer('deactivate', $operator));
    }

    public function test_an_admin_cannot_suspend_a_super_admin_but_a_super_admin_can(): void
    {
        $target = $this->subject(UserStatus::Active, 'super-admin');

        $this->actingAs($this->operator('admin'));
        $this->assertFalse(UserAccountActions::canOffer('suspend', $target));

        $this->actingAs($this->operator('super-admin'));
        $this->assertTrue(UserAccountActions::canOffer('suspend', $target));
    }

    // =========================================================================
    // UserResource — role assignment.
    // =========================================================================

    public function test_an_admin_can_assign_an_ordinary_role(): void
    {
        $subject = $this->subject(UserStatus::Active, null);

        Livewire::actingAs($this->operator('admin'))
            ->test(ViewUser::class, ['record' => $subject->getKey()])
            ->callAction('manageRoles', data: ['roles' => ['auditor']])
            ->assertHasNoActionErrors();

        $this->assertTrue($subject->fresh()->hasRole('auditor'));
    }

    public function test_role_changes_are_recorded_in_the_audit_trail(): void
    {
        $subject = $this->subject(UserStatus::Active, null);
        $operator = $this->operator('admin');

        Livewire::actingAs($operator)
            ->test(ViewUser::class, ['record' => $subject->getKey()])
            ->callAction('manageRoles', data: ['roles' => ['agent']])
            ->assertHasNoActionErrors();

        $entry = AuditLog::query()
            ->where('auditable_type', User::class)
            ->where('auditable_id', $subject->getKey())
            ->latest('id')
            ->first();

        $this->assertNotNull($entry);
        $this->assertSame(AuditAction::RoleAssign, $entry->action);
        $this->assertSame(['roles' => []], $entry->old_values);
        $this->assertSame(['roles' => ['agent']], $entry->new_values);
    }

    public function test_only_a_super_admin_may_grant_super_admin(): void
    {
        $subject = $this->subject(UserStatus::Active, null);

        Livewire::actingAs($this->operator('admin'))
            ->test(ViewUser::class, ['record' => $subject->getKey()])
            ->callAction('manageRoles', data: ['roles' => ['super-admin']])
            ->assertHasActionErrors(['roles']);

        $this->assertFalse($subject->fresh()->hasRole('super-admin'));

        Livewire::actingAs($this->operator('super-admin'))
            ->test(ViewUser::class, ['record' => $subject->getKey()])
            ->callAction('manageRoles', data: ['roles' => ['super-admin']])
            ->assertHasNoActionErrors();

        $this->assertTrue($subject->fresh()->hasRole('super-admin'));
    }

    public function test_an_admin_cannot_escalate_themselves_to_super_admin(): void
    {
        $operator = $this->operator('admin');

        Livewire::actingAs($operator)
            ->test(ViewUser::class, ['record' => $operator->getKey()])
            ->callAction('manageRoles', data: ['roles' => ['admin', 'super-admin']])
            ->assertHasActionErrors(['roles']);

        $this->assertFalse($operator->fresh()->hasRole('super-admin'));
    }

    public function test_an_admin_cannot_revoke_someone_elses_super_admin(): void
    {
        $target = $this->subject(UserStatus::Active, 'super-admin');

        Livewire::actingAs($this->operator('admin'))
            ->test(ViewUser::class, ['record' => $target->getKey()])
            ->callAction('manageRoles', data: ['roles' => []])
            ->assertHasActionErrors(['roles']);

        $this->assertTrue($target->fresh()->hasRole('super-admin'));
    }

    public function test_a_super_admin_cannot_strip_their_own_super_admin_role(): void
    {
        $operator = $this->operator('super-admin');

        Livewire::actingAs($operator)
            ->test(ViewUser::class, ['record' => $operator->getKey()])
            ->callAction('manageRoles', data: ['roles' => ['admin']])
            ->assertHasActionErrors(['roles']);

        $this->assertTrue($operator->fresh()->hasRole('super-admin'));
    }

    public function test_a_super_admin_may_strip_another_super_admins_role(): void
    {
        $target = $this->subject(UserStatus::Active, 'super-admin');

        Livewire::actingAs($this->operator('super-admin'))
            ->test(ViewUser::class, ['record' => $target->getKey()])
            ->callAction('manageRoles', data: ['roles' => ['admin']])
            ->assertHasNoActionErrors();

        $target->refresh();

        $this->assertFalse($target->hasRole('super-admin'));
        $this->assertTrue($target->hasRole('admin'));
    }

    // =========================================================================
    // AgentResource.
    // =========================================================================

    public function test_agent_list_renders_for_an_admin_and_shows_a_record(): void
    {
        $agent = $this->agent($this->subject(UserStatus::Active, 'agent'));

        $this->actingAs($this->operator())
            ->get(AgentResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($agent->agent_code);
    }

    public function test_agent_view_page_renders(): void
    {
        $agent = $this->agent($this->subject(UserStatus::Active, 'agent'));

        $this->actingAs($this->operator())
            ->get(AgentResource::getUrl('view', ['record' => $agent]))
            ->assertSuccessful();
    }

    public function test_player_is_forbidden_from_the_agent_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(AgentResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_auditor_is_forbidden_from_the_agent_resource(): void
    {
        // The seeded auditor holds neither 'manage agents' nor 'view commissions'.
        $this->actingAs($this->operator('auditor'))
            ->get(AgentResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_commission_amounts_render_as_exact_strings_not_floats(): void
    {
        $agent = $this->agent($this->subject(UserStatus::Active, 'agent'), [
            'total_commission_earned' => '1234567.50',
        ]);

        Livewire::actingAs($this->operator())
            ->test(ListAgents::class)
            ->assertCanSeeTableRecords([$agent])
            ->assertSee('1,234,567.50 THB');
    }

    public function test_commission_columns_are_gated_behind_the_commission_permission(): void
    {
        $this->agent($this->subject(UserStatus::Active, 'agent'));

        // 'admin' holds every permission except 'manage system settings', so it sees them.
        $this->actingAs($this->operator('admin'));
        $this->assertTrue(AgentResource::canViewCommissions());

        // A hand-built operator with 'manage agents' but not 'view commissions' must not.
        $restricted = User::factory()->create(['status' => UserStatus::Active]);
        $restricted->assignRole('admin');
        $restricted->roles()->detach();
        $restricted->givePermissionTo(\App\Support\Admin\AdminAccess::VIEW_DASHBOARD);
        $restricted->givePermissionTo(\App\Support\Admin\AdminAccess::MANAGE_AGENTS);
        $restricted = $restricted->fresh();

        $this->actingAs($restricted);
        $this->assertTrue(AgentResource::canViewAny());
        $this->assertFalse(AgentResource::canViewCommissions());

        Livewire::actingAs($restricted)
            ->test(ListAgents::class)
            ->assertTableColumnHidden('total_commission_earned')
            ->assertTableColumnHidden('total_commission_paid')
            ->assertTableColumnHidden('commission_rate');
    }

    public function test_agents_are_never_writable_from_the_panel(): void
    {
        $agent = $this->agent($this->subject(UserStatus::Active, 'agent'));

        $this->actingAs($this->operator('super-admin'));

        $this->assertFalse(AgentResource::canCreate());
        $this->assertFalse(AgentResource::canEdit($agent), 'No agent or commission service exists to delegate a write to.');
        $this->assertFalse(AgentResource::canDelete($agent));
        $this->assertFalse(AgentResource::canDeleteAny());
        $this->assertArrayNotHasKey('edit', AgentResource::getPages());
    }

    public function test_the_agent_view_page_offers_no_actions(): void
    {
        $agent = $this->agent($this->subject(UserStatus::Active, 'agent'));

        $actions = Livewire::actingAs($this->operator('super-admin'))
            ->test(ViewAgent::class, ['record' => $agent->getKey()])
            ->instance()
            ->getCachedHeaderActions();

        $this->assertSame([], $actions);
    }

    // =========================================================================
    // AuditLogResource — readability.
    // =========================================================================

    public function test_audit_log_list_renders_for_an_admin_and_shows_a_record(): void
    {
        $entry = $this->auditLog(['description' => 'A distinctive audited event.']);

        $this->actingAs($this->operator())
            ->get(AuditLogResource::getUrl('index'))
            ->assertSuccessful();

        Livewire::actingAs($this->operator())
            ->test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$entry])
            ->assertSee('A distinctive audited event.');
    }

    public function test_audit_log_list_renders_for_an_auditor(): void
    {
        // The auditor role exists for exactly this screen: 'view audit logs' is one of the
        // five permissions it holds.
        $entry = $this->auditLog();

        $this->actingAs($this->operator('auditor'))
            ->get(AuditLogResource::getUrl('index'))
            ->assertSuccessful();

        Livewire::actingAs($this->operator('auditor'))
            ->test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$entry]);
    }

    public function test_audit_log_view_page_renders_a_readable_diff(): void
    {
        $entry = $this->auditLog([
            'old_values' => ['status' => 'active', 'roles' => ['player']],
            'new_values' => ['status' => 'banned', 'roles' => ['player']],
        ]);

        $this->actingAs($this->operator('auditor'))
            ->get(AuditLogResource::getUrl('view', ['record' => $entry]))
            ->assertSuccessful()
            // Only the field that actually moved is reported, and it is reported as a
            // before/after pair rather than two opaque JSON blobs.
            ->assertSee('status: active → banned', escape: false)
            ->assertDontSee('roles: ', escape: false);
    }

    public function test_player_is_forbidden_from_the_audit_log_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(AuditLogResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_the_actor_action_and_subject_type_filters_narrow_the_list(): void
    {
        $actor = $this->operator('admin');

        $mine = $this->auditLog([
            'user_id' => $actor->getKey(),
            'action' => AuditAction::RoleAssign,
            'auditable_type' => User::class,
        ]);

        $other = $this->auditLog([
            'user_id' => null,
            'action' => AuditAction::Login,
            'auditable_type' => null,
            'auditable_id' => null,
        ]);

        Livewire::actingAs($actor)
            ->test(ListAuditLogs::class)
            ->assertCanSeeTableRecords([$mine, $other])
            ->filterTable('user_id', [$actor->getKey()])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other])
            ->resetTableFilters()
            ->filterTable('action', [AuditAction::Login->value])
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$mine])
            ->resetTableFilters()
            ->filterTable('auditable_type', [User::class])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_date_range_filter_narrows_the_list(): void
    {
        $old = $this->auditLog(['created_at' => now()->subDays(30)]);
        $recent = $this->auditLog(['created_at' => now()]);

        Livewire::actingAs($this->operator('auditor'))
            ->test(ListAuditLogs::class)
            ->filterTable('created_at', ['from' => now()->subDays(2)->toDateString()])
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);
    }

    // =========================================================================
    // AuditLogResource — the guarantee.
    // =========================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function everyPanelRoleProvider(): array
    {
        return [
            'super-admin' => ['super-admin'],
            'admin' => ['admin'],
            'auditor' => ['auditor'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('everyPanelRoleProvider')]
    public function test_no_one_can_edit_or_delete_an_audit_log(string $role): void
    {
        $entry = $this->auditLog();

        $this->actingAs($this->operator($role));

        $this->assertFalse(AuditLogResource::canEdit($entry), $role.' must not be able to edit an audit entry.');
        $this->assertFalse(AuditLogResource::canDelete($entry), $role.' must not be able to delete an audit entry.');
        $this->assertFalse(AuditLogResource::canDeleteAny(), $role.' must not be able to bulk-delete audit entries.');
        $this->assertFalse(AuditLogResource::canCreate(), $role.' must not be able to fabricate an audit entry.');
        $this->assertFalse(AuditLogResource::canForceDelete($entry));
        $this->assertFalse(AuditLogResource::canRestore($entry));
    }

    public function test_the_audit_log_resource_registers_no_create_or_edit_route(): void
    {
        // Not merely denied — absent. There is no URL to guess.
        $pages = AuditLogResource::getPages();

        $this->assertSame(['index', 'view'], array_keys($pages));
    }

    public function test_the_audit_log_edit_url_does_not_exist(): void
    {
        $entry = $this->auditLog();

        $this->actingAs($this->operator('super-admin'));

        $this->expectException(\Exception::class);

        AuditLogResource::getUrl('edit', ['record' => $entry]);
    }

    public function test_the_audit_log_table_exposes_no_bulk_actions_and_no_delete_row_action(): void
    {
        $this->auditLog();

        $table = Livewire::actingAs($this->operator('super-admin'))
            ->test(ListAuditLogs::class)
            ->instance()
            ->getTable();

        $this->assertSame([], $table->getBulkActions(), 'An append-only table has nothing to bulk-do.');
        $this->assertArrayNotHasKey('delete', $table->getActions());
        $this->assertArrayNotHasKey('edit', $table->getActions());
    }

    public function test_the_audit_log_view_page_offers_no_header_actions(): void
    {
        $entry = $this->auditLog();

        $actions = Livewire::actingAs($this->operator('super-admin'))
            ->test(ViewAuditLog::class, ['record' => $entry->getKey()])
            ->instance()
            ->getCachedHeaderActions();

        $this->assertSame([], $actions, 'No edit and no delete button, for anyone.');
    }

    public function test_the_audit_log_model_itself_records_no_update_timestamp(): void
    {
        // Belt and braces at the storage layer: even a direct save() cannot record when a
        // row was tampered with, because there is no column to record it in.
        $this->assertNull(AuditLog::UPDATED_AT);
        $this->assertFalse(
            \Schema::hasColumn('audit_logs', 'deleted_at'),
            'audit_logs must have no soft-delete column.',
        );
    }
}
