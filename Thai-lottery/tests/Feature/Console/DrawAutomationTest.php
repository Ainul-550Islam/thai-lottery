<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\DrawLifecycleState;
use App\Enums\DrawStatus;
use App\Enums\DrawType;
use App\Models\Draw;
use App\Services\Draw\DrawResultPublicationService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The draw automation added after Phase 5.1.
 *
 * WHAT THIS SUITE IS FOR
 * Phase 5.1 shipped a complete draw lifecycle that nothing invoked: the declared
 * calendar and the declared auto-close were configuration no code read. These tests
 * pin the behaviour of the commands and the scheduler that now read them, and - just as
 * importantly - they pin what automation must never do: publish a result, and move
 * money.
 *
 * WHY DatabaseTruncation AND NOT RefreshDatabase
 * Same reason as the settlement suite: DrawSettlementSimulationService refuses to run
 * inside a transaction it does not own, and RefreshDatabase wraps every test in one.
 * Truncation commits, so the transaction boundaries under test are the real ones.
 */
final class DrawAutomationTest extends TestCase
{
    use DatabaseTruncation;

    /**
     * @var list<string>
     */
    private const ALLOWED_DATABASES = ['thai_lottery_test', ':memory:'];

    protected function setUp(): void
    {
        parent::setUp();

        $database = (string) DB::connection()->getDatabaseName();

        if (! in_array($database, self::ALLOWED_DATABASES, true)
            && ! in_array(basename($database), self::ALLOWED_DATABASES, true)) {
            $this->fail(sprintf('ABORTED: this suite may only run against a named test database, not "%s".', $database));
        }

        config([
            'lottery.automation.enabled' => true,
            'lottery.automation.auto_settle' => true,
            'lottery.automation.batch_size' => 50,
            'lottery.automation.settlement_delay_minutes' => 15,
            'lottery.automation.result_pending_after_minutes' => 0,
            'lottery.closing.auto_close' => true,
            'lottery.closing.minutes_before_draw' => 5,
            'lottery.closing.grace_seconds' => 0,
            'lottery.draw.official_schedule.days_of_month' => [1, 16],
            'lottery.draw.official_schedule.time' => '15:00',
        ]);
    }

    // -------------------------------------------------------------------------
    // Provisioning
    // -------------------------------------------------------------------------

    #[Test]
    public function it_creates_the_draws_the_calendar_declares_with_their_betting_window(): void
    {
        $this->artisan('lottery:schedule-draws', ['--days' => 40])->assertSuccessful();

        $draws = Draw::query()->orderBy('scheduled_at')->get();

        $this->assertGreaterThanOrEqual(2, $draws->count(), 'A 40 day horizon over a twice-monthly calendar must yield at least two draws.');

        foreach ($draws as $draw) {
            $this->assertSame(DrawStatus::Scheduled, $draw->status, 'A provisioned draw starts in the configured initial status.');
            $this->assertNotNull($draw->betting_open_at);
            $this->assertNotNull($draw->betting_close_at);
            $this->assertTrue(
                $draw->betting_close_at->lessThan($draw->scheduled_at),
                'Betting must close before the draw happens.',
            );
            $this->assertSame(
                5,
                (int) $draw->betting_close_at->diffInMinutes($draw->scheduled_at, true),
                'The cut-off must be exactly config(lottery.closing.minutes_before_draw) before the draw.',
            );
            $this->assertMatchesRegularExpression('/^DR-\d{8}-\d{4}$/', (string) $draw->draw_number);
        }
    }

    #[Test]
    public function running_provisioning_again_creates_nothing(): void
    {
        $this->artisan('lottery:schedule-draws', ['--days' => 120])->assertSuccessful();
        $first = Draw::query()->pluck('draw_number')->sort()->values()->all();

        $this->artisan('lottery:schedule-draws', ['--days' => 120])->assertSuccessful();
        $second = Draw::query()->pluck('draw_number')->sort()->values()->all();

        $this->assertSame($first, $second, 'Provisioning is idempotent because a draw number is derived from its moment.');
        $this->assertSame(count($first), Draw::query()->count());
    }

