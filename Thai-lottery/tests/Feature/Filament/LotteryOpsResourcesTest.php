<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Enums\LimitStatus;
use App\Enums\TicketStatus;
use App\Filament\Resources\BetResource;
use App\Filament\Resources\BetResource\Pages\ListBets;
use App\Filament\Resources\BetResource\Pages\ViewBet;
use App\Filament\Resources\DrawResource\RelationManagers\NumberLimitsRelationManager;
use App\Filament\Resources\NumberLimitResource;
use App\Filament\Resources\NumberLimitResource\Pages\CreateNumberLimit;
use App\Filament\Resources\NumberLimitResource\Pages\EditNumberLimit;
use App\Filament\Resources\NumberLimitResource\Pages\ListNumberLimits;
use App\Filament\Resources\TicketResource;
use App\Filament\Resources\TicketResource\Pages\ListTickets;
use App\Filament\Resources\TicketResource\Pages\ViewTicket;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\Draw;
use App\Models\NumberLimit;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The Lottery-ops and Risk screens: bets, tickets and number limits.
 *
 * WHY THIS EXISTS
 * Three resources, two opposite promises, and both promises are the sort that decay
 * silently unless a test holds them:
 *
 *   1. BetResource and TicketResource are read-only *by construction*, not by a hidden
 *      button. A future contributor adding a DeleteBulkAction "because the table looked
 *      empty" would break a financial record, and nothing in the UI would complain. The
 *      assertions below therefore check the authorization gates, the absence of create
 *      and edit routes, the empty header actions on both page classes, and the absence of
 *      any mutating row action — four independent statements of the same rule, because
 *      any one of them alone can be satisfied while the panel still writes.
 *
 *   2. NumberLimitResource is the one screen here that legitimately writes, so what is
 *      tested is the *boundary*: the digit shape a bet type implies, the decimal-string
 *      shape of a ceiling, and above all the deletion guard. A limit with reservations is
 *      the record of exposure the house is carrying and of the ceiling a placed bet was
 *      accepted under; deleting it would also leave NumberLimitEngine::release() with no
 *      row to decrement. Both branches of that guard are exercised.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE
 * The risk engine's own arithmetic. reserve(), release() and the exceeded-status rules
 * have their own suites; re-asserting them through a Livewire form would just mean two
 * places to edit when a rule changes. The fixtures below write the consumption columns
 * directly with forceFill, exactly as the engine does, so the panel is tested against a
 * state the engine can actually produce without dragging the engine into the test.
 */
