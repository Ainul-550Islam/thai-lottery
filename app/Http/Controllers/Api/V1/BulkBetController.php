<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Betting\BulkBetSelectionData;
use App\DTOs\Betting\PermutationRequestData;
use App\Exceptions\BetDomainException;
use App\Exceptions\BetPurchaseException;
use App\Http\Requests\Api\V1\BulkBetRequest;
use App\Http\Requests\Api\V1\PermutationRequest;
use App\Http\Resources\BulkBetResource;
use App\Http\Responses\ApiResponse;
use App\Services\Betting\BetPermutationService;
use App\Services\Betting\BulkBetService;
use Illuminate\Http\JsonResponse;

/**
 * Slip-based bulk purchase and permutation ("tod"/กลับเลข) endpoints.
 *
 * Transport-only: aggregation rules (cap, per-item isolation, replay
 * semantics) live in BulkBetService; expansion rules live in
 * BetPermutationService. A slip-level 500 is reserved for infrastructure
 * faults; item-level refusals always arrive inside the report payload.
 */
class BulkBetController
{
    public function __construct(
        private readonly BulkBetService $bulk,
        private readonly BetPermutationService $permutations,
    ) {
    }

    /**
     * POST /api/v1/bets/quote
     */
    public function quote(BulkBetRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $quote = $this->bulk->quote((int) $validated['draw_id'], $this->selections($validated['items']));
        } catch (BetPurchaseException $e) {
            return ApiResponse::error($e->getErrorCode(), $e->getMessage(), 422);
        }

        return ApiResponse::success($quote->toArray(), 'Slip quoted.');
    }

    /**
     * POST /api/v1/bets/purchase-bulk
     */
    public function purchase(BulkBetRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $validated = $request->validated();

        try {
            $report = $this->bulk->purchase(
                (int) $user->getAuthIdentifier(),
                (int) $validated['draw_id'],
                $this->selections($validated['items']),
                (string) $validated['client_key'],
            );
        } catch (BetPurchaseException $e) {
            return ApiResponse::error($e->getErrorCode(), $e->getMessage(), 422);
        }

        return ApiResponse::success(
            (new BulkBetResource($report))->toArray($request),
            $this->summaryMessage($report),
        );
    }

    /**
     * POST /api/v1/bets/permutations/preview
     */
    public function previewPermutation(PermutationRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        try {
            $result = $this->permutations->preview(PermutationRequestData::fromRequestArray(
                (int) $user->getAuthIdentifier(),
                $request->validated(),
            ));
        } catch (BetPurchaseException $e) {
            return ApiResponse::error($e->getErrorCode(), $e->getMessage(), 422);
        } catch (BetDomainException $e) {
            return ApiResponse::error($e->codeKey(), $e->getMessage(), 422);
        }

        return ApiResponse::success($result->toArray(), 'Permutation previewed.');
    }

    /**
     * POST /api/v1/bets/permutations/purchase
     */
    public function purchasePermutation(PermutationRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        try {
            $report = $this->permutations->purchase(PermutationRequestData::fromRequestArray(
                (int) $user->getAuthIdentifier(),
                $request->validated(),
            ));
        } catch (BetPurchaseException $e) {
            return ApiResponse::error($e->getErrorCode(), $e->getMessage(), 422);
        } catch (BetDomainException $e) {
            return ApiResponse::error($e->codeKey(), $e->getMessage(), 422);
        }

        return ApiResponse::success(
            (new BulkBetResource($report))->toArray($request),
            $this->summaryMessage($report),
        );
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<BulkBetSelectionData>
     */
    private function selections(array $items): array
    {
        return array_values(array_map(
            static fn (array $item): BulkBetSelectionData => BulkBetSelectionData::fromRequestArray($item),
            $items,
        ));
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function summaryMessage(array $report): string
    {
        if ($report['refused'] === 0) {
            return sprintf(
                'All %d selections placed (%d purchased, %d replayed).',
                $report['requested'],
                $report['purchased'],
                $report['replayed'],
            );
        }

        return sprintf(
            '%d of %d selections placed (%d purchased, %d replayed); %d refused.',
            $report['requested'] - $report['refused'],
            $report['requested'],
            $report['purchased'],
            $report['replayed'],
            $report['refused'],
        );
    }
}
