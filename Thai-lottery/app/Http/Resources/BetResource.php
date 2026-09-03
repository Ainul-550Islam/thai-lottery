<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Bet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a bet.
 *
 * WHAT IS DELIBERATELY ABSENT
 * - `user_id`. The only bet a caller can ever load is their own, so echoing the owner id
 *   adds nothing and would hand back an internal identifier for free.
 * - `payout_id`, `won_at`, `actual_payout` settlement fields beyond the status. Settlement
 *   is a later phase; exposing empty settlement fields now would imply a contract this
 *   phase does not honour.
 * - The raw `metadata` blob. It carries internal risk and derivation context.
 * - `idempotency_key`. It is the DERIVED, scoped key, not the client's own key. Returning
 *   it would leak how keys are constructed; the client already knows the client_key it
 *   sent.
 *
 * NO WRITES, NO ARITHMETIC
 * This class performs no database write and no money arithmetic. Amounts are the model's
 * decimal:2 strings, forwarded exactly as stored. There is no (float), no round() and no
 * number_format anywhere in it.
 *
 * @mixin Bet
 */
final class BetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Bet $bet */
        $bet = $this->resource;

        return [
            'id' => (int) $bet->getKey(),
            'uuid' => $bet->uuid,
            'bet_number' => (string) $bet->bet_number,

            'draw_id' => (int) $bet->draw_id,
            'ticket_id' => $bet->ticket_id === null ? null : (int) $bet->ticket_id,

            'type' => $bet->type?->value,
            'status' => $bet->status?->value,

            'currency' => $bet->currency?->value,
            'stake' => (string) $bet->stake_amount,
            'potential_payout' => (string) $bet->potential_payout,
            'total_numbers' => (int) $bet->total_numbers,

            'placed_at' => $bet->placed_at?->toIso8601String(),
            'created_at' => $bet->created_at?->toIso8601String(),

            // `whenLoaded` is used rather than an unconditional relation read so this
            // resource can never trigger a lazy query while serialising a response.
            'items' => BetItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
