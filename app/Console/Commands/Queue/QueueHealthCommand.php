<?php

declare(strict_types=1);

namespace App\Console\Commands\Queue;

use App\Services\Queue\QueueHealthService;
use Illuminate\Console\Command;

/**
 * CLI command to monitor queue health and worker metrics.
 */
class QueueHealthCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'queue:health
                            {--json : Output report in JSON format for automated monitoring}';

    /**
     * @var string
     */
    protected $description = 'Check queue liveness, pending job counts, failed jobs, and worker health';

    public function handle(QueueHealthService $healthService): int
    {
        $report = $healthService->check();
        $isJson = (bool) $this->option('json');

        if ($isJson) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT));

            return $report->isHealthy ? self::SUCCESS : self::FAILURE;
        }

        $this->info('=== Queue Infrastructure Health Monitor ===');
        $this->line(sprintf('Status:         %s', $report->isHealthy ? '<fg=green>HEALTHY</>' : '<fg=red>UNHEALTHY</>'));
        $this->line(sprintf('Driver:         <fg=yellow>%s</>', $report->driver));
        $this->line(sprintf('Total Pending:  %d', $report->totalPending));
        $this->line(sprintf('Failed Jobs:    %s', $report->failedCount > 0 ? "<fg=red>{$report->failedCount}</>" : '<fg=green>0</>'));
        $this->line(sprintf('Stuck Jobs:     %s', $report->stuckCount > 0 ? "<fg=red>{$report->stuckCount}</>" : '<fg=green>0</>'));

        $this->newLine();
        $this->info('--- Pending Jobs by Priority Queue ---');

        $rows = [];
        foreach ($report->pendingByQueue as $queue => $count) {
            $rows[] = [$queue, $count];
        }

        $this->table(['Queue Channel', 'Pending Jobs'], $rows);

        if (! empty($report->warnings)) {
            $this->newLine();
            $this->warn('Warnings / Action Required:');
            foreach ($report->warnings as $warning) {
                $this->line(sprintf(' - %s', $warning));
            }
        }

        return $report->isHealthy ? self::SUCCESS : self::FAILURE;
    }
}
