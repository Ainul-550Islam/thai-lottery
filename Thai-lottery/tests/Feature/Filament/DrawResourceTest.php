<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Filament\Resources\DrawResource;
use App\Filament\Resources\DrawResource\Pages\ListDraws;
use App\Filament\Resources\DrawResource\Pages\ViewDraw;
use App\Models\Draw;
use App\Models\DrawResult;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The draw resource: the screen an operator uses on draw night.
 *
 * WHY THIS EXISTS
 * DrawResource is the exemplar the other resources copy, and it is the one place in the
 * application where a human can publish an official result. Two properties matter more
 * than any table formatting, and both are asserted here:
 *
 *   1. A button appears only when the domain would actually accept it. A "Publish" button
 *      on a draw that is still open is a trap, and an operator who trusts it will file a
 *      bug against the lifecycle service instead of the screen.
 *   2. The screen never writes state itself. Publishing goes through
 *      DrawResultPublicationService, so the row that lands in the database is the row the
 *      domain would have written from any other caller.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE
 * The lifecycle rules themselves - those have their own unit suites, and duplicating them
 * would just mean two places to update when a rule changes.
 */
final class DrawResourceTest extends TestCase
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

    private function operator(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function draw(DrawStatus $status, array $attributes = []): Draw
    {
        return Draw::factory()->create(array_merge([
            'status' => $status,
            'type' => DrawType::TwoD,
        ], $attributes));
    }

    // -------------------------------------------------------------------------
    // Pages render.
    // -------------------------------------------------------------------------

    public function test_list_page_renders_for_an_admin(): void
    {
        $draw = $this->draw(DrawStatus::Open);

        $this->actingAs($this->operator())
            ->get(DrawResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($draw->draw_number);
    }

    public function test_view_page_renders_for_an_admin(): void
    {
        $draw = $this->draw(DrawStatus::Open);

        $this->actingAs($this->operator())
            ->get(DrawResource::getUrl('view', ['record' => $draw]))
            ->assertSuccessful()
            ->assertSee($draw->draw_number);
    }

    public function test_create_page_renders_for_an_admin(): void
    {
        $this->actingAs($this->operator())
            ->get(DrawResource::getUrl('create'))
            ->assertSuccessful();
    }

    public function test_edit_page_renders_for_an_admin(): void
    {
        $draw = $this->draw(DrawStatus::Scheduled);

        $this->actingAs($this->operator())
            ->get(DrawResource::getUrl('edit', ['record' => $draw]))
            ->assertSuccessful();
    }

    public function test_auditor_may_view_but_not_create(): void
    {
        $draw = $this->draw(DrawStatus::Open);
        $auditor = $this->operator('auditor');

        $this->actingAs($auditor)
            ->get(DrawResource::getUrl('index'))
            ->assertForbidden();

        $this->assertFalse(DrawResource::canCreate());
        $this->assertFalse($auditor->can('manage draws'));
    }

    public function test_player_cannot_reach_the_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(DrawResource::getUrl('index'))
            ->assertForbidden();
    }

    // -------------------------------------------------------------------------
    // Buttons appear only when the domain would accept them.
    // -------------------------------------------------------------------------

    public function test_publish_is_offered_only_while_awaiting_numbers(): void
    {
        $awaiting = $this->draw(DrawStatus::Drawing);
        $open = $this->draw(DrawStatus::Open);

        Livewire::actingAs($this->operator())
            ->test(ListDraws::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('publish', record: $awaiting)
            ->assertTableActionHidden('publish', record: $open);
    }

    public function test_open_is_offered_only_on_a_scheduled_draw(): void
    {
        $scheduled = $this->draw(DrawStatus::Scheduled);
        $open = $this->draw(DrawStatus::Open);

        Livewire::actingAs($this->operator())
            ->test(ListDraws::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('open', record: $scheduled)
            ->assertTableActionHidden('open', record: $open);
    }

    public function test_close_is_offered_only_on_an_open_draw(): void
    {
        $open = $this->draw(DrawStatus::Open);
        $scheduled = $this->draw(DrawStatus::Scheduled);

        Livewire::actingAs($this->operator())
            ->test(ListDraws::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('close', record: $open)
            ->assertTableActionHidden('close', record: $scheduled);
    }

    public function test_settle_is_offered_only_after_a_result_is_published(): void
    {
        $published = $this->draw(DrawStatus::ResultPublished);
        $awaiting = $this->draw(DrawStatus::Drawing);

        Livewire::actingAs($this->operator())
            ->test(ListDraws::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('settle', record: $published)
            ->assertTableActionHidden('settle', record: $awaiting);
    }

    public function test_cancel_is_not_offered_once_a_result_is_published(): void
    {
        $published = $this->draw(DrawStatus::ResultPublished);
        $scheduled = $this->draw(DrawStatus::Scheduled);

        Livewire::actingAs($this->operator())
            ->test(ListDraws::class, ['activeTab' => 'all'])
            ->assertTableActionVisible('cancelDraw', record: $scheduled)
            ->assertTableActionHidden('cancelDraw', record: $published);
    }

    // -------------------------------------------------------------------------
    // Actions delegate to services.
    // -------------------------------------------------------------------------

    public function test_opening_a_draw_from_the_table_transitions_it(): void
    {
        $draw = $this->draw(DrawStatus::Scheduled);

        Livewire::actingAs($this->operator())
            ->test(ListDraws::class, ['activeTab' => 'all'])
            ->callTableAction('open', record: $draw)
            ->assertHasNoTableActionErrors();

        $this->assertSame(DrawStatus::Open, $draw->fresh()->status);
    }

    public function test_publishing_writes_the_result_through_the_domain_service(): void
    {
        $draw = $this->draw(DrawStatus::Drawing);

        Livewire::actingAs($this->operator())
            ->test(ViewDraw::class, ['record' => $draw->getKey()])
            ->callAction('publish', data: [
                'first_prize' => '123456',
                'bottom_two' => '56',
            ])
            ->assertHasNoActionErrors();

        $result = DrawResult::query()->where('draw_id', $draw->getKey())->first();

        $this->assertNotNull($result, 'Publishing produced no draw_results row.');
        $this->assertSame('123456', $result->first_prize);
        $this->assertSame(DrawStatus::ResultPublished, $draw->fresh()->status);
    }

    public function test_publishing_rejects_a_malformed_first_prize(): void
    {
        $draw = $this->draw(DrawStatus::Drawing);

        Livewire::actingAs($this->operator())
            ->test(ViewDraw::class, ['record' => $draw->getKey()])
            ->callAction('publish', data: [
                'first_prize' => '12345',
                'bottom_two' => '56',
            ])
            ->assertHasActionErrors(['first_prize']);

        $this->assertSame(DrawStatus::Drawing, $draw->fresh()->status);
        $this->assertDatabaseCount('draw_results', 0);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $draw = $this->draw(DrawStatus::Scheduled);

        Livewire::actingAs($this->operator())
            ->test(ViewDraw::class, ['record' => $draw->getKey()])
            ->callAction('cancelDraw', data: ['reason' => ''])
            ->assertHasActionErrors(['reason']);

        $this->assertSame(DrawStatus::Scheduled, $draw->fresh()->status);
    }

    public function test_an_auditor_is_offered_no_lifecycle_buttons(): void
    {
        $draw = $this->draw(DrawStatus::Drawing);

        \Illuminate\Support\Facades\Auth::login($this->operator('auditor'));

        foreach (DrawResource\DrawLifecycleActions::forTable() as $action) {
            $action->record($draw);

            $this->assertFalse(
                $action->isVisible(),
                "An auditor was offered the [{$action->getName()}] action.",
            );
        }
    }
}
