<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WalletHoldType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\WithdrawalException;
use App\Exceptions\WithdrawalKycException;
use App\Http\Requests\Withdrawal\CancelWithdrawalRequest;
use App\Http\Requests\Withdrawal\CreateWithdrawalRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WithdrawalService;
use App\Services\Withdrawal\WithdrawalKycGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player API Controller for initiating, checking, and retracting
 * withdrawals.
 *
 * ARCHITECTURE-LEVEL BOUNDARIES THIS CONTROLLER HONOURS
 * - All arithmetic stays inside WithdrawalService / WalletHoldService /
 *   CarriedForwardMoneyService; this file never adds, subtracts or reserves
 *   a single cent itself.
 * - The KYC gate is INVOKED here exactly once per creation — never
 *   duplicated inline, never skipped when the surface demands it. The gate
 *   is the component that decides when creation detaches into a detention,
 *   not this controller.
 * - Idempotency: the body field OR the X-Idempotency-Key header (body field
 *   wins on disagreement) flows unchanged to the service's existing
 *   idempotency mechanics — this file adds no second mechanism of its own.
 *
 * Response-shape contract preserved from the first-cut controller: every
 * success body keeps `data` as a flat map and `items`/`pagination` on index.
 */
final class WithdrawalController
{
    public function __construct(
        private readonly WithdrawalService $withdrawalService,
        private readonly WalletHoldService $holdService,
        private readonly WithdrawalKycGateService $kycGate,
    ) {
    }

    /**
     * Request a new player withdrawal.
     *
     * Validated by CreateWithdrawalRequest (canonical amount string, closed
     * method vocabulary, destination details scrubbed of markup, and an
     * explicit prohibition on client-asserted balance/KYC facts). After the
     * service records the request and the hold is reserved, the existing KYC
     * gate decides whether this withdrawal stands at Pending or moves
     * directly into the gate's detention state — the response reflects the
     * TRUE resulting status either way.
     */
    public function store(CreateWithdrawalRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = $request->user();
        $currency = isset($validated['currency']) && is_string($validated['currency'])
            ? Currency::from(strtoupper($validated['currency']))
            : Currency::THB;

        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', $currency->value)
            ->first();

        if (! $wallet instanceof Wallet) {
            return ApiResponse::error(
                code: 'wallet_not_found',
                message: sprintf('No wallet found for currency %s.', $currency->value),
                status: 404,
            );
        }

        $method = PaymentMethod::from($validated['method']);
        $amount = Money::of($request->amount(), $currency);
        $idempotencyKey = is_string($validated['idempotency_key'] ?? null)
            ? $validated['idempotency_key']
            : $request->header('X-Idempotency-Key');

        try {
            $withdrawal = $this->withdrawalService->request(
                wallet: $wallet,
                amount: $amount,
                method: $method,
                idempotencyKey: $idempotencyKey,
                options: [
                    'payout_details' => $request->destination(),
                    'ip' => $request->ip(),
                ],
            );

            // Reserve hold on player wallet
            $this->holdService->hold($wallet, $amount, WalletHoldType::Withdrawal, [
                'withdrawal_id' => $withdrawal->id,
            ]);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return ApiResponse::error(
                code: 'insufficient_balance',
                message: 'Available balance is insufficient to request this withdrawal amount.',
                status: 422,
            );
        } catch (WithdrawalException $e) {
            return ApiResponse::error(
                code: 'withdrawal_refused',
                message: $e->getMessage(),
                status: 422,
            );
        }

        // INVOCATION OF THE EXISTING KYC GATE — never duplicated inline.
        // Above the configured threshold the gate decides whether the
        // player's current identity evidence suffices. It never blocks the
        // CREATION itself (the money is already safely reserved); it only
        // decides which state the new withdrawal stands at.
        $gateRefusal = null;

        if ($this->kycGate->requiresGate($withdrawal)) {
            try {
                $this->kycGate->gate($withdrawal);
            } catch (WithdrawalKycException $e) {
                // Detained: the withdrawal now stands at KycRequired. This
                // is a documented outcome of creation, not an error — the
                // response below surfaces the actual status.
                $gateRefusal = $e;
            }
        }

        $withdrawal = $withdrawal->fresh() ?? $withdrawal;

        return ApiResponse::success(
            data: [
                'withdrawal' => [
                    'id' => $withdrawal->id,
                    'uuid' => $withdrawal->uuid,
                    'reference_number' => $withdrawal->reference_number,
                    'status' => $withdrawal->status->value,
                    'amount' => (string) $withdrawal->amount,
                    'fee' => (string) $withdrawal->fee,
                    'net_amount' => (string) $withdrawal->net_amount,
                    'currency' => $withdrawal->currency->value,
                    'method' => $withdrawal->method->value,
                    'requested_at' => $withdrawal->requested_at?->toIso8601String(),
                ],
                'kyc_detained' => $gateRefusal !== null,
                'kyc_detention_reason' => $gateRefusal !== null ? $gateRefusal->getMessage() : null,
            ],
            message: $gateRefusal !== null
                ? 'Withdrawal created and held pending identity verification.'
                : 'Withdrawal requested successfully.',
            status: 201,
        );
    }

