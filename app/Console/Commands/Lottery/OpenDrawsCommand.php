<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Models\Draw;

/**
 * Opens betting on draws whose planned window has started.
 *
 * A draw is opened only if its betting window has begun AND has not already ended.
 * A draw provisioned late - after its own cut-off - is deliberately left in Draft:
 * opening it would produce a draw that advertises itself as open while
 * BetValidationService rejects every bet against it on the betting_close_at check,
 * which is a worse outcome than a draw that never opened.
 *
 * The transition itself is DrawLifecycleService::open(), which locks the row and
 * re-reads the state inside the lock, so two concurrent runs cannot both open the
 * same draw - the second is refused by the transition table and reported as a
 * failure for that draw only.
 */
final class OpenDrawsCommand extends LotteryAutomationCommand
{
    protected $signature = 'lottery:open-draws
        {--dry-run : List what would be opened without writing anything}
        {--force : Run even when automation is disabled}';

    protected $description = 'Open betting on draws whose planned betting window has started';

    protected function activity(): string
    {
        return 'open';
    }

    public function handle(): int
    {
        if (! $this->assertAutomationAllowed() || ! $this->assertNotInTransaction()) {
            return self::SUCCESS;
        }

        $lifecycle = app(\App\Services\Draw\DrawLifecycleService::class);

        $this->eachDueDraw(
            $this->schedule->dueToOpen(),
            function (Draw $draw) use ($lifecycle): string {
                $lifecycle->open($draw, [
                    'stage' => 'automation',
                    'command' => 'lottery:open-draws',
                ]);

                return sprintf('open (betting closes %s)', (string) $draw->betting_close_at);
            },
        );

        return $this->exitCode();
    }
}