    #[Test]
    public function a_deleted_draw_is_not_silently_recreated(): void
    {
        $this->artisan('lottery:schedule-draws', ['--days' => 40])->assertSuccessful();

        $draw = Draw::query()->orderBy('scheduled_at')->firstOrFail();
        $number = (string) $draw->draw_number;
        $draw->delete();

        $this->artisan('lottery:schedule-draws', ['--days' => 40])->assertSuccessful();

        $this->assertSame(0, Draw::query()->where('draw_number', $number)->count(), 'An operator who deleted a draw did so on purpose.');
        $this->assertSame(1, Draw::withTrashed()->where('draw_number', $number)->count());
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->artisan('lottery:schedule-draws', ['--dry-run' => true, '--days' => 60])->assertSuccessful();

        $this->assertSame(0, Draw::withTrashed()->count());
    }

    // -------------------------------------------------------------------------
    // Opening
    // -------------------------------------------------------------------------

    #[Test]
    public function it_opens_a_draw_whose_betting_window_has_started(): void
    {
        $draw = $this->draw(DrawStatus::Scheduled, now()->addHour(), now()->subHour(), now()->addMinutes(55));

        $this->artisan('lottery:open-draws')->assertSuccessful();

        $draw->refresh();
        $this->assertSame(DrawStatus::Open, $draw->status);
        $this->assertNotNull($draw->opened_at, 'The lifecycle stamps the real event, not the plan.');
        $this->assertTrue($draw->canAcceptBets());
    }

    #[Test]
    public function it_does_not_open_a_draw_whose_window_has_not_started(): void
    {
        $draw = $this->draw(DrawStatus::Scheduled, now()->addDays(10), now()->addDays(5), now()->addDays(10)->subMinutes(5));

        $this->artisan('lottery:open-draws')->assertSuccessful();

        $this->assertSame(DrawStatus::Scheduled, $draw->refresh()->status);
    }

