<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TicketOwnershipResource;
use App\Http\Responses\ApiResponse;
use App\Models\Ticket;
use App\Services\Ticket\TicketOwnershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identity-bound ticket lookup/ownership API.
 *
 * THE UNAUTHORIZED-DISCLOSURE RULE
 * Every read this controller serves is owner-scoped: the caller's OWN
 * bindings only, with the ownership service's stamp as the single source of
 * truth about who may even kNOW of the binding. A foreign ticket number is
 * indistinguishable from an unknown one — same 404, same body, no partial
 * shape hints. Ownership MUTATION stays strictly outside this controller:
 * binding/locking custody transitions ride through the purchase and claim
 * lanes (or operator console) that were built with the service's own
 * four-eyes choreography — the ownership API surface here is read-side
 * write-never.
 *
 * WHAT THE CONTROLLER ITSELF OWNS
 * Route param binding by ticket number, per-principal scoping, service
 * invocation (never reimplementing the stamp protocol inline), and the
 * resource assembly.
 */
final class TicketOwnershipController
{
    public function __construct(
        private readonly TicketOwnershipService $ownership,
    ) {
    }

    /**
     * The caller's own bound tickets.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        $tickets = Ticket::query()
            ->where('user_id', (int) $user->getAuthIdentifier())
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::success(
            data: [
                'items' => collect($tickets->items())
                    ->map(function (Ticket $ticket) use ($request): array {
                        $stamp = $this->ownership->stampFor($ticket);

                        return (new TicketOwnershipResource($ticket, $stamp))->resolve($request);
                    })
                    ->all(),
                'pagination' => [
                    'current_page' => $tickets->currentPage(),
                    'last_page' => $tickets->lastPage(),
                    'per_page' => $tickets->perPage(),
                    'total' => $tickets->total(),
                ],
            ],
            message: 'Bound tickets retrieved successfully.',
        );
    }

    /**
     * One owned ticket by its printed number — with the binding stamp
     * resolved through the ownership lane itself, not by re-reading the
     * metadata at the HTTP layer (which would stale the moment the service
     * rotated custody).
     */
    public function show(string $ticket, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! preg_match('/^[A-Za-z0-9\-]{1,24}$/', $ticket)) {
            return ApiResponse::error(
                code: 'ticket_not_found',
                message: 'Ticket not found.',
                status: 404,
            );
        }

        $row = Ticket::query()
            ->where('ticket_number', $ticket)
            ->where('user_id', (int) $user->getAuthIdentifier())
            ->first();

        if (! $row instanceof Ticket) {
            return ApiResponse::error(
                code: 'ticket_not_found',
                message: 'Ticket not found.',
                status: 404,
            );
        }

        $stamp = $this->ownership->stampFor($row);
        $status = $this->ownership->statusFor($row);

        return ApiResponse::success(
            data: [
                'ownership' => (new TicketOwnershipResource($row, $stamp))->resolve($request),
                'binding' => [
                    'status' => $status?->value,
                    'in_custody' => $status !== null && $status->inCustody(),
                ],
            ],
            message: 'Ticket ownership retrieved successfully.',
        );
    }
}
