<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a withdrawal.
 *
 * @mixin Withdrawal
 */
final class WithdrawalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Withdrawal $w */
        $w = $this->resource;

        return [
            'id' => (int) $w->getKey(),
            'uuid' => $w->uuid,
            'reference_number' => (string) $w->reference_number,
            'status' => $w->status?->value,
            'amount' => (string) $w->amount,
            'fee' => (string) $w->fee,
            'net_amount' => (string) $w->net_amount,
            'currency' => $w->currency?->value,
            'method' => $w->method?->value,
            'provider' => $w->provider,
            'requested_at' => $w->requested_at?->toIso8601String(),
            'approved_at' => $w->approved_at?->toIso8601String(),
            'completed_at' => $w->completed_at?->toIso8601String(),
            'rejected_at' => $w->rejected_at?->toIso8601String(),
            'rejection_reason' => $w->rejection_reason,
            'created_at' => $w->created_at?->toIso8601String(),
        ];
    }
}
