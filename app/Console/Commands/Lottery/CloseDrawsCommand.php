<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Models\Draw;

/**
 * Closes betting on draws whose cut-off has passed.
 *
 * THIS IS THE COMMAND THAT MAKES config('lottery.closing') REAL. That block has
 * declared auto_close = true and minutes_before_draw = 5 since Phase 1, and until now
 * nothing read it: a draw stayed Open past its cut-off forever. Late bets were still
 * refused, because BetValidationService independently checks betting_close_at - so
 * this was never a money hole - but the draw's status lied about its state, and a
 * closed-but-Open draw could never reach settlement.
 *
 * The cut-off arithmetic is NOT repeated here. DrawScheduleService::dueToClose()
 * selects on the stored betting_close_at, which was itself computed by
 * DrawScheduleService::bettingCloseAtFor(), so the moment a bet stops being accepted
 * and the moment the closer acts are derived from the same single expression.
 *
 * When config('lottery.closing.auto_close') is false the command refuses unless
 * --force is given, because in that mode closing is an operator's decision.
 */
final class CloseDrawsCommand extends LotteryAutomationCommand
{
    protected $signature = 'lottery:close-draws
        {--dry-run : List what would be closed without writing anything}
        {--force : Run even when automation or auto-close is disabled}';

    protected $description = 'Close betting on draws whose cut-off has passed';

    protected function activity(): string
    {
        return 'close';
    }

    public function handle(): int
    {
        if (! $this->assertAutomationAllowed() || ! $this->assertNotInTransaction()) {
            return self::SUCCESS;
        }

        if (! $this->schedule->autoCloseEnabled() && (bool) $this->option('force') !== true) {
            $this->warn('config(lottery.closing.auto_close) is false, so closing is a manual operation. Nothing was done. Re-run with --force to override.');

            return self::SUCCESS;
        }

        $lifecycle = app(\App\Services\Draw\DrawLifecycleService::class);

        $this->eachDueDraw(
            $this->schedule->dueToClose(),
            function (Draw $draw) use ($lifecycle): string {
                $lifecycle->close($draw, [
                    'stage' => 'automation',
                    'command' => 'lottery:close-draws',
                ]);

                return 'closed';
            },
        );

        return $this->exitCode();
    }
}
