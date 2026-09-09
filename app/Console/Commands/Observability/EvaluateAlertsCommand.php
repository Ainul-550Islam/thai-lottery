<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use App\Services\Observability\OperationalAlertService;
use Illuminate\Console\Command;

/**
 * CLI command to evaluate system health thresholds and dispatch throttled alerts.
 */
class EvaluateAlertsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ops:evaluate-alerts';

    /**
     * @var string
     */
    protected $description = 'Evaluate operational health metrics and dispatch throttled alerts for degradations';

    public function handle(OperationalAlertService $alertService): int
    {
        $this->info('Evaluating system metrics for operational anomalies...');
        $triggered = $alertService->evaluateAndAlert();

        if ($triggered === []) {
            $this->info('All operational metrics within acceptable bounds. Zero alerts dispatched.');
        } else {
            $this->warn(sprintf('Dispatched %d operational alert(s): %s', count($triggered), implode(', ', $triggered)));
        }

        return self::SUCCESS;
    }
}
