<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Http\Resources\FinancialTransactionResource;
use App\Http\Resources\WalletResource;
use App\Http\Responses\ApiResponse;
use App\Models\FinancialTransaction;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Player API Controller for wallet balances, hold tracking, and transaction history.
 */
final class WalletController
{
    /**
     * Retrieve authenticated player's wallet balances and currency status.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $wallets = Wallet::query()
            ->where('user_id', $user->id)
            ->get();

        $primaryWallet = $wallets->firstWhere('type', \App\Enums\WalletType::Primary) ?? $wallets->first();

        if (! $primaryWallet instanceof Wallet) {
            return ApiResponse::error(
                code: 'wallet_not_found',
                message: 'No wallet found for your account. Please contact support.',
                status: 404,
            );
        }

        return ApiResponse::success(
            data: [
                'wallet' => (new WalletResource($primaryWallet))->toArray($request),
                'wallets' => WalletResource::collection($wallets),
            ],
            message: 'Wallet balance retrieved successfully.',
        );
    }

    /**
     * Retrieve authenticated player's paginated financial transaction history.
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = FinancialTransaction::query()
            ->where('user_id', $user->id);

        // Filter by transaction type
        if ($request->filled('type')) {
            $type = TransactionType::tryFrom((string) $request->query('type'));
            if ($type !== null) {
                $query->where('type', $type);
            }
        }

        // Filter by transaction status
        if ($request->filled('status')) {
            $status = TransactionStatus::tryFrom((string) $request->query('status'));
            if ($status !== null) {
                $query->where('status', $status);
            }
        }

        // Filter by currency
        if ($request->filled('currency')) {
            $currency = Currency::tryFrom(strtoupper((string) $request->query('currency')));
            if ($currency !== null) {
                $query->where('currency', $currency);
            }
        }

        // Date range filters
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', (string) $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', (string) $request->query('to'));
        }

        $perPage = min(max((int) $request->query('per_page', 15), 1), 50);

        $transactions = $query
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::success(
            data: [
                'items' => FinancialTransactionResource::collection($transactions->items()),
                'pagination' => [
                    'current_page' => $transactions->currentPage(),
                    'last_page' => $transactions->lastPage(),
                    'per_page' => $transactions->perPage(),
                    'total' => $transactions->total(),
                ],
            ],
            message: 'Transactions retrieved successfully.',
        );
    }
}
