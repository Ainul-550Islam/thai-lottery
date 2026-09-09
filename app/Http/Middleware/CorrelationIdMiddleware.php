<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Observability\CorrelationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to establish, propagate, and attach request correlation IDs.
 *
 * Incoming requests receive a unique correlation ID that travels through all
 * application layers, logs, database audits, and response headers.
 */
class CorrelationIdMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incomingId = (string) ($request->header('X-Correlation-ID')
            ?: $request->header('X-Request-ID')
            ?: $request->header('X-Request-Id', ''));

        $correlationId = CorrelationContext::set($incomingId);

        $request->attributes->set('correlation_id', $correlationId);
        $request->attributes->set('request_id', $correlationId);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Request-ID', $correlationId);

        return $response;
    }
}
