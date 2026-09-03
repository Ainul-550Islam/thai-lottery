<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\BetPurchaseData;
use App\Http\Requests\Api\V1\PurchaseBetRequest;
use App\Http\Resources\BetPurchaseResource;
use App\Http\Responses\ApiResponse;
use App\Http\Support\BetPurchaseAuditRecorder;
use App\Http\Support\BetPurchaseErrorMapper;
use App\Services\Betting\BetPurchaseService;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * POST /api/v1/bets/purchase
 *
 * The thinnest possible bridge between HTTP and the Phase 4.3 purchase engine.
 *
 * WHAT THIS CONTROLLER DOES - THE COMPLETE LIST
 * 1. Reads the authenticated user from the auth context.
 * 2. Reads the already structurally validated request.
 * 3. Builds the Phase 4.3 DTO.
 * 4. Calls BetPurchaseService::purchase().
 * 5. Turns the result, or the exception, into a response.
 * 6. Records the attempt in the audit trail.
 *
 * WHAT IT DOES NOT DO, AND WHY
 * - No wallet read, no wallet lock, no balance check, no debit. The balance that matters
 *   is the one read inside Phase 4.3's row lock; anything this controller read beforehand
 *   would be stale by the time it mattered, and acting on it would be a second, weaker
 *   affordability check competing with the real one.
 * - No ledger write. Phase 2.1 posts the double entry inside the purchase transaction.
 * - No number-limit reservation. Phase 3.1 owns that, under its own row lock.
 * - No payout calculation and no multiplier lookup. Both come from config/lottery.php via
 *   Phase 4.2, inside the purchase. No payout rate is written anywhere in this file.
 * - No permutation logic for 3D Tod. Phase 4.2's TodPermutationService owns it.
 * - No transaction of its own, and no compensation. Opening a transaction here would break
 *   Phase 4.3's invariant that a purchase owns its transaction, and Phase 4.3 asserts
 *   against exactly that.
 * - No Bet::create(), no BetItem::create(), no Ticket::create(), no Payout::create().
 *
 * THE USER IDENTITY IS NOT NEGOTIABLE
 * The user id passed to the DTO comes from $request->user() only. The payload's `user_id`
 * is refused outright by the FormRequest, and even if it were present Phase 4.3's
 * BetPurchaseData takes the user id as a separate constructor argument and never reads it
 * from the payload. Two independent layers, both closed.
 *
 * ONE PURCHASE PER REQUEST - SEE THE REPORT'S CONFLICT SECTION
 * The endpoint accepts an `items` array because that is the requested contract and because
 * it is the right shape for the future. It currently executes exactly one item. Phase 4.3
 * deliberately refuses to run inside a transaction it does not own
 * (BetPurchaseTransactionService::assertNotAlreadyInTransaction), so N selections cannot be
 * wrapped in one atomic transaction without modifying verified Phase 4.3 financial code -
 * which this phase is forbidden from doing silently. Executing them sequentially instead
 * would be worse than refusing: a failure on item 3 would leave items 1 and 2 sold, which
 * is precisely the partial purchase the whole architecture exists to prevent. So a
 * multi-item request is refused BEFORE any mutation, and the refusal is honest about why.
 */
final class BetPurchaseController
{
    public function __construct(
        private readonly BetPurchaseService $purchases,
        private readonly BetPurchaseErrorMapper $errors,
        private readonly BetPurchaseAuditRecorder $audit,
    ) {
    }

    public function store(PurchaseBetRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            // Unreachable behind auth middleware; present so the class is correct on its
            // own terms rather than only in the context of a route definition.
            return ApiResponse::error(
                BetPurchaseErrorMapper::CODE_UNAUTHENTICATED,
                'Authentication is required for this request.',
                401,
            );
        }

        $clientKey = $request->clientKey();
        $drawId = $request->drawId();
        $items = $request->items();

        $requestContext = [
            'draw_id' => $drawId,
            'item_count' => count($items),
            'channel' => 'api',
            'api_version' => 'v1',
        ];

        if (count($items) > 1) {
            return ApiResponse::error(
                BetPurchaseErrorMapper::CODE_MULTI_ITEM_UNSUPPORTED,
                'This endpoint currently accepts one item per purchase. Nothing was charged. '
                .'Send each selection as its own request with its own client_key.',
                422,
                ['item_count' => count($items), 'max_supported' => 1],
            );
        }

        $item = $items[0];

        $data = BetPurchaseData::fromRequestArray((int) $user->getAuthIdentifier(), [
            'draw_id' => $drawId,
            'market' => $item['market'],
            'number' => $item['number'],
            'stake' => $item['stake'],
            // The client's key is handed over as the REQUEST identity. Phase 4.3 then
            // derives the authoritative, scoped database key from user + draw + this value,
            // which is what stops two different users' identical keys from colliding. The
            // client's key is never used directly as the stored key.
            'idempotency_key' => $clientKey,
        ])->withMetadata([
            'channel' => 'api',
            'api_version' => 'v1',
        ]);

        try {
            $result = $this->purchases->purchase($data);
        } catch (Throwable $exception) {
            $mapped = $this->errors->map($exception);

            $this->audit->recordFailure(
                $request,
                $exception,
                $mapped['code'],
                $mapped['status'],
                $clientKey,
                $requestContext,
            );

            return ApiResponse::error(
                $mapped['code'],
                $mapped['message'],
                $mapped['status'],
                $mapped['details'],
            );
        }

        $this->audit->recordSuccess($request, $result, $clientKey, $requestContext);

        return ApiResponse::success(
            (new BetPurchaseResource($result, $clientKey))->toArray($request),
            $result->isReplay()
                ? 'This purchase was already completed and is returned unchanged.'
                : 'Bet purchased.',
            // 200 for a replay, 201 for a purchase that was created by THIS request. A
            // client can therefore tell the two apart from the status line alone, and a
            // retry is never reported as a fresh creation.
            $result->isReplay() ? 200 : 201,
            ['replayed' => $result->isReplay()],
        );
    }
}
