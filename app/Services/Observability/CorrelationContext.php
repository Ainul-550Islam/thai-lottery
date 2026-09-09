<?php

declare(strict_types=1);

namespace App\Services\Observability;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Request & Operation Correlation Identifier Context.
 *
 * Propagates correlation identifiers across HTTP requests, background jobs,
 * and console operations for end-to-end distributed traceability without leaking secrets.
 */
final class CorrelationContext
{
    private static ?string $currentCorrelationId = null;

    /**
     * Retrieve the current correlation ID, generating a fresh UUID if absent.
     */
    public static function get(): string
    {
        if (self::$currentCorrelationId !== null) {
            return self::$currentCorrelationId;
        }

        if (class_exists(Context::class) && Context::has('correlation_id')) {
            $contextId = (string) Context::get('correlation_id');
            if (trim($contextId) !== '') {
                self::$currentCorrelationId = $contextId;

                return $contextId;
            }
        }

        return self::set(self::generate());
    }

    /**
     * Set the correlation ID for the current execution context and enrich logger.
     */
    public static function set(string $correlationId): string
    {
        // Sanitize: allow only alphanumeric, hyphen, underscore up to 64 chars
        $sanitized = (string) preg_replace('/[^a-zA-Z0-9\-_]/', '', trim($correlationId));
        if ($sanitized === '' || strlen($sanitized) > 64) {
            $sanitized = self::generate();
        }

        self::$currentCorrelationId = $sanitized;

        if (class_exists(Context::class)) {
            Context::add('correlation_id', $sanitized);
        }

        // Share context with Monolog / Laravel Log
        Log::shareContext(['correlation_id' => $sanitized]);

        return $sanitized;
    }

    /**
     * Generate a new RFC-4122 UUID correlation identifier.
     */
    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Clear the correlation context (used during testing or worker loop iteration).
     */
    public static function clear(): void
    {
        self::$currentCorrelationId = null;
        if (class_exists(Context::class)) {
            Context::forget('correlation_id');
        }
    }
}
