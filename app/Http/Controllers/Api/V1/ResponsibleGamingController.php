<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Exceptions\RealityCheckException;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Http\Responses\ApiResponse;
use App\Models\RealityCheck;
use App\Models\ResponsibleGamingLimit;
use App\Models\ResponsibleGamingLimitVersion;
use App\Models\User;
use App\Services\ResponsibleGaming\RealityCheckService;
use App\Services\ResponsibleGaming\ResponsibleGamingLimitService;
use App\Services\ResponsibleGaming\SelfExclusionService;
use App\Services\Security\ResponsibleGamingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Player API Controller for Responsible Gaming Limits and Self-Exclusion.
 */
final class ResponsibleGamingController
{
    public function __construct(
        private readonly ResponsibleGamingService $rgService,
        private readonly SelfExclusionService $selfExclusions,
        private readonly ResponsibleGamingLimitService $limitVersions,
        private readonly RealityCheckService $realityChecks,
    ) {}

    /**
     * Get player's current responsible gaming limits and self-exclusion status.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $limits = ResponsibleGamingLimit::query()->where('user_id', $user->id)->first();

        return ApiResponse::success(
            data: [
                'limits' => [
                    'daily_deposit_limit' => $limits?->daily_deposit_limit,
                    'single_bet_limit' => $limits?->single_bet_limit,
                    'daily_wagering_limit' => $limits?->daily_wagering_limit,
                    'is_self_excluded' => $user->isSelfExcluded() || $this->selfExclusions->hasActiveExclusion((int) $user->id),
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

    /**
     * VERSIONED LIMITS (batch-14): pronounce a limit version with
     * bcmath-exact comparison and stricter-precedence. Server derives
     * the user identity from the authenticity token — never the body.
     */
    public function pronounceLimit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit_type' => ['required', 'string', 'in:daily_deposit,weekly_deposit,monthly_deposit,daily_loss,weekly_loss,monthly_loss,stake_limit,single_bet,daily_wagering'],
            'amount' => ['required', 'string', 'regex:/^\\d{1,12}(\\.\\d{1,2})?$/'],
            'currency' => ['nullable', 'string', 'size:3'],
            'effective_to' => ['nullable', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->limitVersions->pronounce(ResponsibleGamingLimitData::fromInput([
                'user_id' => (int) $user->id,
                'limit_type' => $validated['limit_type'],
                'amount' => (string) $validated['amount'],
                'currency' => $validated['currency'] ?? 'THB',
                'effective_to' => isset($validated['effective_to']) ? Carbon::parse($validated['effective_to']) : null,
            ]));
        } catch (ResponsibleGamingLimitException|SelfExclusionException $e) {
            return ApiResponse::error(code: strtolower($e->errorCode()), message: $e->getMessage(), status: 422);
        }

