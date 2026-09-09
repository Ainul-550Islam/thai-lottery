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

final class TicketController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->notFound();
        }

        $tickets = Ticket::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->with('bets')
            ->latest('id')
            ->get();

        return ApiResponse::success([
            'items' => $tickets->toArray(),
        ], 'Tickets retrieved.');
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
