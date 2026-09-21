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

/**
 * Read endpoints for a bet.
 *
 * GET /api/v1/bets/{bet}
 * GET /api/v1/bets/{bet}/status
 *
 * TWO INDEPENDENT DEFENCES AGAINST IDOR
 * 1. The query itself is scoped: `where('user_id', $user->id)`. A bet belonging to another
 *    player is not merely rejected, it is not selected in the first place, so no branch
 *    downstream can accidentally serialise it.
 * 2. The existing Phase 1 BetPolicy is then consulted through Gate::authorize('view'). It
 *    passes for the owner via BasePolicy::owns() and for a staff member holding the
 *    `bet.view` permission.
 *
 * Route model binding is deliberately NOT used. Implicit binding resolves the model before
 * the controller runs, from the route parameter alone, and the scoping then has to be
 * remembered afterwards. Resolving explicitly inside a user-scoped query makes the
 * ownership condition impossible to forget - there is no code path here that can load a
 * bet without it.
 *
 * A FOREIGN BET IS REPORTED AS 404, NOT 403
 * Answering 403 for "exists but belongs to someone else" and 404 for "does not exist" would
 * turn this endpoint into an existence oracle: an attacker could enumerate valid bet ids
 * without ever reading one. Both cases return the same 404.
 *
 * NO WRITES ANYWHERE
 * These are strictly read endpoints. No wallet, ledger, bet, item, ticket or risk row is
 * created, updated or deleted, and no money arithmetic is performed - amounts are the
 * stored decimal strings, forwarded by the resource.
 */
final class BetController
{
    /**
     * List the authenticated player's own bets, paginated.
     *
     * The query is scoped to the caller's user_id before anything else, so the endpoint
     * can never surface another player's wager. Pagination shape matches the draw list:
     * `data.items` plus `data.pagination`.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return $this->notFound();
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $bets = Bet::query()
            ->where('user_id', $user->getAuthIdentifier())
            ->with(['draw', 'items'])
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::success(
            data: [
                'items' => BetResource::collection($bets->items()),
                'pagination' => [
                    'current_page' => $bets->currentPage(),
                    'last_page' => $bets->lastPage(),
                    'per_page' => $bets->perPage(),
                    'total' => $bets->total(),
                ],
            ],
            message: 'Bets retrieved successfully.',
        );
    }

    public function show(Request $request, string $bet): JsonResponse
    {
        $model = $this->resolve($request, $bet);

        if (! $model instanceof Bet) {
            return $this->notFound();
        }

        // Eager loaded explicitly: AppServiceProvider enables
        // Model::preventLazyLoading outside production, so a resource that triggered a
        // lazy load would throw rather than quietly N+1.
        $model->load('items');

        return ApiResponse::success(
            (new BetResource($model))->toArray($request),
            'Bet retrieved.',
        );
    }

    /**
     * A deliberately minimal projection for polling.
     *
     * A client that is waiting for a bet to move from `active` to a settled state should
     * not have to download the whole bet, with every item, on every poll. This returns only
     * what changes.
     */
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
            'status' => $model->status?->value,
            'draw_id' => (int) $model->draw_id,
            'placed_at' => $model->placed_at?->toIso8601String(),
        ], 'Bet status retrieved.');
    }

    /**
     * Resolve a bet the authenticated user is allowed to read, or null.
     *
     * Accepts either the numeric id or the uuid. The two are distinguished by shape rather
     * than by a query parameter, so a client can use whichever identifier it holds. A
     * purely numeric value is looked up by primary key; anything else is looked up by uuid,
     * as an exact string comparison - never a LIKE, never a partial match.
     */
    private function resolve(Request $request, string $identifier): ?Bet
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        $query = Bet::query()->where('user_id', $user->getAuthIdentifier());

        if (ctype_digit($identifier)) {
            // An identifier cast, not a financial one. Bounded by the digit check above.
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
