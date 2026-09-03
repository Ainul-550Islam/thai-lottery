<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the scheduler is, and is not, told to run.
 *
 * WHY THIS SUITE EXISTS
 * Before this work bootstrap/app.php declared no schedule at all, so the entire draw
 * lifecycle was inert. A test that only checked the commands would not have caught
 * that: each command worked perfectly and nothing ever called them. These assertions
 * are about the wiring itself.
 *
 * The second half is the more important half: it pins that result publication is NOT
 * scheduled. The official numbers come from outside this system, and a future
 * well-meaning change that "automates the last manual step" would turn a lottery into
 * a random number generator. This test is the tripwire for that.
 */
final class ScheduleRegistrationTest extends TestCase
{
    /**
     * @return list<Event>
     */
    private function events(): array
    {
        // withSchedule() registers on Artisan::starting, so the console kernel has to be
        // booted before the Schedule instance knows anything. Resolving the command list
        // is the cheapest way to boot it.
        app(\Illuminate\Contracts\Console\Kernel::class)->all();

        return app(Schedule::class)->events();
    }

    #[Test]
    public function the_draw_tick_is_scheduled(): void
    {
        $commands = array_map(static fn (Event $event): string => (string) $event->command, $this->events());

        $this->assertNotSame([], $commands, 'The application must declare a schedule; it declared none before this phase.');

        $matching = array_values(array_filter(
            $commands,
            static fn (string $command): bool => str_contains($command, 'lottery:tick'),
        ));

        $this->assertCount(1, $matching, 'Exactly one tick task, so two runs cannot advance the same draw in one minute.');
    }

    #[Test]
    public function the_tick_runs_on_the_configured_expression(): void
    {
        $event = $this->tickEvent();

        $this->assertSame(
            (string) config('lottery.automation.tick_cron', '* * * * *'),
            $event->expression,
            'The cadence is configuration, not a literal in the bootstrap file.',
        );
    }

    #[Test]
    public function the_tick_cannot_overlap_itself(): void
    {
        $event = $this->tickEvent();

        $this->assertTrue($event->withoutOverlapping, 'Two concurrent ticks would both see the same draw as due.');
        $this->assertTrue($event->onOneServer, 'On a multi-server deployment only one host may advance a draw.');
        $this->assertTrue($event->runInBackground, 'A tick that settles a large draw must not block the scheduler.');
    }

    #[Test]
    public function the_tick_leaves_a_trace_an_operator_can_read(): void
    {
        $event = $this->tickEvent();

        $this->assertNotNull($event->description);
        $this->assertNotNull($event->output);
        $this->assertStringContainsString('lottery-tick.log', (string) $event->output);
        $this->assertTrue($event->shouldAppendOutput, 'Appending, so the previous run is not overwritten.');
    }

    #[Test]
    public function result_publication_is_never_scheduled(): void
    {
        $events = $this->events();
        $this->assertNotSame([], $events);

        foreach ($events as $event) {
            $this->assertStringNotContainsString(
                'lottery:publish-result',
                (string) $event->command,
                'The official numbers come from outside this system and a human confirms them. '
                .'A scheduled publication would invent a lottery result.',
            );
        }
    }

    #[Test]
    public function no_scheduled_task_touches_money(): void
    {
        // A tripwire for the phases that are not built yet: the day payouts, deposits or
        // withdrawals gain automation, that automation must be a deliberate decision
        // with its own review, not something that appears in this file unnoticed.
        $events = $this->events();
        $this->assertNotSame([], $events);

        foreach ($events as $event) {
            $command = (string) $event->command;

            foreach (['payout', 'withdraw', 'deposit', 'payment', 'settle-real'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $command);
            }
        }
    }

    #[Test]
    public function every_lottery_command_is_registered_with_artisan(): void
    {
        $registered = array_keys(app(\Illuminate\Contracts\Console\Kernel::class)->all());

        foreach ([
            'lottery:tick',
            'lottery:schedule-draws',
            'lottery:open-draws',
            'lottery:close-draws',
            'lottery:mark-results-pending',
            'lottery:settle-draws',
            'lottery:publish-result',
        ] as $command) {
            $this->assertContains($command, $registered);
        }
    }

    private function tickEvent(): Event
    {
        foreach ($this->events() as $event) {
            if (str_contains((string) $event->command, 'lottery:tick')) {
                return $event;
            }
        }

        $this->fail('The lottery:tick task is not scheduled.');
    }
}
