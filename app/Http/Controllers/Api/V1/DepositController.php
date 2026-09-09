<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Http\Responses\ApiResponse;
use App\Models\Deposit;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Payment\PaymentInitiationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player API Controller for initiating and checking deposits.
 */
final class DepositController
{
    public function __construct(
        private readonly PaymentInitiationService $initiationService,
    ) {
    }

    /**
     * List payment methods available for deposits.
     */
    public function methods(): JsonResponse
    {
        $methods = array_map(fn (PaymentMethod $m): array => [
            'id' => $m->value,
            'name' => $m->name,
            'label' => $m->label(),
        ], PaymentMethod::cases());

        return ApiResponse::success(
            data: [
                'methods' => $methods,
                'default_currency' => 'THB',
                'currencies' => ['THB', 'USD', 'BDT'],
            ],
            message: 'Payment methods retrieved.',
        );
    }

    /**
     * List authenticated player's deposits.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $deposits = Deposit::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate((int) $request->query('per_page', 15));

        return ApiResponse::success(
            data: [
                'items' => collect($deposits->items())->map(fn (Deposit $d): array => [
                    'id' => $d->id,
                    'uuid' => $d->uuid,
                    'reference_number' => $d->reference_number,
                    'status' => $d->status->value,
                    'amount' => (string) $d->amount,
                    'fee' => (string) $d->fee,
                    'net_amount' => (string) $d->net_amount,
                    'currency' => $d->currency->value,
                    'method' => $d->method->value,
                    'confirmed_at' => $d->confirmed_at?->toIso8601String(),
                    'created_at' => $d->created_at?->toIso8601String(),
                ]),
                'pagination' => [
                    'current_page' => $deposits->currentPage(),
                    'last_page' => $deposits->lastPage(),
                    'per_page' => $deposits->perPage(),
                    'total' => $deposits->total(),
                ],
            ],
            message: 'Deposits retrieved successfully.',
        );
    }

    /**
     * Initiate a new deposit request.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'string', 'regex:/^\d+(\.\d{1,2})?$/'],
            'method' => ['required', 'string', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'currency' => ['nullable', 'string', 'in:THB,USD,BDT'],
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

        $result = $this->initiationService->initiateDeposit(
            wallet: $wallet,
            amount: $amount,
            method: $method,
            idempotencyKey: $idempotencyKey,
            options: ['ip' => $request->ip()],
        );

        return ApiResponse::success(
            data: [
                'deposit' => [
                    'id' => $result['deposit']->id,
                    'uuid' => $result['deposit']->uuid,
                    'reference_number' => $result['deposit']->reference_number,
                    'status' => $result['deposit']->status->value,
                    'amount' => (string) $result['deposit']->amount,
                    'fee' => (string) $result['deposit']->fee,
                    'net_amount' => (string) $result['deposit']->net_amount,
                    'currency' => $result['deposit']->currency->value,
                    'method' => $result['deposit']->method->value,
                ],
                'payment' => [
                    'reference_number' => $result['payment']->reference_number,
                    'status' => $result['payment']->status->value,
                ],
                'checkout' => $result['gateway_response']->toArray(),
            ],
            message: 'Deposit initiated successfully.',
            status: 201,
        );
    }

    /**
     * View deposit status by ID or reference number.
     */
    public function show(string $depositId, Request $request): JsonResponse
    {
        $user = $request->user();

        $deposit = Deposit::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($depositId): void {
                $query->where('reference_number', $depositId)
                    ->orWhere('uuid', $depositId);

                if (is_numeric($depositId)) {
                    $query->orWhere('id', (int) $depositId);
                }
            })
            ->first();

        if (! $deposit instanceof Deposit) {
            return ApiResponse::error(
                code: 'deposit_not_found',
                message: 'Deposit not found.',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: [
                'id' => $deposit->id,
                'uuid' => $deposit->uuid,
                'reference_number' => $deposit->reference_number,
                'status' => $deposit->status->value,
                'amount' => (string) $deposit->amount,
                'fee' => (string) $deposit->fee,
                'net_amount' => (string) $deposit->net_amount,
                'currency' => $deposit->currency->value,
                'method' => $deposit->method->value,
                'confirmed_at' => $deposit->confirmed_at?->toIso8601String(),
                'failed_at' => $deposit->failed_at?->toIso8601String(),
                'failure_reason' => $deposit->failure_reason,
            ],
            message: 'Deposit retrieved successfully.',
        );
    }
}
