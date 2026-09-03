<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use App\Models\Draw;
use App\Services\Draw\DrawScheduleService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared behaviour for every unattended lottery command.
 *
 * WHY A BASE CLASS
 * The five automation commands differ only in which draws they select and which
 * lifecycle call they make. Everything else is identical and is exactly the part that
 * is easy to get wrong in a job that runs every minute without a human watching:
 *
 * 1. THE KILL SWITCH IS CHECKED ONCE, IN ONE PLACE. With
 *    config('lottery.automation.enabled') false, every command refuses unless it is
 *    run with --force. A maintenance window is therefore one env var, and no command
 *    can forget to honour it.
 *
 * 2. NO COMMAND OPENS A TRANSACTION. DrawSettlementSimulationService refuses to run
 *    when DB::transactionLevel() > 0, because the rollback guarantee has to belong to
 *    the settlement itself. A wrapping transaction here would break settlement, so
 *    this class asserts it is NOT inside one before doing any work, and each draw is
 *    processed in its own transaction owned by the service that needs one.
 *
 * 3. ONE BAD DRAW DOES NOT STOP THE BATCH. Each draw is processed inside its own
 *    try/catch. A draw that refuses its transition is reported and skipped, the rest
 *    still advance, and the command exits non-zero so the scheduler's failure
 *    handling still sees that something needs attention.
 *
 * 4. WORK IS BOUNDED. A batch is capped by config('lottery.automation.batch_size'),
 *    so a backlog cannot turn a one-minute tick into a job that is still running when
 *    the next tick starts.
 *
 * 5. EVERY RUN IS LOGGED. Unattended work that leaves no trace cannot be debugged
 *    after the fact, so each command logs its counts under a stable channel key.
 *
 * WHAT THESE COMMANDS NEVER DO
 * They never write draws.status directly - DrawLifecycleService owns every transition.
 * They never publish a result. They never touch money: there is no wallet, ledger,
 * payout or gateway reference anywhere in this namespace, and the settlement they
 * trigger is the declared non-monetary Phase 5.1 simulation.
 */
abstract class LotteryAutomationCommand extends Command
{
    /**
     * Draws that could not be advanced, as id => reason.
     *
     * @var array<int, string>
     */
    protected array $failures = [];

    public function __construct(protected readonly DrawScheduleService $schedule)
    {
        parent::__construct();
    }

    /**
     * A one-line description of what this command advances, used in output and logs.
     */
    abstract protected function activity(): string;

    /**
     * Refuse to run when automation is off, unless the operator forced it.
     *
     * Returns true when the command may proceed.
     */
    protected function assertAutomationAllowed(): bool
    {
        if ($this->schedule->automationEnabled()) {
            return true;
        }

        if ((bool) $this->option('force') === true) {
            $this->warn('Automation is disabled in config(lottery.automation.enabled); proceeding because --force was given.');

            return true;
        }

        $this->warn('Automation is disabled (config lottery.automation.enabled = false). Nothing was done. Re-run with --force to override.');

        return false;
    }

    /**
     * Refuse to run inside a transaction the command does not own.
     *
     * Returns true when it is safe to proceed.
     */
    protected function assertNotInTransaction(): bool
    {
        $level = DB::transactionLevel();

        if ($level === 0) {
            return true;
        }

        $this->error(sprintf(
            'Refusing to run inside an open transaction (level %d). Settlement and the lifecycle must own their own transaction boundaries.',
            $level,
        ));

        return false;
    }

    /**
     * Apply $handler to each due draw, isolating failures.
     *
     * @param  Builder<Draw>  $query
     * @param  callable(Draw): string  $handler  returns a short outcome for the report
     * @return array{processed: int, failed: int, skipped: int}
     */
    protected function eachDueDraw(Builder $query, callable $handler): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->schedule->batchSize();

        /** @var list<Draw> $draws */
        $draws = $query->limit($limit)->get()->all();

        if ($draws === []) {
            $this->line(sprintf('No draws are due to %s.', $this->activity()));

            return ['processed' => 0, 'failed' => 0, 'skipped' => 0];
        }

        $processed = 0;
        $skipped = 0;

        foreach ($draws as $draw) {
            $drawId = (int) $draw->getKey();

            if ($dryRun) {
                $this->line(sprintf(
                    '  [dry-run] would %s draw %s (id %d, scheduled %s)',
                    $this->activity(),
                    (string) $draw->draw_number,
                    $drawId,
                    (string) $draw->scheduled_at,
                ));
                $skipped++;

                continue;
            }

            try {
                $outcome = $handler($draw);
                $processed++;

                $this->line(sprintf('  %s %s -> %s', '✓', (string) $draw->draw_number, $outcome));
            } catch (Throwable $exception) {
                // The message is shown to an operator on a terminal, not to a player
                // over HTTP, so the domain refusal is safe to display. The exception
                // itself goes to the log with its full context.
                $this->failures[$drawId] = $exception->getMessage();

                $this->error(sprintf('  ✗ %s: %s', (string) $draw->draw_number, $exception->getMessage()));

                Log::error('lottery.automation.draw_failed', [
                    'command' => $this->getName(),
                    'activity' => $this->activity(),
                    'draw_id' => $drawId,
                    'draw_number' => (string) $draw->draw_number,
                    'exception' => $exception,
                ]);
            }
        }

        $counts = [
            'processed' => $processed,
            'failed' => count($this->failures),
            'skipped' => $skipped,
        ];

        $this->reportCounts($counts, count($draws), $limit);

        return $counts;
    }

    /**
     * @param  array{processed: int, failed: int, skipped: int}  $counts
     */
    protected function reportCounts(array $counts, int $candidates, int $limit): void
    {
        $this->info(sprintf(
            '%s: %d processed, %d failed, %d skipped (of %d candidates).',
            ucfirst($this->activity()),
            $counts['processed'],
            $counts['failed'],
            $counts['skipped'],
            $candidates,
        ));

        if ($candidates === $limit) {
            // The batch was full, so there may be more waiting. Said out loud rather
            // than left for the operator to infer from the numbers.
            $this->warn(sprintf(
                'The batch limit of %d was reached; more draws may still be due. The next run will continue.',
                $limit,
            ));
        }

        Log::info('lottery.automation.run', [
            'command' => $this->getName(),
            'activity' => $this->activity(),
            'processed' => $counts['processed'],
            'failed' => $counts['failed'],
            'skipped' => $counts['skipped'],
            'candidates' => $candidates,
        ]);
    }

    /**
     * The process exit code: 0 only when nothing failed.
     */
    protected function exitCode(): int
    {
        return $this->failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