final class LotteryOpsResourcesTest extends TestCase
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

    // -------------------------------------------------------------------------
    // Fixtures. Only Draw, User and Wallet have factories, so everything else is
    // built explicitly, with forceFill for the columns a domain service owns.
    // -------------------------------------------------------------------------

    private function operator(string $role = 'admin'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    private function draw(DrawStatus $status = DrawStatus::Open): Draw
    {
        return Draw::factory()->create([
            'status' => $status,
            'type' => DrawType::TwoD,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function bet(User $user, Draw $draw, array $attributes = []): Bet
    {
        $bet = new Bet;

        $bet->forceFill(array_merge([
            'bet_number' => 'BET-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT).'-'.uniqid(),
            'user_id' => $user->getKey(),
            'draw_id' => $draw->getKey(),
            'type' => BetType::TwoD,
            'status' => BetStatus::Active,
            'currency' => Currency::THB,
            'stake_amount' => '100.00',
            'potential_payout' => '9000.00',
            'actual_payout' => '0.00',
            'total_numbers' => 1,
            'placed_at' => now(),
        ], $attributes))->save();

        return $bet->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function betItem(Bet $bet, array $attributes = []): BetItem
    {
        $item = new BetItem;

        $item->forceFill(array_merge([
            'bet_id' => $bet->getKey(),
            'number' => '07',
            'position' => 'top',
            'amount' => '100.00',
            'payout_multiplier' => 90,
            'potential_payout' => '9000.00',
            'is_winner' => false,
            'actual_payout' => '0.00',
        ], $attributes))->save();

        return $item->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(User $user, Draw $draw, array $attributes = []): Ticket
    {
        $ticket = new Ticket;

        $ticket->forceFill(array_merge([
            'ticket_number' => 'TKT-'.uniqid(),
            'user_id' => $user->getKey(),
            'draw_id' => $draw->getKey(),
            'status' => TicketStatus::Confirmed,
            'currency' => Currency::THB,
            'total_amount' => '250.00',
            'total_bets' => 1,
            'total_numbers' => 2,
            'issued_at' => now(),
            'confirmed_at' => now(),
        ], $attributes))->save();

        return $ticket->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function numberLimit(Draw $draw, array $attributes = []): NumberLimit
    {
        $limit = new NumberLimit;

        $limit->forceFill(array_merge([
            'draw_id' => $draw->getKey(),
            'bet_type' => BetType::TwoD,
            'number' => '42',
            'max_amount' => '1000.00',
            'current_amount' => '0.00',
            'maximum_payout_exposure' => '90000.00',
            'current_payout_exposure' => '0.00',
            'status' => LimitStatus::Active,
        ], $attributes))->save();

        return $limit->refresh();
    }

    /**
     * The header actions a Filament page declares, read through reflection because the
     * method is protected. Asserting on the array itself is stronger than asserting a
     * particular button is invisible: an empty array cannot hide a mutation.
     *
     * @return array<int, mixed>
     */
    private function headerActionsOf(string $pageClass): array
    {
        $method = new ReflectionMethod($pageClass, 'getHeaderActions');
        $method->setAccessible(true);

        return $method->invoke(new $pageClass);
    }

    // =========================================================================
    // BetResource
    // =========================================================================

    public function test_bet_list_renders_for_an_admin_and_shows_the_bet(): void
    {
        $bet = $this->bet($this->operator('player'), $this->draw());

        $this->actingAs($this->operator())
            ->get(BetResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($bet->bet_number);
    }

    public function test_bet_view_shows_each_selection_with_its_simulated_prize(): void
    {
        $bet = $this->bet($this->operator('player'), $this->draw());
        $this->betItem($bet, ['number' => '07', 'amount' => '100.00', 'payout_multiplier' => 90]);

        $this->actingAs($this->operator())
            ->get(BetResource::getUrl('view', ['record' => $bet]))
            ->assertSuccessful()
            ->assertSee($bet->bet_number)
            ->assertSee('07')
            // 100.00 x 90, computed with bcmul and formatted without intl.
            ->assertSee('9,000.00');
    }

    public function test_bet_money_columns_are_formatted_as_exact_strings(): void
    {
        $bet = $this->bet($this->operator('player'), $this->draw(), [
            'stake_amount' => '1234567.50',
            'potential_payout' => '0.00',
        ]);

        $this->actingAs($this->operator())
            ->get(BetResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee('1,234,567.50 THB');

        $this->assertSame('1,234,567.50 THB', \App\Support\Admin\AdminFormat::money($bet->stake_amount, 'THB'));
    }

    public function test_bet_resource_exposes_no_mutating_capability(): void
    {
        $bet = $this->bet($this->operator('player'), $this->draw());

        \Illuminate\Support\Facades\Auth::login($this->operator('super-admin'));

        $this->assertFalse(BetResource::canCreate(), 'A bet must never be creatable from the panel.');
        $this->assertFalse(BetResource::canEdit($bet), 'A bet must never be editable from the panel.');
        $this->assertFalse(BetResource::canDelete($bet), 'A bet must never be deletable from the panel.');
        $this->assertFalse(BetResource::canDeleteAny());
        $this->assertFalse(BetResource::canForceDelete($bet));
        $this->assertFalse(BetResource::canRestore($bet));

        // No route exists to guess, either.
        $this->assertArrayNotHasKey('create', BetResource::getPages());
        $this->assertArrayNotHasKey('edit', BetResource::getPages());
    }

    public function test_bet_pages_declare_no_header_actions(): void
    {
        $this->assertSame([], $this->headerActionsOf(ListBets::class));
        $this->assertSame([], $this->headerActionsOf(ViewBet::class));
    }

    public function test_bet_table_offers_only_a_view_action(): void
    {
        $bet = $this->bet($this->operator('player'), $this->draw());

        Livewire::actingAs($this->operator())
            ->test(ListBets::class)
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertCanSeeTableRecords([$bet]);
    }

    public function test_bet_filters_narrow_the_list(): void
    {
        $player = $this->operator('player');
        $draw = $this->draw();

        $small = $this->bet($player, $draw, ['stake_amount' => '20.00']);
        $large = $this->bet($player, $draw, ['stake_amount' => '5000.00', 'status' => BetStatus::Won]);

        Livewire::actingAs($this->operator())
            ->test(ListBets::class)
            ->filterTable('stake_between', ['stake_min' => '1000', 'stake_max' => '9999.99'])
            ->assertCanSeeTableRecords([$large])
            ->assertCanNotSeeTableRecords([$small])
            ->resetTableFilters()
            ->filterTable('status', ['won'])
            ->assertCanSeeTableRecords([$large])
            ->assertCanNotSeeTableRecords([$small]);
    }

    public function test_a_player_cannot_reach_the_bet_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(BetResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_an_auditor_may_read_bets_because_it_holds_view_transaction_history(): void
    {
        $auditor = $this->operator('auditor');
        $bet = $this->bet($this->operator('player'), $this->draw());

        // The permission the resource actually reads, spelled out so the test fails
        // loudly if the seeded matrix changes rather than silently asserting the wrong
        // thing.
        $this->assertTrue($auditor->can('view transaction history'));
        $this->assertFalse($auditor->can('view draws'));

        $this->actingAs($auditor)
            ->get(BetResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($bet->bet_number);

        $this->actingAs($auditor)
            ->get(BetResource::getUrl('view', ['record' => $bet]))
            ->assertSuccessful();

        \Illuminate\Support\Facades\Auth::login($auditor);
        $this->assertFalse(BetResource::canCreate());
        $this->assertFalse(BetResource::canEdit($bet));
    }

    // =========================================================================
    // TicketResource
    // =========================================================================

    public function test_ticket_list_renders_for_an_admin_and_shows_the_ticket(): void
    {
        $ticket = $this->ticket($this->operator('player'), $this->draw());

        $this->actingAs($this->operator())
            ->get(TicketResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($ticket->ticket_number)
            ->assertSee('250.00 THB');
    }

    public function test_ticket_view_lists_the_bets_it_groups(): void
    {
        $player = $this->operator('player');
        $draw = $this->draw();
        $ticket = $this->ticket($player, $draw);
        $bet = $this->bet($player, $draw, ['ticket_id' => $ticket->getKey()]);

        $this->actingAs($this->operator())
            ->get(TicketResource::getUrl('view', ['record' => $ticket]))
            ->assertSuccessful()
            ->assertSee($ticket->ticket_number)
            ->assertSee($bet->bet_number);
    }

    public function test_ticket_resource_exposes_no_mutating_capability(): void
    {
        $ticket = $this->ticket($this->operator('player'), $this->draw());

        \Illuminate\Support\Facades\Auth::login($this->operator('super-admin'));

        $this->assertFalse(TicketResource::canCreate());
        $this->assertFalse(TicketResource::canEdit($ticket));
        $this->assertFalse(TicketResource::canDelete($ticket));
        $this->assertFalse(TicketResource::canDeleteAny());
        $this->assertFalse(TicketResource::canForceDelete($ticket));
        $this->assertFalse(TicketResource::canRestore($ticket));

        $this->assertArrayNotHasKey('create', TicketResource::getPages());
        $this->assertArrayNotHasKey('edit', TicketResource::getPages());
    }

    public function test_ticket_pages_declare_no_header_actions(): void
    {
        $this->assertSame([], $this->headerActionsOf(ListTickets::class));
        $this->assertSame([], $this->headerActionsOf(ViewTicket::class));
    }

    public function test_ticket_table_offers_only_a_view_action(): void
    {
        $ticket = $this->ticket($this->operator('player'), $this->draw());

        Livewire::actingAs($this->operator())
            ->test(ListTickets::class)
            ->assertTableActionExists('view')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete')
            ->assertCanSeeTableRecords([$ticket]);
    }

    public function test_a_player_cannot_reach_the_ticket_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(TicketResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_an_auditor_may_read_tickets_but_gets_no_write_capability(): void
    {
        $auditor = $this->operator('auditor');
        $ticket = $this->ticket($this->operator('player'), $this->draw());

        $this->actingAs($auditor)
            ->get(TicketResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($ticket->ticket_number);

        \Illuminate\Support\Facades\Auth::login($auditor);
        $this->assertFalse(TicketResource::canCreate());
        $this->assertFalse(TicketResource::canEdit($ticket));
        $this->assertFalse(TicketResource::canDelete($ticket));
    }

    // =========================================================================
    // NumberLimitResource
    // =========================================================================

    public function test_number_limit_list_shows_reserved_and_remaining(): void
    {
        $limit = $this->numberLimit($this->draw(), [
            'max_amount' => '1000.00',
            'current_amount' => '250.00',
        ]);

        $this->actingAs($this->operator())
            ->get(NumberLimitResource::getUrl('index'))
            ->assertSuccessful()
            ->assertSee($limit->number)
            ->assertSee('1,000.00')   // ceiling
            ->assertSee('250.00')     // reserved
            ->assertSee('750.00')     // remaining, bcsub
            ->assertSee('25.00%');    // utilisation, bcdiv
    }

    public function test_remaining_and_utilisation_are_exact_bcmath_strings(): void
    {
        $limit = $this->numberLimit($this->draw(), [
            'max_amount' => '3333.33',
            'current_amount' => '1111.11',
        ]);

        $this->assertSame('2222.22', $limit->remaining_amount);
        $this->assertSame('33.33', $limit->utilisation_percent);
    }

    public function test_create_page_renders_and_writes_exactly_the_engine_fields(): void
    {
        $draw = $this->draw();

        $this->actingAs($this->operator())
            ->get(NumberLimitResource::getUrl('create'))
            ->assertSuccessful();

        Livewire::actingAs($this->operator())
            ->test(CreateNumberLimit::class)
            ->fillForm([
                'draw_id' => $draw->getKey(),
                'bet_type' => BetType::TwoD->value,
                'number' => '07',
                'max_amount' => '5000.00',
                'maximum_payout_exposure' => '450000.00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $limit = NumberLimit::query()->where('number', '07')->firstOrFail();

        $this->assertSame($draw->getKey(), $limit->draw_id);
        $this->assertSame(BetType::TwoD, $limit->bet_type);
        $this->assertSame('07', $limit->number, 'The leading zero must survive: number is a string column.');
        $this->assertSame(0, bccomp('5000.00', (string) $limit->max_amount, 2));
        $this->assertSame(0, bccomp('450000.00', (string) $limit->maximum_payout_exposure, 2));

        // The consumption columns the engine owns start at their schema defaults and were
        // not touched by the form.
        $this->assertSame(0, bccomp('0', (string) $limit->current_amount, 2));
        $this->assertSame(0, bccomp('0', (string) $limit->current_payout_exposure, 2));
        $this->assertSame(LimitStatus::Active, $limit->status);
        $this->assertNull($limit->exceeded_at);
    }

    public function test_the_number_must_match_the_digit_count_its_bet_type_implies(): void
    {
        $draw = $this->draw();

        Livewire::actingAs($this->operator())
            ->test(CreateNumberLimit::class)
            ->fillForm([
                'draw_id' => $draw->getKey(),
                'bet_type' => BetType::TwoD->value,
                'number' => '123',
                'max_amount' => '5000.00',
            ])
            ->call('create')
            ->assertHasFormErrors(['number']);

        $this->assertDatabaseCount('number_limits', 0);

        // The same three digits are valid for a 3D limit, proving the rule reads the
        // type rather than hard-coding two digits.
        Livewire::actingAs($this->operator())
            ->test(CreateNumberLimit::class)
            ->fillForm([
                'draw_id' => $draw->getKey(),
                'bet_type' => BetType::ThreeD->value,
                'number' => '123',
                'max_amount' => '5000.00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('number_limits', 1);
    }

    public function test_run_limits_take_the_digit_count_from_configuration_not_the_enum(): void
    {
        // BetType::Run->digits() returns 2, configuration says 1, and
        // NumberNormalizationService documents configuration as authoritative. The form
        // must agree with the engine, so a single digit is accepted here.
        $this->assertSame(1, NumberLimitResource::digitsFor(BetType::Run));
        $this->assertSame(3, NumberLimitResource::digitsFor(BetType::Tod));

        $draw = $this->draw();

        Livewire::actingAs($this->operator())
            ->test(CreateNumberLimit::class)
            ->fillForm([
                'draw_id' => $draw->getKey(),
                'bet_type' => BetType::Run->value,
                'number' => '7',
                'max_amount' => '100.00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('number_limits', ['number' => '7', 'bet_type' => 'run']);
    }

    public function test_a_ceiling_must_be_an_exact_positive_decimal_string(): void
    {
        $draw = $this->draw();

        Livewire::actingAs($this->operator())
            ->test(CreateNumberLimit::class)
            ->fillForm([
                'draw_id' => $draw->getKey(),
                'bet_type' => BetType::TwoD->value,
                'number' => '07',
                'max_amount' => '1,000.005',
            ])
            ->call('create')
            ->assertHasFormErrors(['max_amount']);

        Livewire::actingAs($this->operator())
            ->test(CreateNumberLimit::class)
            ->fillForm([
                'draw_id' => $draw->getKey(),
                'bet_type' => BetType::TwoD->value,
                'number' => '07',
                'max_amount' => '0',
            ])
            ->call('create')
            ->assertHasFormErrors(['max_amount']);

        $this->assertDatabaseCount('number_limits', 0);
    }

    public function test_editing_changes_only_definition_fields(): void
    {
        $limit = $this->numberLimit($this->draw(), [
            'max_amount' => '1000.00',
            'current_amount' => '400.00',
            'current_payout_exposure' => '36000.00',
        ]);

        Livewire::actingAs($this->operator())
            ->test(EditNumberLimit::class, ['record' => $limit->getKey()])
            ->fillForm(['max_amount' => '2000.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $limit->refresh();

        $this->assertSame(0, bccomp('2000.00', (string) $limit->max_amount, 2));
        $this->assertSame(0, bccomp('400.00', (string) $limit->current_amount, 2), 'The reserved counter must be untouched by an edit.');
        $this->assertSame(0, bccomp('36000.00', (string) $limit->current_payout_exposure, 2));
        $this->assertSame(LimitStatus::Active, $limit->status);
    }

    public function test_a_ceiling_may_be_lowered_below_what_is_already_reserved(): void
    {
        // Deliberate: "stop selling this number, keep what we sold" is a real risk
        // decision, and NumberLimitEngine::reserve() will simply refuse further stake.
        $limit = $this->numberLimit($this->draw(), [
            'max_amount' => '1000.00',
            'current_amount' => '900.00',
        ]);

        Livewire::actingAs($this->operator())
            ->test(EditNumberLimit::class, ['record' => $limit->getKey()])
            ->fillForm(['max_amount' => '500.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, bccomp('500.00', (string) $limit->fresh()->max_amount, 2));
        $this->assertSame('0.00', $limit->fresh()->remaining_amount);
    }

    // ---- the deletion guard, both branches ----------------------------------

    public function test_an_untouched_limit_may_be_deleted(): void
    {
        $limit = $this->numberLimit($this->draw(), ['current_amount' => '0.00', 'current_payout_exposure' => '0.00']);

        \Illuminate\Support\Facades\Auth::login($this->operator());
        $this->assertTrue(NumberLimitResource::canDelete($limit));
        $this->assertFalse(NumberLimitResource::hasReservations($limit));

        Livewire::actingAs($this->operator())
            ->test(ListNumberLimits::class)
            ->assertTableActionVisible('delete', record: $limit)
            ->callTableAction('delete', record: $limit)
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted('number_limits', ['id' => $limit->getKey()]);
    }

    public function test_a_limit_with_reserved_stake_refuses_deletion(): void
    {
        $limit = $this->numberLimit($this->draw(), ['current_amount' => '250.00']);

        \Illuminate\Support\Facades\Auth::login($this->operator());
        $this->assertTrue(NumberLimitResource::hasReservations($limit));
        $this->assertFalse(NumberLimitResource::canDelete($limit), 'A limit with reserved stake must not be deletable.');

        Livewire::actingAs($this->operator())
            ->test(ListNumberLimits::class)
            ->assertTableActionHidden('delete', record: $limit);

        $this->assertDatabaseHas('number_limits', ['id' => $limit->getKey(), 'deleted_at' => null]);
    }

    public function test_a_limit_with_only_payout_exposure_reserved_also_refuses_deletion(): void
    {
        // reserve() moves both counters, but a release() that clamped the stake counter
        // to zero can leave payout exposure behind. Either one means the row has history.
        $limit = $this->numberLimit($this->draw(), [
            'current_amount' => '0.00',
            'current_payout_exposure' => '9000.00',
        ]);

        \Illuminate\Support\Facades\Auth::login($this->operator());

        $this->assertTrue(NumberLimitResource::hasReservations($limit));
        $this->assertFalse(NumberLimitResource::canDelete($limit));
    }

    public function test_bulk_deletion_of_limits_is_never_available(): void
    {
        \Illuminate\Support\Facades\Auth::login($this->operator('super-admin'));

        $this->assertFalse(NumberLimitResource::canDeleteAny());

        $this->numberLimit($this->draw());

        Livewire::actingAs($this->operator())
            ->test(ListNumberLimits::class)
            ->assertTableBulkActionDoesNotExist('delete');
    }

    // ---- authorization ------------------------------------------------------

    public function test_a_player_cannot_reach_the_number_limit_resource(): void
    {
        $this->actingAs($this->operator('player'))
            ->get(NumberLimitResource::getUrl('index'))
            ->assertForbidden();
    }

    public function test_an_auditor_holds_neither_risk_permission_and_is_refused(): void
    {
        $auditor = $this->operator('auditor');

        // Stated explicitly: the auditor's seeded grant is audit and finance reading,
        // and risk alerts are not part of it. If that matrix changes, this test tells
        // the reader which assumption broke.
        $this->assertFalse($auditor->can('view risk alerts'));
        $this->assertFalse($auditor->can('manage risk limits'));

        $this->actingAs($auditor)
            ->get(NumberLimitResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($auditor)
            ->get(NumberLimitResource::getUrl('create'))
            ->assertForbidden();
    }

    public function test_an_admin_may_create_and_edit_because_it_holds_manage_risk_limits(): void
    {
        $admin = $this->operator();
        $limit = $this->numberLimit($this->draw());

        $this->assertTrue($admin->can('manage risk limits'));

        \Illuminate\Support\Facades\Auth::login($admin);

        $this->assertTrue(NumberLimitResource::canViewAny());
        $this->assertTrue(NumberLimitResource::canCreate());
        $this->assertTrue(NumberLimitResource::canEdit($limit));

        $this->actingAs($admin)
            ->get(NumberLimitResource::getUrl('edit', ['record' => $limit]))
            ->assertSuccessful();
    }

    // =========================================================================
    // The draw's number-limits relation manager
    // =========================================================================

    public function test_the_relation_manager_lists_the_limits_of_its_draw_only(): void
    {
        $draw = $this->draw();
        $other = $this->draw();

        $mine = $this->numberLimit($draw, ['number' => '11', 'current_amount' => '100.00']);
        $theirs = $this->numberLimit($other, ['number' => '22']);

        Livewire::actingAs($this->operator())
            ->test(NumberLimitsRelationManager::class, [
                'ownerRecord' => $draw,
                'pageClass' => \App\Filament\Resources\DrawResource\Pages\ViewDraw::class,
            ])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_relation_manager_is_read_only(): void
    {
        $draw = $this->draw();
        $this->numberLimit($draw);

        $manager = new NumberLimitsRelationManager;

        $this->assertTrue($manager->isReadOnly(), 'Limits are edited on their own resource, not inside the draw page.');

        Livewire::actingAs($this->operator())
            ->test(NumberLimitsRelationManager::class, [
                'ownerRecord' => $draw,
                'pageClass' => \App\Filament\Resources\DrawResource\Pages\ViewDraw::class,
            ])
            ->assertTableActionDoesNotExist('delete')
            ->assertTableActionDoesNotExist('edit')
            ->assertTableBulkActionDoesNotExist('delete');
    }
}
