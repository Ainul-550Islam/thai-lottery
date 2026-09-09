<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\BetResource;
use App\Http\Responses\ApiResponse;
use App\Models\Bet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BetController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->notFound();
        }

        $perPage = (int) $request->query('per_page', 15);

        $paginator = Bet::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->with('items')
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::success([
            'items' => $paginator->items(),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Bets retrieved.');
    }

    public function show(Request $request, string $bet): JsonResponse
    {
        $model = $this->resolve($request, $bet);

        if (! $model instanceof Bet) {
            return $this->notFound();
        }

        $model->load('items');

        return ApiResponse::success(
            (new BetResource($model))->toArray($request),
            'Bet retrieved.',
        );
    }

    public function status(Request $request, string $bet): JsonResponse
    {
        $model = $this->resolve($request, $bet);

        if (! $model instanceof Bet) {
            return $this->notFound();
        }

        return ApiResponse::success([
            'id' => (int) $model->getKey(),
            'uuid' => $model->uuid,
            'bet_number' => (string) $model->bet_number,
            'status' => $model->status instanceof \BackedEnum ? $model->status->value : (string) $model->status,
            'draw_id' => (int) $model->draw_id,
            'placed_at' => $model->placed_at?->toIso8601String(),
        ], 'Bet status retrieved.');
    }

    private function resolve(Request $request, string $identifier): ?Bet
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $query = Bet::query()->where('user_id', $user->getAuthIdentifier());

        if (ctype_digit($identifier)) {
            $query->whereKey((int) $identifier);
        } else {
            $query->where('uuid', $identifier);
        }

        try {
            $bet = $query->firstOrFail();
        } catch (ModelNotFoundException) {
            return null;
        }

        try {
            Gate::forUser($user)->authorize('view', $bet);
        } catch (AuthorizationException) {
            return null;
        }

        return $bet;
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
