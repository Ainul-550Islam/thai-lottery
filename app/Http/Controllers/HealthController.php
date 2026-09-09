<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Observability\SystemHealthService;
use Illuminate\Http\JsonResponse;

/**
 * Health, Liveness & Readiness Probe Endpoints.
 *
 * Provides non-mutating operational liveness, readiness, and detailed health checks.
 */
class HealthController
{
    public function __construct(
        private readonly SystemHealthService $healthService,
    ) {
    }

    /**
     * Liveness Probe: confirms PHP-FPM / HTTP worker is alive.
     */
    public function live(): JsonResponse
    {
        return response()->json($this->healthService->checkLiveness(), 200);
    }

    /**
     * Readiness Probe: confirms DB, cache, and queue connections are operational.
     */
    public function ready(): JsonResponse
    {
        $result = $this->healthService->checkReadiness();
        $status = $result['ready'] ? 200 : 503;

        return response()->json($result, $status);
    }

    /**
     * Detailed Health & Degraded State Check.
     */
    public function health(): JsonResponse
    {
        $report = $this->healthService->checkDetailedHealth();
        $httpStatus = $report['status'] === 'UNHEALTHY' ? 503 : 200;

        return response()->json($report, $httpStatus);
    }
}
