<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Draw;

use App\Services\Draw\DrawScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The calendar arithmetic behind draw automation.
 *
 * Pure config in, moments out - no database, no draws. What is pinned here is the part
 * that decides WHEN a draw happens, because a bug in it does not throw: it silently
 * schedules a draw on the wrong day or accepts bets past the cut-off.
 */
final class DrawScheduleServiceTest extends TestCase
{
    private function service(): DrawScheduleService
    {
        return app(DrawScheduleService::class);
    }

    // -------------------------------------------------------------------------
    // The declared calendar
    // -------------------------------------------------------------------------

    #[Test]
    public function it_generates_the_declared_days_of_month_at_the_declared_time(): void
    {
        config([
            'lottery.timezone' => 'Asia/Bangkok',
            'lottery.draw.official_schedule.days_of_month' => [1, 16],
            'lottery.draw.official_schedule.time' => '15:00',
        ]);

        $occurrences = $this->service()->occurrencesBetween(
            CarbonImmutable::parse('2026-03-01 00:00', 'Asia/Bangkok'),
            CarbonImmutable::parse('2026-04-30 23:59', 'Asia/Bangkok'),
        );

        $this->assertSame(
            ['2026-03-01 15:00', '2026-03-16 15:00', '2026-04-01 15:00', '2026-04-16 15:00'],
            array_map(static fn (CarbonImmutable $m): string => $m->format('Y-m-d H:i'), $occurrences),
        );
    }

    #[Test]
    public function a_day_the_month_does_not_have_is_skipped_and_never_rolled_forward(): void
    {
        // Carbon would turn "31 February" into 3 March. Drawing on a day the operator
        // never declared is worse than not drawing, so the occurrence is dropped.
        config([
            'lottery.timezone' => 'UTC',
            'lottery.draw.official_schedule.days_of_month' => [31],
            'lottery.draw.official_schedule.time' => '15:00',
        ]);

        $occurrences = $this->service()->occurrencesBetween(
            CarbonImmutable::parse('2026-02-01 00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-31 23:59', 'UTC'),
        );

        $this->assertSame(
            ['2026-03-31 15:00'],
            array_map(static fn (CarbonImmutable $m): string => $m->format('Y-m-d H:i'), $occurrences),
        );
    }

    #[Test]
    public function the_boundaries_are_inclusive_so_a_horizon_landing_on_a_draw_includes_it(): void
    {
        config([
            'lottery.timezone' => 'UTC',
            'lottery.draw.official_schedule.days_of_month' => [16],
            'lottery.draw.official_schedule.time' => '15:00',
        ]);

        $exact = $this->service()->occurrencesBetween(
            CarbonImmutable::parse('2026-05-16 15:00', 'UTC'),
            CarbonImmutable::parse('2026-05-16 15:00', 'UTC'),
        );

        $this->assertCount(1, $exact);

        $justAfter = $this->service()->occurrencesBetween(
            CarbonImmutable::parse('2026-05-16 15:01', 'UTC'),
            CarbonImmutable::parse('2026-05-31 15:00', 'UTC'),
        );

        $this->assertSame([], $justAfter);
    }

    #[Test]
    public function an_inverted_range_yields_nothing_rather_than_looping(): void
    {
        $this->assertSame([], $this->service()->occurrencesBetween(
            CarbonImmutable::parse('2026-06-01'),
            CarbonImmutable::parse('2026-05-01'),
        ));
    }

    #[Test]
    public function an_empty_or_invalid_calendar_yields_no_occurrence(): void
    {
        config(['lottery.draw.official_schedule.days_of_month' => [0, 32, 'x', -5]]);

        $this->assertSame([], $this->service()->occurrencesBetween(now(), now()->addYear()));
    }

    #[Test]
    public function a_malformed_time_falls_back_to_midday_rather_than_to_midnight(): void
    {
        config([
            'lottery.timezone' => 'UTC',
            'lottery.draw.official_schedule.days_of_month' => [10],
            'lottery.draw.official_schedule.time' => 'not-a-time',
        ]);

        $occurrences = $this->service()->occurrencesBetween(
            CarbonImmutable::parse('2026-07-01', 'UTC'),
            CarbonImmutable::parse('2026-07-31', 'UTC'),
        );

        $this->assertCount(1, $occurrences);
        $this->assertSame('12:00', $occurrences[0]->format('H:i'));
    }

    // -------------------------------------------------------------------------
    // Timezone
    // -------------------------------------------------------------------------

    #[Test]
    public function the_declared_time_is_the_market_timezone_not_the_server_timezone(): void
    {
        config([
            'lottery.timezone' => 'Asia/Bangkok',
            'lottery.draw.official_schedule.days_of_month' => [16],
            'lottery.draw.official_schedule.time' => '15:00',
        ]);

        $occurrence = $this->service()->occurrencesBetween(
            CarbonImmutable::parse('2026-05-01', 'UTC'),
            CarbonImmutable::parse('2026-05-31', 'UTC'),
        )[0];

        // 15:00 in Bangkok is 08:00 UTC. A draw stored as 15:00 UTC would run seven
        // hours late for every player in the market.
        $this->assertSame('2026-05-16 15:00', $occurrence->format('Y-m-d H:i'));
        $this->assertSame('2026-05-16 08:00', $occurrence->utc()->format('Y-m-d H:i'));
    }

    // -------------------------------------------------------------------------
    // The betting window
    // -------------------------------------------------------------------------

