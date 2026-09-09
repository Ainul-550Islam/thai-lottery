<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FinancialTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a financial transaction.
 *
 * @mixin FinancialTransaction
 */
final class FinancialTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var FinancialTransaction $tx */
        $tx = $this->resource;

        return [
            'id' => (int) $tx->getKey(),
            'reference_number' => (string) $tx->reference_number,
            'type' => $tx->type?->value,
            'status' => $tx->status?->value,
            'currency' => $tx->currency?->value,
            'amount' => (string) $tx->amount,
            'fee' => (string) $tx->fee,
            'net_amount' => (string) $tx->net_amount,
            'description' => $tx->description,
            'processed_at' => $tx->processed_at?->toIso8601String(),
            'created_at' => $tx->created_at?->toIso8601String(),
        ];
    }
}
