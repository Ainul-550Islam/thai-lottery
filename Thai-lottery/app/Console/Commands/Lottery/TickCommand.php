<?php

declare(strict_types=1);

namespace App\Console\Commands\Lottery;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The single task the scheduler runs: advance every draw that is due, in order.
 *
 * WHY ONE ORCHESTRATOR INSTEAD OF FIVE SCHEDULED TASKS
 * The steps are ordered and the order matters within a single minute. A draw
 * provisioned at 14:54 should be able to open, and a draw whose cut-off is 14:55
 * should close, in the same tick - not one step per tick, which would make the
 * effective cut-off drift by however many steps precede it. Running them in one
 * process also means one lock, one log line, and no chance of two steps interleaving
 * on the same draw.
 *
 * ORDER, AND WHY IT IS THIS ORDER
 *   1. schedule-draws        a draw must exist before it can open
 *   2. open-draws            betting starts
 *   3. close-draws           betting ends at the cut-off
 *   4. mark-results-pending  the draw has happened; we are waiting on numbers
 *   5. settle-draws          the published result is resolved into outcomes
 *
 * Step 4 does not skip to publication: nothing here publishes a result, ever. See
 * PublishDrawResultCommand for why.
 *
 * FAILURE HANDLING
 * A failing step does not stop the following steps - closing must still happen even if
 * provisioning failed - but the tick exits non-zero if any step did, so the scheduler's
 * failure hooks and the operator's monitoring both see it.
 */
final class TickCommand extends Command
{
    protected $signature = 'lottery:tick
        {--dry-run : Report what each step would do without writing anything}
        {--force : Run even when automation is disabled}';

    protected $description = 'Advance every draw that is due to its next lifecycle state';

    /**
     * The ordered steps. Publication is absent on purpose.
     *
     * @var list<string>
     */
    private const STEPS = [
        'lottery:schedule-draws',
        'lottery:open-draws',
        'lottery:close-draws',
        'lottery:mark-results-pending',
        'lottery:settle-draws',
    ];

    public function handle(): int
    {
        $started = microtime(true);
        $options = [];

        if ((bool) $this->option('dry-run') === true) {
            $options['--dry-run'] = true;
        }

        if ((bool) $this->option('force') === true) {
            $options['--force'] = true;
        }

        $failed = [];

        foreach (self::STEPS as $step) {
            $this->line(sprintf('<info>%s</info>', $step));

            $exit = $this->call($step, $options);

            if ($exit !== self::SUCCESS) {
                $failed[] = $step;
            }
        }

        $duration = round((microtime(true) - $started) * 1000);

        if ($failed === []) {
            $this->info(sprintf('Tick complete in %dms; all %d step(s) succeeded.', $duration, count(self::STEPS)));
        } else {
            $this->error(sprintf('Tick complete in %dms; %d step(s) reported failures: %s', $duration, count($failed), implode(', ', $failed)));
        }

        Log::info('lottery.tick', [
            'steps' => count(self::STEPS),
            'failed_steps' => $failed,
            'duration_ms' => $duration,
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
