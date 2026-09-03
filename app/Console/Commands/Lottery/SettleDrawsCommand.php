<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\DTOs\SettlementSimulationResult;
use App\Models\Draw;
use App\Services\Draw\DrawSettlementSimulationService;

/**
 * Settles published draws as the Phase 5.1 NON-MONETARY simulation.
 *
 * WHAT "SETTLE" MEANS HERE, PRECISELY
 * It resolves every selection against the published result, records whether it matched
 * and what it would have been worth, and moves the draw to Settled. It moves NO MONEY:
 * no wallet balance changes, no ledger entry is written, no financial transaction is
 * created, no payouts row appears, and bets.payout_id stays NULL. That is not a
 * limitation this command works around - it is the declared contract of
 * DrawSettlementSimulationService, whose MODE is the class constant 'simulation'.
 * Real-money payout is Phase 5.2 and does not exist yet.
 *
 * WHY IT IS SAFE TO RUN UNATTENDED
 * Because it moves no money. That is also why it has its own switch,
 * config('lottery.automation.auto_settle'), rather than reusing
 * config('lottery.payouts.auto_process') - that one governs the real-money payout that
 * does not exist, and it stays false. Conflating the two would mean that enabling
 * simulated settlement also pre-authorised unattended payouts the day Phase 5.2 lands.
 *
 * WHY THERE IS A DELAY
 * config('lottery.automation.settlement_delay_minutes') is measured from
 * result_published_at, giving an operator a window to notice a mistyped result before
 * the draw becomes terminal. Settled is terminal and this project implements nothing
 * reversal-shaped, so that window is the only chance to catch the mistake.
 *
 * IDEMPOTENT. The service replays a stored settlement without writing when the draw is
 * already Settled, so a repeated or overlapping run cannot double-settle.
 */
final class SettleDrawsCommand extends LotteryAutomationCommand
{
    protected $signature = 'lottery:settle-draws
        {--draw= : Settle one draw by id or draw number, ignoring the delay}
        {--dry-run : List what would be settled without writing anything}
        {--force : Run even when automation or auto-settle is disabled}';

    protected $description = 'Settle published draws as the non-monetary Phase 5.1 simulation';

    protected function activity(): string
    {
        return 'settle';
    }

    public function handle(): int
    {
        if (! $this->assertAutomationAllowed() || ! $this->assertNotInTransaction()) {
            return self::SUCCESS;
        }

        $single = $this->option('draw');

        if (! $this->schedule->autoSettleEnabled() && $single === null && (bool) $this->option('force') !== true) {
            $this->warn('config(lottery.automation.auto_settle) is false. Nothing was done. Settle a specific draw with --draw=, or re-run with --force.');

            return self::SUCCESS;
        }

        $settlement = app(DrawSettlementSimulationService::class);

        $query = $this->schedule->dueToSettle();

        if ($single !== null) {
            // An explicitly named draw bypasses the delay - the operator is the reason
            // the delay exists, so an operator asking for it now needs no wait - but it
            // does NOT bypass the lifecycle: an unpublished draw is still refused by
            // assertCanSettle().
            $query = Draw::query()
                ->where(function ($inner) use ($single): void {
                    $inner->where('draw_number', (string) $single);

                    if (is_numeric($single)) {
                        $inner->orWhere('id', (int) $single);
                    }
                })
                ->orderBy('scheduled_at');
        }

        $this->eachDueDraw(
            $query,
            function (Draw $draw) use ($settlement): string {
                $result = $settlement->settle((int) $draw->getKey());

                $this->reportSettlement($result);

                return $result->performedWork()
                    ? sprintf(
                        'settled: %d selection(s), %d winner(s), simulated %s %s',
                        $result->selectionsEvaluated,
                        $result->winningSelections,
                        $result->totalSimulatedPrize,
                        $result->currency,
                    )
                    : 'already settled (nothing written)';
            },
        );

        return $this->exitCode();
    }

    /**
     * Print the non-monetary facts, so an operator reading the log can see that a
     * settlement moved no money rather than having to trust that it did not.
     */
    private function reportSettlement(SettlementSimulationResult $result): void
    {
        if (! $this->output->isVerbose()) {
            return;
        }

        $this->line(sprintf(
            '      mode=%s  state %s -> %s  first_prize=%s  bottom_two=%s  stake=%s  simulated_prize=%s  wallets_touched=0  ledger_entries=0',
            $result->mode,
            $result->stateBefore->value,
            $result->stateAfter->value,
            $result->firstPrize,
            $result->bottomTwo,
            $result->totalStake,
            $result->totalSimulatedPrize,
        ));
    }
}