    #[Test]
    public function it_does_not_open_a_draw_whose_cut_off_has_already_passed(): void
    {
        // A draw provisioned late. Opening it would advertise a draw as open while the
        // bet validator rejects every bet against it on the betting_close_at check.
        $draw = $this->draw(DrawStatus::Scheduled, now()->subMinutes(2), now()->subDay(), now()->subMinutes(7));

        $this->artisan('lottery:open-draws')->assertSuccessful();

        $this->assertSame(DrawStatus::Scheduled, $draw->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // Closing
    // -------------------------------------------------------------------------

    #[Test]
    public function it_closes_a_draw_whose_cut_off_has_passed(): void
    {
        $draw = $this->draw(DrawStatus::Open, now()->addMinutes(3), now()->subDay(), now()->subMinutes(2));

        $this->artisan('lottery:close-draws')->assertSuccessful();

        $draw->refresh();
        $this->assertSame(DrawStatus::Closed, $draw->status);
        $this->assertNotNull($draw->closed_at);
        $this->assertFalse($draw->canAcceptBets(), 'This is the state that used to never be reached.');
    }

    #[Test]
    public function it_leaves_a_draw_open_while_its_window_is_still_running(): void
    {
        $draw = $this->draw(DrawStatus::Open, now()->addHour(), now()->subHour(), now()->addMinutes(55));

        $this->artisan('lottery:close-draws')->assertSuccessful();

        $this->assertSame(DrawStatus::Open, $draw->refresh()->status);
    }

    #[Test]
    public function it_closes_a_hand_created_draw_that_has_no_planned_cut_off(): void
    {
        $draw = $this->draw(DrawStatus::Open, now()->subMinute(), null, null);

        $this->artisan('lottery:close-draws')->assertSuccessful();

        $this->assertSame(DrawStatus::Closed, $draw->refresh()->status);
    }

    #[Test]
    public function closing_respects_the_auto_close_setting_and_the_force_override(): void
    {
        config(['lottery.closing.auto_close' => false]);
        $draw = $this->draw(DrawStatus::Open, now()->addMinutes(3), now()->subDay(), now()->subMinutes(2));

        $this->artisan('lottery:close-draws')->assertSuccessful();
        $this->assertSame(DrawStatus::Open, $draw->refresh()->status, 'With auto_close off, closing is an operator decision.');

        $this->artisan('lottery:close-draws', ['--force' => true])->assertSuccessful();
        $this->assertSame(DrawStatus::Closed, $draw->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // Awaiting the result
    // -------------------------------------------------------------------------

    #[Test]
    public function it_moves_a_closed_draw_to_result_pending_once_the_draw_moment_has_passed(): void
    {
        $draw = $this->draw(DrawStatus::Closed, now()->subMinutes(10), now()->subDay(), now()->subMinutes(15));

        $this->artisan('lottery:mark-results-pending')->assertSuccessful();

        $draw->refresh();
        $this->assertSame(DrawLifecycleState::ResultPending, app(\App\Services\Draw\DrawLifecycleService::class)->currentState($draw));
    }

    #[Test]
    public function it_does_not_move_a_closed_draw_whose_moment_has_not_arrived(): void
    {
        $draw = $this->draw(DrawStatus::Closed, now()->addMinutes(4), now()->subDay(), now()->subMinute());

        $this->artisan('lottery:mark-results-pending')->assertSuccessful();

        $this->assertSame(DrawStatus::Closed, $draw->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // Settlement
    // -------------------------------------------------------------------------

    #[Test]
    public function it_settles_a_published_draw_once_the_delay_has_elapsed(): void
    {
        $draw = $this->publishedDraw(publishedMinutesAgo: 20);

        $this->artisan('lottery:settle-draws')->assertSuccessful();

        $this->assertTrue(
            app(\App\Services\Draw\DrawLifecycleService::class)->currentState($draw->refresh())->isSettled(),
        );
    }

    #[Test]
    public function it_waits_out_the_settlement_delay_so_a_mistyped_result_can_still_be_caught(): void
    {
        $draw = $this->publishedDraw(publishedMinutesAgo: 1);

        $this->artisan('lottery:settle-draws')->assertSuccessful();

        $this->assertFalse(
            app(\App\Services\Draw\DrawLifecycleService::class)->currentState($draw->refresh())->isSettled(),
        );
    }

    #[Test]
    public function an_operator_can_settle_one_named_draw_immediately(): void
    {
        $draw = $this->publishedDraw(publishedMinutesAgo: 0);

        $this->artisan('lottery:settle-draws', ['--draw' => (string) $draw->draw_number])->assertSuccessful();

        $this->assertTrue(
            app(\App\Services\Draw\DrawLifecycleService::class)->currentState($draw->refresh())->isSettled(),
        );
    }

    #[Test]
    public function settling_twice_writes_nothing_the_second_time(): void
    {
        $draw = $this->publishedDraw(publishedMinutesAgo: 20);

        $this->artisan('lottery:settle-draws')->assertSuccessful();
        $settledAt = $draw->refresh()->completed_at;

        $this->artisan('lottery:settle-draws', ['--draw' => (string) $draw->draw_number])
            ->expectsOutputToContain('already settled')
            ->assertSuccessful();

        $this->assertEquals($settledAt, $draw->refresh()->completed_at, 'A replay must not restamp the draw.');
    }

    #[Test]
    public function unattended_settlement_can_be_switched_off_independently_of_real_payouts(): void
    {
        // config(lottery.payouts.auto_process) governs the real-money Phase 5.2 payout
        // that does not exist. This is a different switch on purpose.
        config(['lottery.automation.auto_settle' => false]);
        $draw = $this->publishedDraw(publishedMinutesAgo: 60);

        $this->artisan('lottery:settle-draws')->assertSuccessful();
        $this->assertFalse(app(\App\Services\Draw\DrawLifecycleService::class)->currentState($draw->refresh())->isSettled());

        $this->artisan('lottery:settle-draws', ['--force' => true])->assertSuccessful();
        $this->assertTrue(app(\App\Services\Draw\DrawLifecycleService::class)->currentState($draw->refresh())->isSettled());
    }

    // -------------------------------------------------------------------------
    // Behaviour under failure and under the kill switch
    // -------------------------------------------------------------------------

    #[Test]
    public function one_bad_draw_does_not_stop_the_batch_and_the_command_still_reports_failure(): void
    {
        // A draw claiming a published result with no draw_results row: settlement must
        // refuse it. Reachable only by corrupting the row directly, which is exactly
        // the kind of state a batch job has to survive.
        $broken = $this->draw(DrawStatus::ResultPublished, now()->subHours(2), now()->subDay(), now()->subHours(3));
        $broken->result_published_at = now()->subHour();
        $broken->closed_at = now()->subHours(3);
        $broken->drawn_at = now()->subHours(2);
        $broken->save();

        $healthy = $this->publishedDraw(publishedMinutesAgo: 30);

        $this->artisan('lottery:settle-draws')->assertFailed();

        $lifecycle = app(\App\Services\Draw\DrawLifecycleService::class);

        $this->assertTrue($lifecycle->currentState($healthy->refresh())->isSettled(), 'The healthy draw must still settle.');
        $this->assertFalse($lifecycle->currentState($broken->refresh())->isSettled(), 'The broken draw must be refused, not forced.');
    }

    #[Test]
    public function the_kill_switch_stops_every_command_unless_it_is_forced(): void
    {
        config(['lottery.automation.enabled' => false]);
        $draw = $this->draw(DrawStatus::Scheduled, now()->addHour(), now()->subHour(), now()->addMinutes(55));

        $this->artisan('lottery:schedule-draws')->assertSuccessful();
        $this->artisan('lottery:open-draws')->assertSuccessful();
        $this->artisan('lottery:tick')->assertSuccessful();

        $this->assertSame(DrawStatus::Scheduled, $draw->refresh()->status);
        $this->assertSame(1, Draw::query()->count(), 'Provisioning must not run either.');

        $this->artisan('lottery:open-draws', ['--force' => true])->assertSuccessful();
        $this->assertSame(DrawStatus::Open, $draw->refresh()->status);
    }

    #[Test]
    public function a_batch_is_bounded_so_a_backlog_cannot_overrun_the_next_tick(): void
    {
        config(['lottery.automation.batch_size' => 2]);

        for ($i = 0; $i < 5; $i++) {
            $this->draw(DrawStatus::Open, now()->addMinutes(3), now()->subDay(), now()->subMinutes(2 + $i));
        }

        $this->artisan('lottery:close-draws')->assertSuccessful();

        $this->assertSame(2, Draw::query()->where('status', DrawStatus::Closed)->count());
        $this->assertSame(3, Draw::query()->where('status', DrawStatus::Open)->count());
    }

    // -------------------------------------------------------------------------
    // The orchestrator
    // -------------------------------------------------------------------------

    #[Test]
    public function one_tick_walks_a_draw_from_open_to_awaiting_its_result(): void
    {
        // The whole point of one orchestrator instead of five scheduled tasks: closing
        // and moving to result-pending both happen within the same minute.
        $draw = $this->draw(DrawStatus::Open, now()->subMinutes(2), now()->subDay(), now()->subMinutes(7));

        $this->artisan('lottery:tick')->assertSuccessful();

        $this->assertSame(
            DrawLifecycleState::ResultPending,
            app(\App\Services\Draw\DrawLifecycleService::class)->currentState($draw->refresh()),
        );
    }

    #[Test]
    public function a_tick_never_publishes_a_result_and_never_moves_money(): void
    {
        $draw = $this->draw(DrawStatus::Open, now()->subMinutes(2), now()->subDay(), now()->subMinutes(7));

        $this->artisan('lottery:tick')->assertSuccessful();

        // No result can appear without an operator entering the official numbers.
        $this->assertSame(0, DB::table('draw_results')->count());
        $this->assertSame(0, DB::table('winning_numbers')->count());

        // And nothing financial is touched by automation, in any table.
        foreach (['ledger_entries', 'financial_transactions', 'payouts', 'deposits', 'withdrawals', 'payments'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), sprintf('Automation must never write %s.', $table));
        }

        $this->assertSame(DrawStatus::Drawing, $draw->refresh()->status);
    }

    // -------------------------------------------------------------------------
    // Publication is an operator action
    // -------------------------------------------------------------------------

    #[Test]
    public function an_operator_can_publish_a_result_from_the_console(): void
    {
        $draw = $this->pendingDraw();

        $this->artisan('lottery:publish-result', [
            'draw' => (string) $draw->draw_number,
            '--first-prize' => '456123',
            '--bottom-two' => '45',
            '--yes' => true,
        ])->assertSuccessful();

        $this->assertSame(DrawStatus::ResultPublished, $draw->refresh()->status);
        $this->assertSame('456123', (string) DB::table('draw_results')->where('draw_id', $draw->getKey())->value('first_prize'));
        $this->assertGreaterThan(0, DB::table('winning_numbers')->where('draw_id', $draw->getKey())->count());
    }

    #[Test]
    public function publication_refuses_numbers_the_validator_rejects_and_writes_nothing(): void
    {
        $draw = $this->pendingDraw();

        $this->artisan('lottery:publish-result', [
            'draw' => (string) $draw->draw_number,
            '--first-prize' => '12',
            '--bottom-two' => '45',
            '--yes' => true,
        ])->assertFailed();

        $this->assertSame(0, DB::table('draw_results')->count());
        $this->assertSame(DrawStatus::Drawing, $draw->refresh()->status);
    }

    #[Test]
    public function publication_is_refused_for_a_draw_that_is_not_awaiting_its_result(): void
    {
        $draw = $this->draw(DrawStatus::Open, now()->addHour(), now()->subHour(), now()->addMinutes(55));

        $this->artisan('lottery:publish-result', [
            'draw' => (string) $draw->draw_number,
            '--first-prize' => '456123',
            '--bottom-two' => '45',
            '--yes' => true,
        ])->assertFailed();

        $this->assertSame(0, DB::table('draw_results')->count());
    }

    #[Test]
    public function declining_the_confirmation_publishes_nothing(): void
    {
        $draw = $this->pendingDraw();

        $this->artisan('lottery:publish-result', [
            'draw' => (string) $draw->draw_number,
            '--first-prize' => '456123',
            '--bottom-two' => '45',
        ])
            ->expectsConfirmation('Publish these numbers as the official result?', 'no')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('draw_results')->count());
    }

    #[Test]
    public function an_unknown_draw_is_reported_rather_than_created(): void
    {
        $this->artisan('lottery:publish-result', [
            'draw' => 'DR-99999999-9999',
            '--first-prize' => '456123',
            '--bottom-two' => '45',
            '--yes' => true,
        ])->assertFailed();

        $this->assertSame(0, Draw::withTrashed()->count());
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function draw(
        DrawStatus $status,
        \Illuminate\Support\Carbon $scheduledAt,
        ?\Illuminate\Support\Carbon $bettingOpenAt,
        ?\Illuminate\Support\Carbon $bettingCloseAt,
    ): Draw {
        static $sequence = 0;
        $sequence++;

        $draw = new Draw;
        $draw->draw_number = sprintf('DR-TEST-%04d', $sequence);
        $draw->type = DrawType::ThreeD;
        $draw->scheduled_at = $scheduledAt;
        $draw->betting_open_at = $bettingOpenAt;
        $draw->betting_close_at = $bettingCloseAt;

        // status and the lifecycle timestamps are outside Draw::$fillable by design, so
        // the fixture sets them directly rather than weakening the model.
        $draw->status = $status;

        if ($status !== DrawStatus::Scheduled) {
            $draw->opened_at = $bettingOpenAt ?? now()->subDay();
        }

        if (in_array($status, [DrawStatus::Closed, DrawStatus::Drawing, DrawStatus::ResultPublished, DrawStatus::Completed], true)) {
            $draw->closed_at = $bettingCloseAt ?? now()->subHour();
        }

        $draw->save();

        return $draw;
    }

    /**
     * A draw parked in ResultPending, ready for publication.
     */
    private function pendingDraw(): Draw
    {
        $draw = $this->draw(DrawStatus::Closed, now()->subMinutes(10), now()->subDay(), now()->subMinutes(15));

        $this->artisan('lottery:mark-results-pending')->assertSuccessful();

        return $draw->refresh();
    }

    /**
     * A draw with a genuinely published result, published $publishedMinutesAgo ago.
     *
     * Published through the real service rather than by inserting rows, so the
     * settlement under test runs against the state the system actually produces.
     */
    private function publishedDraw(int $publishedMinutesAgo): Draw
    {
        $draw = $this->pendingDraw();

        app(DrawResultPublicationService::class)->publish((int) $draw->getKey(), [
            'first_prize' => '456123',
            'bottom_two' => '45',
        ]);

        $draw->refresh();

        if ($publishedMinutesAgo > 0) {
            // Backdated rather than travelling the clock, because travelling would also
            // move the scheduled_at comparisons the other steps make.
            $draw->result_published_at = now()->subMinutes($publishedMinutesAgo);
            $draw->save();
        }

        return $draw->refresh();
    }
}
