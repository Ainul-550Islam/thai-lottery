<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PayoutApprovalException;
use App\Http\Requests\Payout\CancelPayoutRequest;
use App\Http\Requests\Payout\ShowPayoutRequest;
use App\Http\Resources\PayoutResource;
use App\Http\Responses\ApiResponse;
use App\Models\Payout;
use App\Services\Finance\PayoutApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player/operator-facing HTTP surface for payout obligations.
 *
 * WHAT THIS CONTROLLER OWNS
 * - how a payout is FOUND from its three permitted spellings (id / uuid /
 *   reference number),
 * - who may look at the result (the PayoutPolicy),
 * - how the sanitized representation ships (the PayoutResource),
 * - turning domain exceptions into HTTP-shaped responses.
 *
 * WHAT IT NEVER OWNS
 * No ledger arithmetic, no payment-provider state, no batch arithmetic and
 * no settlement choreography appears here. The route handles admittance to
 * the payout lanes; the lanes handle every financial consequence. A bare
 * controller returning a payout's raw metadata would breach the sanitization
 * contract the PayoutResource exists to enforce, so the resource is used
 * for every success body this controller produces.
 */
final class PayoutController
{
    public function __construct(
        private readonly PayoutApprovalService $approvals,
    ) {
    }

    /**
     * The caller's own payout obligations, most recent first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('viewAny', Payout::class)) {
            abort(403);
        }

        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        $payouts = Payout::query()
            ->where('user_id', (int) $user->getAuthIdentifier())
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::success(
            data: [
                'items' => PayoutResource::collection($payouts->items())->resolve($request),
                'pagination' => [
                    'current_page' => $payouts->currentPage(),
                    'last_page' => $payouts->lastPage(),
                    'per_page' => $payouts->perPage(),
                    'total' => $payouts->total(),
                ],
            ],
            message: 'Payouts retrieved successfully.',
        );
    }

    /**
     * One payout by any of its three identifier spellings, authorized by the
     * policy and returned through the sanitized resource.
     */
    public function show(ShowPayoutRequest $request): JsonResponse
    {
        $payout = $this->findPayout($request->identifier());

        if (! $payout instanceof Payout) {
            return ApiResponse::error(
                code: 'payout_not_found',
                message: 'Payout not found.',
                status: 404,
            );
        }

        $user = $request->user();

        if (! $user->can('view', $payout)) {
            return ApiResponse::error(
                code: 'payout_forbidden',
                message: 'You are not authorized to view this payout.',
                status: 403,
            );
        }

        $payout->loadMissing('draw');

        return ApiResponse::success(
            data: ['payout' => (new PayoutResource($payout))->resolve($request)],
            message: 'Payout retrieved successfully.',
        );
    }

    /**
     * Petition the cancellation of a still-at-rest payout.
     *
     * The state guard + ownership check is the PayoutPolicy's `cancel` rule;
     * the only lasting record this endpoint leaves is an auditable petition
     * stamp on the payout's approval lane — never a status mutation, never
     * a money movement. The operator court reviews the petition out of band.
     */
    public function cancel(CancelPayoutRequest $request): JsonResponse
    {
        $payout = $this->findPayout($request->identifier());

        if (! $payout instanceof Payout) {
            return ApiResponse::error(
                code: 'payout_not_found',
                message: 'Payout not found.',
                status: 404,
            );
        }

        $user = $request->user();

        if (! $user->can('cancel', $payout)) {
            return ApiResponse::error(
                code: 'payout_cancellation_forbidden',
                message: 'This payout can no longer be cancelled through this surface.',
                status: 403,
            );
        }

        try {
            $this->approvals->recordCancellationPetition(
                payout: $payout,
                petitionerUserId: (int) $user->getAuthIdentifier(),
                reason: $request->reason(),
            );
        } catch (PayoutApprovalException $e) {
            return ApiResponse::error(
                code: 'payout_cancellation_refused',
                message: $e->getMessage(),
                status: 422,
                details: ['reason' => $e->errorCode()],
            );
        }

        $payout = $payout->fresh();
        $payout?->loadMissing('draw');

        return ApiResponse::success(
            data: ['payout' => (new PayoutResource($payout))->resolve($request)],
            message: 'Cancellation petition recorded. An operator will adjudicate it.',
        );
    }

    /**
     * Resolve a payout from the identifier's three permitted spellings in a
     * single pass — id first when numeric (cheapest), then uuid and
     * reference number via an OR group so the lookup stays exactly one query
     * for any spelling.
     */
    private function findPayout(string $identifier): ?Payout
    {
        return Payout::query()
            ->where(function ($q) use ($identifier): void {
                if (ctype_digit($identifier)) {
                    $q->orWhere('id', (int) $identifier);
                }
                $q->orWhere('uuid', $identifier)
                    ->orWhere('reference_number', $identifier);
            })
            ->first();
    }
}
