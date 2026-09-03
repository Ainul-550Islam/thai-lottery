<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Models\Draw;

/**
 * Moves a closed draw whose scheduled moment has passed to "awaiting result".
 *
 * WHY THIS IS ITS OWN STEP
 * Closed and ResultPending are genuinely different states: Closed means betting has
 * ended but the draw has not happened yet, ResultPending means the draw has happened
 * and the system is waiting for the official numbers. Collapsing them would make
 * "which draws are we waiting on numbers for?" an inference from timestamps instead
 * of a query on status - and that question is exactly what an operator asks.
 *
 * It is also the state DrawResultPublicationService requires: publication is only
 * legal from ResultPending, so without this step no result could ever be published
 * by the normal path.
 *
 * This command does NOT invent numbers, and no scheduled task ever will. The official
 * Thai result comes from outside this system and a human confirms it, per
 * config('lottery.results.require_admin_confirmation'). Publication is
 * lottery:publish-result, run by an operator.
 */
final class MarkResultsPendingCommand extends LotteryAutomationCommand
{
    protected $signature = 'lottery:mark-results-pending
        {--dry-run : List what would be marked without writing anything}
        {--force : Run even when automation is disabled}';

    protected $description = 'Move closed draws whose scheduled time has passed to result-pending';

    protected function activity(): string
    {
        return 'mark result-pending';
    }

    public function handle(): int
    {
        if (! $this->assertAutomationAllowed() || ! $this->assertNotInTransaction()) {
            return self::SUCCESS;
        }

        $lifecycle = app(\App\Services\Draw\DrawLifecycleService::class);

        $this->eachDueDraw(
            $this->schedule->dueForResultPending(),
            function (Draw $draw) use ($lifecycle): string {
                $lifecycle->markResultPending($draw, [
                    'stage' => 'automation',
                    'command' => 'lottery:mark-results-pending',
                ]);

                return 'awaiting official numbers';
            },
        );

        return $this->exitCode();
    }
}