        return ApiResponse::success(data: [
            'limit_key' => $result['version']->limit_key,
            'limit_type' => $result['version']->limit_type->value,
            'amount' => (string) $result['version']->amount,
            'status' => $result['version']->limit_status->value,
            'effective_from' => $result['version']->effective_from?->toIso8601String(),
            'replayed' => $result['replayed'],
        ], message: 'Limit version pronounced.');
    }

    /**
     * SELF-EXCLUSION (batch-14): fail-closed, server-derived identity,
     * deterministic by request fingerprint.
     */
    public function requestSelfExclusion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ends_at' => ['required', 'date', 'after:+23 hours'],
            'reason_code' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_:\\-\\.]{1,64}$/'],
            'scope' => ['nullable', 'string', 'max:32'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $exclusion = $this->selfExclusions->request(SelfExclusionData::fromInput([
                'user_id' => (int) $user->id,
                'ends_at' => Carbon::parse($validated['ends_at']),
                'reason_code' => $validated['reason_code'],
                'scope' => $validated['scope'] ?? 'account',
            ]));
            $exclusion = $this->selfExclusions->activate($exclusion);
        } catch (SelfExclusionException $e) {
            return ApiResponse::error(code: strtolower($e->errorCode()), message: $e->getMessage(), status: 422);
        }

        return ApiResponse::success(data: [
            'request_fingerprint' => $exclusion->request_fingerprint,
            'status' => $exclusion->status->value,
            'activated_at' => $exclusion->activated_at?->toIso8601String(),
            'ends_at' => $exclusion->ends_at->toIso8601String(),
        ], message: 'Self-exclusion is active until the server-authoritative end time. Deposit and betting flows are closed.');
    }

    /**
     * REALITY CHECKS (batch-14): list own checks + acknowledge by
     * session evidence; never another player's rows.
     */
    public function realityChecks(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rows = RealityCheck::query()
            ->where('user_id', $user->id)
            ->orderByDesc('due_at')
            ->limit(25)
            ->get()
            ->map(static fn (RealityCheck $c): array => [
                'reference' => substr((string) $c->delivery_fingerprint, 0, 12),
                'session_reference' => $c->session_reference,
                'status' => $c->status->value,
                'due_at' => $c->due_at->toIso8601String(),
                'delivered_at' => $c->delivered_at?->toIso8601String(),
                'acknowledged_at' => $c->acknowledged_at?->toIso8601String(),
                'expires_at' => $c->expires_at->toIso8601String(),
            ]);

        return ApiResponse::success(data: ['reality_checks' => $rows], message: 'Reality checks retrieved.');
    }

    public function acknowledgeRealityCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'size:12'],
            'session_reference' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $check = RealityCheck::query()
            ->where('user_id', $user->id)
            ->where('delivery_fingerprint', 'like', $validated['reference'].'%')
            ->first();

        if (! $check instanceof RealityCheck) {
            return ApiResponse::error(code: 'rc_not_found', message: 'No such reality check.', status: 404);
        }

        try {
            $check = $this->realityChecks->acknowledge($check, $validated['session_reference']);
        } catch (RealityCheckException $e) {
            return ApiResponse::error(code: strtolower($e->errorCode()), message: $e->getMessage(), status: 422);
        }

        return ApiResponse::success(data: [
            'acknowledged_at' => $check->acknowledged_at?->toIso8601String(),
            'status' => $check->status->value,
        ], message: 'Reality check acknowledged.');
    }

    /**
     * PROTECTION STATE (batch-14): the player's own protection surface
     * — server-derived identity, rows of the token-holder alone.
     */
    public function protectionState(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $versions = ResponsibleGamingLimitVersion::query()
            ->where('user_id', $user->id)
            ->where('limit_status', 'active')
            ->orderBy('limit_type')
            ->get()
            ->map(static fn (ResponsibleGamingLimitVersion $v): array => [
                'limit_type' => $v->limit_type->value,
                'amount' => (string) $v->amount,
                'currency' => $v->currency,
                'effective_from' => $v->effective_from?->toIso8601String(),
                'effective_to' => $v->effective_to?->toIso8601String(),
            ]);

        $pending = ResponsibleGamingLimitVersion::query()
            ->where('user_id', $user->id)
            ->where('limit_status', 'pending')
            ->get(['limit_type', 'amount', 'currency', 'effective_from'])
            ->map(static fn (ResponsibleGamingLimitVersion $v): array => [
                'limit_type' => $v->limit_type->value,
                'amount' => (string) $v->amount,
                'binding_from' => $v->effective_from?->toIso8601String(),
            ]);

        return ApiResponse::success(data: [
            'is_self_excluded' => $this->selfExclusions->hasActiveExclusion((int) $user->id),
            'active_exclusion' => $this->selfExclusions->currentActiveFor((int) $user->id)?->only(['status', 'scope', 'ends_at']),
            'binding_ceilings' => $versions,
            'pending_increases' => $pending,
        ], message: 'Current protection state.');
    }
}