    #[Test]
    public function betting_closes_the_configured_number_of_minutes_before_the_draw(): void
    {
        config([
            'lottery.closing.minutes_before_draw' => 5,
            'lottery.closing.grace_seconds' => 0,
        ]);

        $this->assertSame(
            '2026-05-16 14:55:00',
            $this->service()
                ->bettingCloseAtFor(CarbonImmutable::parse('2026-05-16 15:00:00'))
                ->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function the_configured_grace_seconds_extend_the_cut_off(): void
    {
        config([
            'lottery.closing.minutes_before_draw' => 5,
            'lottery.closing.grace_seconds' => 30,
        ]);

        $this->assertSame(
            '2026-05-16 14:55:30',
            $this->service()
                ->bettingCloseAtFor(CarbonImmutable::parse('2026-05-16 15:00:00'))
                ->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function betting_opens_when_the_previous_draws_window_closed_so_there_is_no_dead_period(): void
    {
        config([
            'lottery.timezone' => 'UTC',
            'lottery.draw.official_schedule.days_of_month' => [1, 16],
            'lottery.draw.official_schedule.time' => '15:00',
            'lottery.closing.minutes_before_draw' => 5,
            'lottery.closing.grace_seconds' => 0,
        ]);

        $opensAt = $this->service()->bettingOpenAtFor(CarbonImmutable::parse('2026-05-16 15:00', 'UTC'));

        $this->assertSame('2026-05-01 14:55:00', $opensAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function the_fallback_window_is_used_when_the_calendar_has_no_previous_occurrence(): void
    {
        config([
            'lottery.timezone' => 'UTC',
            // A calendar with a single yearly draw: two months back finds nothing.
            'lottery.draw.official_schedule.days_of_month' => [16],
            'lottery.draw.official_schedule.time' => '15:00',
            'lottery.draw.betting_window_days' => 7,
        ]);

        $service = $this->service();
        $scheduled = CarbonImmutable::parse('2026-05-16 15:00', 'UTC');

        // Force the "no previous occurrence" branch by narrowing the calendar to a day
        // that only exists in the target month's window.
        config(['lottery.draw.official_schedule.days_of_month' => [16]]);

        $previous = $service->previousOccurrenceBefore($scheduled);
        $this->assertNotNull($previous, 'A monthly calendar does have a previous occurrence.');

        // Now a calendar with no valid day at all: the fallback must apply.
        config(['lottery.draw.official_schedule.days_of_month' => []]);

        $this->assertNull($service->previousOccurrenceBefore($scheduled));
        $this->assertSame(
            '2026-05-09 15:00:00',
            $service->bettingOpenAtFor($scheduled)->format('Y-m-d H:i:s'),
        );
    }

    // -------------------------------------------------------------------------
    // Identity
    // -------------------------------------------------------------------------

    #[Test]
    public function the_draw_number_is_derived_from_the_moment_so_provisioning_can_repeat(): void
    {
        config([
            'lottery.timezone' => 'Asia/Bangkok',
            'lottery.draw.reference_prefix' => 'DR',
        ]);

        $service = $this->service();
        $moment = CarbonImmutable::parse('2026-05-16 15:00', 'Asia/Bangkok');

        $this->assertSame('DR-20260516-1500', $service->drawNumberFor($moment));

        // The same instant expressed in another timezone must produce the SAME
        // identifier, otherwise a run from a differently configured server would
        // duplicate every draw.
        $this->assertSame('DR-20260516-1500', $service->drawNumberFor($moment->utc()));
        $this->assertSame($service->drawNumberFor($moment), $service->drawNumberFor(Carbon::parse($moment)));
    }

    #[Test]
    public function a_blank_prefix_falls_back_to_the_documented_default(): void
    {
        config(['lottery.draw.reference_prefix' => '   ']);

        $this->assertStringStartsWith(
            DrawScheduleService::DEFAULT_PREFIX.'-',
            $this->service()->drawNumberFor(CarbonImmutable::parse('2026-05-16 15:00')),
        );
    }

    // -------------------------------------------------------------------------
    // Switches
    // -------------------------------------------------------------------------

    #[Test]
    public function the_switches_and_bounds_are_read_from_config(): void
    {
        config([
            'lottery.automation.enabled' => false,
            'lottery.automation.auto_settle' => false,
            'lottery.closing.auto_close' => true,
            'lottery.automation.batch_size' => 0,
            'lottery.automation.tick_cron' => '  */5 * * * *  ',
        ]);

        $service = $this->service();

        $this->assertFalse($service->automationEnabled());
        $this->assertFalse($service->autoSettleEnabled());
        $this->assertTrue($service->autoCloseEnabled());
        // A batch size of zero would process nothing forever, so it is floored at one.
        $this->assertSame(1, $service->batchSize());
        $this->assertSame('*/5 * * * *', $service->tickCron());
    }

    #[Test]
    public function an_unusable_cron_expression_falls_back_to_every_minute(): void
    {
        config(['lottery.automation.tick_cron' => '']);

        $this->assertSame('* * * * *', $this->service()->tickCron());
    }

    #[Test]
    public function the_audit_states_what_this_service_does_not_do(): void
    {
        $audit = $this->service()->audit();

        $this->assertFalse($audit['writes_status'], 'Only DrawLifecycleService may write a draw status.');
        $this->assertFalse($audit['writes_money']);
        $this->assertFalse($audit['publishes_results']);
        $this->assertSame('config(lottery.draw.official_schedule)', $audit['calendar_source']);
    }
}
