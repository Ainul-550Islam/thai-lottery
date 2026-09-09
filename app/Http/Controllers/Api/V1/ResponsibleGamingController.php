<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Models\ResponsibleGamingLimit;
use App\Models\User;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Player API Controller for Responsible Gaming Limits and Self-Exclusion.
 */
final class ResponsibleGamingController
{
    public function __construct(
        private readonly ResponsibleGamingService $rgService,
    ) {
    }

    /**
     * Get player's current responsible gaming limits and self-exclusion status.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $limits = $user->responsibleGamingLimits ?? ResponsibleGamingLimit::query()->where('user_id', $user->id)->first();

        return ApiResponse::success(
            data: [
                'limits' => [
                    'daily_deposit_limit' => $limits?->daily_deposit_limit,
                    'single_bet_limit' => $limits?->single_bet_limit,
                    'daily_wagering_limit' => $limits?->daily_wagering_limit,
                    'is_self_excluded' => $user->isSelfExcluded(),
                    'self_excluded_until' => $limits?->self_excluded_until?->toIso8601String(),
                    'self_exclusion_reason' => $limits?->self_exclusion_reason,
                ],
            ],
            message: 'Responsible gaming settings retrieved successfully.',
        );
    }

    /**
     * Set or update responsible gaming limits.
     */
    public function updateLimits(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'daily_deposit_limit' => ['nullable', 'numeric', 'min:0'],
            'single_bet_limit' => ['nullable', 'numeric', 'min:0'],
            'daily_wagering_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $record = $this->rgService->setLimits(
            user: $user,
            dailyDepositLimit: isset($validated['daily_deposit_limit']) ? (string) $validated['daily_deposit_limit'] : null,
            singleBetLimit: isset($validated['single_bet_limit']) ? (string) $validated['single_bet_limit'] : null,
            dailyWageringLimit: isset($validated['daily_wagering_limit']) ? (string) $validated['daily_wagering_limit'] : null,
        );

        return ApiResponse::success(
            data: [
                'daily_deposit_limit' => $record->daily_deposit_limit,
                'single_bet_limit' => $record->single_bet_limit,
                'daily_wagering_limit' => $record->daily_wagering_limit,
            ],
            message: 'Responsible gaming limits updated successfully.',
        );
    }

    /**
     * Request player self-exclusion for a specified duration.
     */
    public function selfExclude(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $record = $this->rgService->selfExclude(
                user: $user,
                days: (int) $validated['days'],
                reason: $validated['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error(
                code: 'self_exclusion_failed',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: [
                'is_self_excluded' => true,
                'self_excluded_until' => $record->self_excluded_until?->toIso8601String(),
                'reason' => $record->self_exclusion_reason,
            ],
            message: 'Self-exclusion has been activated. You cannot place bets or deposit funds during this period.',
        );
    }
}
