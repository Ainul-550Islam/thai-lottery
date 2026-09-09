<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WalletHoldType;
use App\Http\Responses\ApiResponse;
use App\Models\Wallet;
use App\Models\Withdrawal;
use App\Services\Finance\Money;
use App\Services\Finance\WalletHoldService;
use App\Services\Finance\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player API Controller for initiating and checking withdrawals.
 */
final class WithdrawalController
{
    public function __construct(
        private readonly WithdrawalService $withdrawalService,
        private readonly WalletHoldService $holdService,
    ) {
    }

    /**
     * Request a new player withdrawal.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'method' => ['required', 'string', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'currency' => ['nullable', 'string', 'in:THB,USD,BDT'],
            'payout_details' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'min:16', 'max:128'],
        ]);

        $user = $request->user();
        $currency = isset($validated['currency'])
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
        $amount = Money::of($validated['amount'], $currency);
        $idempotencyKey = $validated['idempotency_key'] ?? $request->header('X-Idempotency-Key');

        try {
            $withdrawal = $this->withdrawalService->request(
                wallet: $wallet,
                amount: $amount,
                method: $method,
                idempotencyKey: $idempotencyKey,
                options: [
                    'payout_details' => $validated['payout_details'] ?? [],
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
        }

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
            ],
            message: 'Withdrawal requested successfully.',
            status: 201,
        );
    }

    /**
     * View withdrawal status by ID or reference number.
     */
    public function show(string $withdrawalId, Request $request): JsonResponse
    {
        $user = $request->user();

        $withdrawal = Withdrawal::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($withdrawalId): void {
                $query->where('reference_number', $withdrawalId)
                    ->orWhere('uuid', $withdrawalId);

                if (is_numeric($withdrawalId)) {
                    $query->orWhere('id', (int) $withdrawalId);
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

        return ApiResponse::success(
            data: [
                'id' => $withdrawal->id,
                'uuid' => $withdrawal->uuid,
                'reference_number' => $withdrawal->reference_number,
                'status' => $withdrawal->status->value,
                'amount' => (string) $withdrawal->amount,
                'fee' => (string) $withdrawal->fee,
                'net_amount' => (string) $withdrawal->net_amount,
                'currency' => $withdrawal->currency->value,
                'method' => $withdrawal->method->value,
                'provider' => $withdrawal->provider,
                'provider_reference' => $withdrawal->provider_reference,
                'requested_at' => $withdrawal->requested_at?->toIso8601String(),
                'approved_at' => $withdrawal->approved_at?->toIso8601String(),
                'completed_at' => $withdrawal->completed_at?->toIso8601String(),
                'rejected_at' => $withdrawal->rejected_at?->toIso8601String(),
                'rejection_reason' => $withdrawal->rejection_reason,
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
}
