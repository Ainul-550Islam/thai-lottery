<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\TicketResource;
use App\Http\Responses\ApiResponse;
use App\Models\Ticket;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Read endpoint for a ticket.
 *
 * GET /api/v1/tickets/{ticket}
 *
 * The same two independent defences as BetController: the query is scoped to the
 * authenticated user's `user_id`, and the existing Phase 1 TicketPolicy is then consulted.
 * Route model binding is not used, so no code path here can load a ticket without the
 * ownership condition. A ticket belonging to another player is reported as 404, identically
 * to one that does not exist, so the endpoint cannot be used to enumerate ticket ids.
 *
 * NESTED BETS ARE SCOPED TOO
 * The ticket's bets are eager loaded with an explicit `user_id` constraint on the relation.
 * A ticket in this schema can hold many bets (bets.ticket_id is the foreign key), so if a
 * ticket were ever shared across users, an unscoped relation load would leak another
 * player's bet through a ticket the caller legitimately owns. The constraint makes that
 * impossible regardless of how the data is shaped later.
 *
 * No write of any kind, and no money arithmetic: amounts are the stored decimal strings.
 */
final class TicketController
{
    /**
     * List the authenticated player's own tickets, paginated.
     *
     * The query is scoped to the caller's user_id before anything else, so the endpoint
     * can never surface another player's ticket receipt.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->notFound();
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $tickets = Ticket::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->with(['bets'])
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::success(
            data: [
                'items' => TicketResource::collection($tickets->items()),
                'pagination' => [
                    'current_page' => $tickets->currentPage(),
                    'last_page' => $tickets->lastPage(),
                    'per_page' => $tickets->perPage(),
                    'total' => $tickets->total(),
                ],
            ],
            message: 'Tickets retrieved successfully.',
        );
    }

    public function show(Request $request, string $ticket): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->notFound();
        }

        $query = Ticket::query()->where('user_id', $user->getAuthIdentifier());

        if (ctype_digit($ticket)) {
            $query->whereKey((int) $ticket);
        } else {
            $query->where('uuid', $ticket);
        }

        try {
            $model = $query->firstOrFail();
        } catch (ModelNotFoundException) {
            return $this->notFound();
        }

        try {
            Gate::forUser($user)->authorize('view', $model);
        } catch (AuthorizationException) {
            return $this->notFound();
        }

        $model->load([
            'bets' => function ($relation) use ($user): void {
                $relation->where('user_id', $user->getAuthIdentifier());
            },
            'bets.items',
        ]);

        return ApiResponse::success(
            (new TicketResource($model))->toArray($request),
            'Ticket retrieved.',
        );
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error(
            'resource_not_found',
            'The requested resource was not found.',
            404,
        );
    }
}
