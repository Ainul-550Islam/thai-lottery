<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Betting\BetCancellationData;
use App\Exceptions\BetDomainException;
use App\Http\Requests\Api\V1\CancelBetRequest;
use App\Http\Resources\BetCancellationResource;
use App\Http\Responses\ApiResponse;
use App\Services\Betting\BetCancellationService;
use Illuminate\Http\JsonResponse;

/**
 * Player-facing bet cancellation endpoint.
 *
 * Transport-only: the request object has already validated and normalised the
 * payload; this controller passes it through to the service and shapes the
 * envelope. Every gate (ownership, window, draw state, status, money) lives in
 * the service; refusals arrive as BetDomainException and are answered with
 * 422 plus the domain code, never an ad-hoc vocabulary.
 */
class BetCancellationController
{
    public function __construct(private readonly BetCancellationService $cancellations)
    {
    }

    /**
     * POST /api/v1/bets/{bet}/cancel
     */
    public function store(CancelBetRequest $request, string $bet): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        try {
            $result = $this->cancellations->cancel(BetCancellationData::fromRequestArray(
                (int) $user->getAuthIdentifier(),
                $bet,
                $request->validated(),
            ));
        } catch (BetDomainException $e) {
            return ApiResponse::error($e->codeKey(), $e->getMessage(), 422);
        }

        return ApiResponse::success(
            (new BetCancellationResource($result))->toArray($request),
            $result->isReplay() ? 'The bet was already cancelled.' : 'Bet cancelled and stake refunded.',
        );
    }
}
