<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Observability\FinancialMetricsCollector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * OpenMetrics / Prometheus Telemetry Exporter Endpoint.
 *
 * Exposes real-time system gauges and counters for Prometheus / Grafana scraping.
 */
class MetricsController
{
    public function __construct(
        private readonly FinancialMetricsCollector $metricsCollector,
    ) {
    }

    /**
     * Export system telemetry in Prometheus text or JSON format.
     */
    public function metrics(Request $request): Response|JsonResponse
    {
        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json($this->metricsCollector->collect(), 200);
        }

        $prometheusText = $this->metricsCollector->toPrometheusFormat();

        return response($prometheusText, 200, [
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
        ]);
    }
}
