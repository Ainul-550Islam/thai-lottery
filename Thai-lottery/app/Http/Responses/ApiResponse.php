<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

/**
 * The single JSON envelope for every Phase 4.4 API response.
 *
 * WHY THIS CLASS EXISTS
 * Before Phase 4.4 the project had no HTTP API at all: routes/api.php was an empty
 * placeholder, so there was no existing response convention to preserve. This class
 * establishes exactly one, and every controller in this phase returns through it, so a
 * client can parse success and failure with one code path instead of guessing per
 * endpoint.
 *
 * THE TWO SHAPES
 * Success:
 *   {"success": true, "message": "...", "data": {...}}
 * Error:
 *   {"success": false, "error": {"code": "...", "message": "...", "details": {...}}}
 *
 * The `success` boolean is present in both shapes on purpose. A client that only
 * inspects the body - a webview, a retry wrapper, a log pipeline - can branch without
 * access to the HTTP status line.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No database access, no model loading and no lazy relation touching. A response
 *   builder that queries is a response builder that can fail after the transaction it
 *   is reporting on has already committed.
 * - No money arithmetic. Amounts arrive here as already-formatted decimal strings from
 *   the domain layer; this class never adds, multiplies, rounds or casts them.
 * - No exception translation. Turning a domain exception into a code and a status is
 *   BetPurchaseErrorMapper's job; this class only serialises what it is handed.
 *
 * SECURITY
 * Nothing is placed in an envelope by this class itself. It cannot leak a stack trace,
 * a SQL fragment or an internal identifier, because it adds no data of its own - the
 * caller decides what goes in `data` and `details`, and the mapper whitelists those
 * fields explicitly.
 */
final class ApiResponse
{
    /**
     * A successful response.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta  optional non-payload context (pagination,
     *                                      replay flags); omitted from the body entirely
     *                                      when empty rather than serialised as null
     */
    public static function success(
        array $data,
        string $message = 'OK',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        $body = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status);
    }

    /**
     * A failure response.
     *
     * `details` is always present, as an object, even when empty. A client that reads
     * `error.details.field` does not then have to guard against the key being absent on
     * some failures and present on others.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function error(
        string $code,
        string $message,
        int $status,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details === [] ? new \stdClass() : $details,
            ],
        ], $status, $headers);
    }
}