    /**
     * View withdrawal status by ID or reference number. Owner-scoped
     * lookup only: a foreign principal's identifier is indistinguishable
     * from a nonexistent one, deliberately (404 == same answer for "not
     * found" or "not yours").
     */
    public function show(string $withdrawal, Request $request): JsonResponse
    {
        $user = $request->user();

        $row = Withdrawal::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($withdrawal): void {
                $query->where('reference_number', $withdrawal)
                    ->orWhere('uuid', $withdrawal);

                if (is_numeric($withdrawal)) {
                    $query->orWhere('id', (int) $withdrawal);
                }
            })
            ->first();

        if (! $row instanceof Withdrawal) {
            return ApiResponse::error(
                code: 'withdrawal_not_found',
                message: 'Withdrawal not found.',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: [
                'id' => $row->id,
                'uuid' => $row->uuid,
                'reference_number' => $row->reference_number,
                'status' => $row->status->value,
                'amount' => (string) $row->amount,
                'fee' => (string) $row->fee,
                'net_amount' => (string) $row->net_amount,
                'currency' => $row->currency->value,
                'method' => $row->method->value,
                'requested_at' => $row->requested_at?->toIso8601String(),
                'approved_at' => $row->approved_at?->toIso8601String(),
                'completed_at' => $row->completed_at?->toIso8601String(),
                'rejected_at' => $row->rejected_at?->toIso8601String(),
                'rejection_reason' => $row->rejection_reason,
            ],
            message: 'Withdrawal retrieved successfully.',
        );
    }

    /**
     * List authenticated player's withdrawal history.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $withdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate((int) $request->query('per_page', 15));

        return ApiResponse::success(
            data: [
                'items' => collect($withdrawals->items())->map(fn (Withdrawal $w): array => [
                    'id' => $w->id,
                    'uuid' => $w->uuid,
                    'reference_number' => $w->reference_number,
                    'status' => $w->status->value,
                    'amount' => (string) $w->amount,
                    'fee' => (string) $w->fee,
                    'net_amount' => (string) $w->net_amount,
                    'currency' => $w->currency->value,
                    'method' => $w->method->value,
                    'requested_at' => $w->requested_at?->toIso8601String(),
                    'completed_at' => $w->completed_at?->toIso8601String(),
                ]),
                'pagination' => [
                    'current_page' => $withdrawals->currentPage(),
                    'last_page' => $withdrawals->lastPage(),
                    'per_page' => $withdrawals->perPage(),
                    'total' => $withdrawals->total(),
                ],
            ],
            message: 'Withdrawals retrieved successfully.',
        );
    }

    /**
     * Retract a withdrawal whose reservation is not yet in the operator's
     * hands.
     *
     * The CancelWithdrawalRequest owns shape validation; the owner guard and
     * the current-state guard are re-derived here atomically under the
     * service's own lock so a cancellation can never race the approval lane
     * into a half-transition.
     */
    public function cancel(CancelWithdrawalRequest $request): JsonResponse
    {
        $user = $request->user();
        $identifier = $request->identifier();

        $withdrawal = Withdrawal::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($identifier): void {
                $query->where('reference_number', $identifier)
                    ->orWhere('uuid', $identifier);

                if (is_numeric($identifier)) {
                    $query->orWhere('id', (int) $identifier);
                }
            })
            ->first();

        if (! $withdrawal instanceof Withdrawal) {
            return ApiResponse::error(
                code: 'withdrawal_not_found',
                message: 'Withdrawal not found.',
                status: 404,
            );
        }

        try {
            $result = $this->withdrawalService->cancel(
                $withdrawal,
                $request->reason(),
                (int) $user->id,
            );

            $cancelled = $result['withdrawal'] instanceof Withdrawal ? $result['withdrawal'] : $withdrawal->fresh() ?? $withdrawal;
        } catch (WithdrawalException $e) {
            return ApiResponse::error(
                code: 'withdrawal_cancellation_refused',
                message: $e->getMessage(),
                status: 422,
            );
        }

        return ApiResponse::success(
            data: [
                'id' => $cancelled->id,
                'uuid' => $cancelled->uuid,
                'reference_number' => $cancelled->reference_number,
                'status' => $cancelled->status->value,
                'amount' => (string) $cancelled->amount,
                'currency' => $cancelled->currency->value,
            ],
            message: 'Withdrawal cancelled; the reserved funds have been released.',
        );
    }
}
