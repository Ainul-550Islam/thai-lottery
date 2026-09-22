<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\PrizeClaimException;
use App\Http\Requests\Prize\CreatePrizeClaimRequest;
use App\Http\Resources\PrizeClaimResource;
use App\Http\Responses\ApiResponse;
use App\Models\Bet;
use App\Models\Payout;
use App\Services\Prize\PrizeClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player claim-endpoint surface.
 *
 * THE DELEGATION RULE THIS CONTROLLER LIVES BY
 * Every single claim rule — eligibility, ownership, window, duplicate
 * suppression, review-threshold forking — lives inside PrizeClaimService
 * (and its window/ownership companions) and is invoked EXACTLY once from
 * here. Nothing about "may this claimant proceed" is duplicated at the HTTP
 * layer: the request class validated shape, this controller validates
 * nothing else of consequence. The controller is responsible for: route
 * param binding, scoping to the authenticated principal, service invocation,
 * and exception-to-HTTP translation.
 *
 * NOT FOUND VS FORBIDDEN
 * A foreign principal's claim/bet is indistinguishable from one that does
 * not exist — the response contract says both are `prize_claim_not_found`,
 * 404, so enumerating other players' obligations by guessing identifiers is
 * worth nothing.
 */
final class PrizeClaimController
{
    public function __construct(
        private readonly PrizeClaimService $claims,
    ) {
    }

    /**
     * Submit a claim for a won bet. Same-claimant replay re-serves the
     * existing claim silently (the service's identity rule guarantees it).
     */
    public function store(CreatePrizeClaimRequest $request): JsonResponse
    {
        $user = $request->user();
        $betId = $request->betId();

        // Two pre-questions the controller is of course allowed to answer:
        //   (1) does the referenced bet exist, and (2) is it THEIRS.
        // Any failure here is a 404 for the reason stated in the file's
        // header; business reasons ("already claimed by a foreign
        // principal") stay the service's exceptions, not the controller's
        // affirmative answers.
        $betIsTheirs = Bet::query()
            ->whereKey($betId)
            ->where('user_id', (int) $user->getAuthIdentifier())
            ->exists();

        if (! $betIsTheirs) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claimable win was found under that reference.',
                status: 404,
            );
        }

        try {
            $result = $this->claims->claim($betId, (int) $user->getAuthIdentifier());
        } catch (PrizeClaimException $e) {
            return ApiResponse::error(
                code: 'prize_claim_refused',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: ['claim' => (new PrizeClaimResource($result))->resolve($request)],
            message: ($result['no_op'] ?? false) === true
                ? 'This claim was already on file; returning the existing claim.'
                : 'Prize claim submitted successfully.',
            status: ($result['no_op'] ?? false) === true ? 200 : 201,
        );
    }

    /**
     * One claim for one of the caller's own bets.
     */
    public function show(string $bet, Request $request): JsonResponse
    {
        $user = $request->user();

        if (! ctype_digit($bet) || (int) $bet < 1) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claimable win was found under that reference.',
                status: 404,
            );
        }

        $ownBet = Bet::query()
            ->whereKey((int) $bet)
            ->where('user_id', (int) $user->getAuthIdentifier())
            ->first();

        if (! $ownBet instanceof Bet) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claimable win was found under that reference.',
                status: 404,
            );
        }

        $payout = $this->payoutForBet($ownBet);

        if (! $payout instanceof Payout) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claimable win was found under that reference.',
                status: 404,
            );
        }

        $claim = $this->claims->claimFor((int) $payout->getKey());

        if (! is_array($claim)) {
            return ApiResponse::error(
                code: 'prize_claim_not_found',
                message: 'No claim has been submitted for this win yet.',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: ['claim' => (new PrizeClaimResource($payout))->resolve($request)],
            message: 'Prize claim retrieved successfully.',
        );
    }

    /**
     * The caller's claim history — payouts that carry a claim lane, newest
     * first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $perPage = max(1, min(50, (int) $request->query('per_page', 15)));

        $payouts = Payout::query()
            ->where('user_id', (int) $user->getAuthIdentifier())
            ->whereNotNull('metadata->claim')
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::success(
            data: [
                'items' => collect($payouts->items())
                    ->map(fn (Payout $payout): array => (new PrizeClaimResource($payout))->resolve($request))
                    ->all(),
                'pagination' => [
                    'current_page' => $payouts->currentPage(),
                    'last_page' => $payouts->lastPage(),
                    'per_page' => $payouts->perPage(),
                    'total' => $payouts->total(),
                ],
            ],
            message: 'Prize claims retrieved successfully.',
        );
    }

    /**
     * The payout obligation for a bet: direct association first (the newer
     * `bets.payout_id` lane), reverse lookup second (the canonical
     * `payouts.bet_id` lane).
     */
    private function payoutForBet(Bet $bet): ?Payout
    {
        if ($bet->payout_id !== null) {
            $byDirectId = Payout::query()->find((int) $bet->payout_id);

            if ($byDirectId instanceof Payout) {
                return $byDirectId;
            }
        }

        return Payout::query()->where('bet_id', (int) $bet->getKey())->first();
    }
}
