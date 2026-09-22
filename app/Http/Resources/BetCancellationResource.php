<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Betting\BetCancellationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a bet cancellation outcome.
 *
 * WHAT IS DELIBERATELY ABSENT
 * - user_id / wallet_id: the only cancellation a caller can produce is their
 *   own, so owner identifiers would be internal ids echoed back for free.
 * - the raw reason taxonomy labels: the machine code is enough for a client.
 *
 * NO ARITHMETIC
 * Amounts are the model's decimal strings forwarded exactly as stored.
 *
 * @mixin BetCancellationResult
 */
final class BetCancellationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BetCancellationResult $result */
        $result = $this->resource;

        return [
            'bet_id' => (int) $result->bet->getKey(),
            'bet_number' => (string) $result->bet->bet_number,
            'uuid' => $result->bet->uuid,
            'status' => $result->bet->status->value,
            'reason' => $result->reason->value,
            'refund' => [
                'amount' => $result->refundAmount,
                'currency' => $result->currency,
                'transaction_id' => (int) $result->refund->getKey(),
                'reference' => $result->refund->reference_number,
            ],
            'ticket' => $result->ticket === null ? null : [
                'id' => (int) $result->ticket->getKey(),
                'ticket_number' => (string) $result->ticket->ticket_number,
                'status' => $result->ticket->status->value,
            ],
            'cancelled_at' => $result->bet->cancelled_at?->toIso8601String(),
            'replayed' => $result->isReplay(),
        ];
    }
}
