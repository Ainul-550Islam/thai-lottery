<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Filament\Widgets\DrawPipelineWidget;
use App\Filament\Widgets\LedgerBalanceWidget;
use App\Filament\Widgets\PendingApprovalsWidget;
use App\Filament\Widgets\PlatformStatsWidget;
use App\Models\Draw;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The dashboard: the first screen an operator sees.
 *
 * WHY THIS EXISTS
 * A dashboard widget runs an aggregate query on every page load, against whatever data the
 * platform happens to hold. The two ways it fails in practice are both asserted here:
 *
 *   1. It crashes on an EMPTY platform. Every widget below sums, counts or divides, and a
 *      sum over zero rows is null, not "0.00". A panel that 500s on a fresh install is a
 *      panel nobody can set up.
 *   2. It formats a decimal through something that needs the intl extension, which this
 *      runtime does not have. AdminFormat exists precisely to avoid that, and a widget that
 *      quietly reaches for Filament's money() helper instead would only fail once real
 *      money exists.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE
 * The exact figures. These are aggregates over fixtures; asserting the arithmetic would be
 * asserting that SUM works. What matters is that they render, on an empty database and on a
 * populated one, for every role allowed to see them.
 */
final class OperationsDashboardTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    /** @var list<class-string> */
    private const WIDGETS = [
        PlatformStatsWidget::class,
        PendingApprovalsWidget::class,
        DrawPipelineWidget::class,
        LedgerBalanceWidget::class,
    ];

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

    private function operator(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_every_widget_renders_on_an_empty_platform(): void
    {
        $admin = $this->operator('admin');

        foreach (self::WIDGETS as $widget) {
            Livewire::actingAs($admin)
                ->test($widget)
                ->assertOk();
        }
    }

    public function test_every_widget_renders_with_data(): void
    {
        Draw::factory()->create(['status' => DrawStatus::Open, 'type' => DrawType::TwoD]);
        Draw::factory()->create(['status' => DrawStatus::Drawing, 'type' => DrawType::ThreeD]);
        Draw::factory()->create(['status' => DrawStatus::Completed, 'type' => DrawType::TwoD]);
        User::factory()->count(3)->create();

        $admin = $this->operator('admin');

        foreach (self::WIDGETS as $widget) {
            Livewire::actingAs($admin)
                ->test($widget)
                ->assertOk();
        }
    }

    public function test_widgets_render_for_an_auditor(): void
    {
        $auditor = $this->operator('auditor');

        foreach (self::WIDGETS as $widget) {
            Livewire::actingAs($auditor)
                ->test($widget)
                ->assertOk();
        }
    }

    public function test_the_dashboard_page_itself_renders(): void
    {
        Draw::factory()->create(['status' => DrawStatus::Drawing, 'type' => DrawType::TwoD]);

        $this->actingAs($this->operator('admin'))
            ->get('/admin')
            ->assertSuccessful();
    }

    public function test_the_dashboard_renders_on_an_empty_platform(): void
    {
        $this->actingAs($this->operator('super-admin'))
            ->get('/admin')
            ->assertSuccessful();
    }
}
