<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a deposit.
 *
 * @mixin Deposit
 */
final class DepositResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Deposit $deposit */
        $deposit = $this->resource;

        return [
            'id' => (int) $deposit->getKey(),
            'uuid' => $deposit->uuid,
            'reference_number' => (string) $deposit->reference_number,
            'status' => $deposit->status?->value,
            'amount' => (string) $deposit->amount,
            'fee' => (string) $deposit->fee,
            'net_amount' => (string) $deposit->net_amount,
            'currency' => $deposit->currency?->value,
            'method' => $deposit->method?->value,
            'confirmed_at' => $deposit->confirmed_at?->toIso8601String(),
            'failed_at' => $deposit->failed_at?->toIso8601String(),
            'failure_reason' => $deposit->failure_reason,
            'created_at' => $deposit->created_at?->toIso8601String(),
        ];
    }
}
