<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a player wallet.
 *
 * @mixin Wallet
 */
final class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Wallet $wallet */
        $wallet = $this->resource;

        return [
            'id' => (int) $wallet->getKey(),
            'currency' => $wallet->currency?->value,
            'type' => $wallet->type?->value,
            'status' => $wallet->status?->value,
            'balance' => (string) $wallet->balance,
            'locked_balance' => (string) $wallet->locked_balance,
            'available_balance' => (string) $wallet->getAvailableBalance(),
            'can_transact' => $wallet->canTransact(),
            'is_locked' => $wallet->isLocked(),
            'updated_at' => $wallet->updated_at?->toIso8601String(),
        ];
    }
}
