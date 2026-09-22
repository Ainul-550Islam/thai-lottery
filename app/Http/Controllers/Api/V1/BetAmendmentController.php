<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Betting\BetAmendmentData;
use App\Exceptions\BetDomainException;
use App\Exceptions\BetPurchaseException;
use App\Http\Requests\Api\V1\AmendBetRequest;
use App\Http\Resources\BetAmendmentResource;
use App\Http\Responses\ApiResponse;
use App\Services\Betting\BetAmendmentService;
use Illuminate\Http\JsonResponse;

/**
 * Bet amendment endpoint (refund-and-replace).
 *
 * Transport-only. The client_key is part of the validated payload and is
 * required by the request object: it is the only thing standing between a
 * retried amendment and a double replacement purchase, so this controller
 * would rather reject an ambiguous call than guess.
 */
class BetAmendmentController
{
    public function __construct(private readonly BetAmendmentService $amendments)
    {
    }

    /**
     * POST /api/v1/bets/{bet}/amend
     */
    public function store(AmendBetRequest $request, string $bet): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        try {
            $result = $this->amendments->amend(BetAmendmentData::fromRequestArray(
                (int) $user->getAuthIdentifier(),
                $bet,
                $request->validated(),
            ));
        } catch (BetDomainException $e) {
            return ApiResponse::error($e->codeKey(), $e->getMessage(), 422);
        } catch (BetPurchaseException $e) {
            return ApiResponse::error($e->getErrorCode(), $e->getMessage(), 422);
        }

        return ApiResponse::success(
            (new BetAmendmentResource($result))->toArray($request),
            $result->isApplied()
                ? 'Bet amended: original refunded and replacement placed.'
                : 'Amendment failed after refund; stake returned to wallet.',
        );
    }
}
