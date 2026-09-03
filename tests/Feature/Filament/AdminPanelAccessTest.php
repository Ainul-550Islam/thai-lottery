<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Who can reach /admin, and who cannot.
 *
 * WHY THIS EXISTS
 * The panel is the only surface in the application that can publish an official result or
 * approve a withdrawal. Its access rule lives in exactly one place - AdminAccess -
 * reached through User::canAccessPanel(). This suite is the proof that the rule holds at
 * the HTTP boundary rather than only in the unit that declares it: a player who guesses
 * the URL, a suspended admin, and an unauthenticated visitor must all be refused, and an
 * auditor must get in but see nothing they may not act on.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE
 * Individual resource behaviour. That is each resource's own suite. This file only answers
 * the door.
 */
final class AdminPanelAccessTest extends TestCase
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

    private function userWithRole(string $role, UserStatus $status = UserStatus::Active): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_guest_is_redirected_to_the_panel_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_panel_login_page_renders(): void
    {
        $this->get('/admin/login')->assertSuccessful();
    }

    public function test_super_admin_reaches_the_dashboard(): void
    {
        $this->actingAs($this->userWithRole('super-admin'))
            ->get('/admin')
            ->assertSuccessful();
    }

    public function test_admin_reaches_the_dashboard(): void
    {
        $this->actingAs($this->userWithRole('admin'))
            ->get('/admin')
            ->assertSuccessful();
    }

    public function test_auditor_reaches_the_dashboard(): void
    {
        $this->actingAs($this->userWithRole('auditor'))
            ->get('/admin')
            ->assertSuccessful();
    }

    public function test_player_is_refused(): void
    {
        $this->actingAs($this->userWithRole('player'))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_agent_is_refused(): void
    {
        $this->actingAs($this->userWithRole('agent'))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_user_with_no_role_is_refused(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_suspended_admin_is_refused(): void
    {
        $this->actingAs($this->userWithRole('admin', UserStatus::Suspended))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_admin_pending_verification_is_refused(): void
    {
        $this->actingAs($this->userWithRole('admin', UserStatus::PendingVerification))
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_can_access_panel_is_the_single_authorization_surface(): void
    {
        $panel = \Filament\Facades\Filament::getPanel('admin');

        $this->assertTrue($this->userWithRole('admin')->canAccessPanel($panel));
        $this->assertFalse($this->userWithRole('player')->canAccessPanel($panel));
    }
}
