<?php

declare(strict_types=1);

namespace App\Services\Observability;

use Illuminate\Support\Facades\Http;

/**
 * Minimal Prometheus text-format parser used by the metrics exporter.
 *
 * The metrics controller / route returns OpenMetrics text directly; this tiny client exists
 * so that one exporter can safely relay another deployment's exposition through `GET /metrics`
 * without leaking the uptime URL, credentials or any scalar that is not a published metric.
 *
 * Only three pieces of information are ever read from the target: whether the request
 * succeeded, the response status, and the parsed metric line families. An API host that is
 * misconfigured, timing out, returning an error page or a non-prometheus payload simply
 * yields an empty family map rather than crashing the exporter.
 */
final class PrometheusClient
{
    /**
     * Fetch and parse a Prometheus text exposition endpoint.
     *
     * @return array<string, mixed>
     */
    public function fetch(string $url): array
    {
        try {
            $response = Http::timeout(3)->get($url);
        } catch (\Throwable) {
            return [
                'ok' => false,
                'status' => 0,
                'metrics' => [],
            ];
        }

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'metrics' => $this->parse($response->body()),
        ];
    }

    /**
     * Parse Prometheus text exposition format into metric families without retaining the
     * original scalar values as a separate concept: each family maps to its raw text lines
     * in order, which is all the relay needs.
     *
     * @return array<string, list<string>>
     */
    public function parse(string $exposition): array
    {
        $families = [];

        foreach (preg_split('/\R/', $exposition) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // A metric sample line is `name{labels} value [timestamp]` or `name value`.
            if (preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)\b/', $line, $match) !== 1) {
                continue;
            }

            $name = $match[1];
            $families[$name] = $families[$name] ?? [];
            $families[$name][] = $line;
        }

        return $families;
    }
}
