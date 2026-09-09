<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use App\Services\Observability\FinancialMetricsCollector;
use Illuminate\Console\Command;

/**
 * CLI command to export system and financial metrics.
 */
class MetricsExportCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'ops:metrics-export
                            {--json : Output metrics in JSON format instead of Prometheus text}
                            {--format= : Output format (prometheus or json)}';

    /**
     * @var list<string>
     */
    protected $aliases = ['metrics:export'];

    /**
     * @var string
     */
    protected $description = 'Export operational and financial metrics in Prometheus or JSON format';

    public function handle(FinancialMetricsCollector $metricsCollector): int
    {
        $isJson = (bool) $this->option('json') || strtolower((string) $this->option('format')) === 'json';

        if ($isJson) {
            $this->line(json_encode($metricsCollector->collect(), JSON_PRETTY_PRINT));
        } else {
            $this->line($metricsCollector->toPrometheusFormat());
        }

        return self::SUCCESS;
    }
}
