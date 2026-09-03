<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a ticket - the player's receipt.
 *
 * The ticket uuid IS exposed, unlike most internal identifiers, because it is the value a
 * player needs in order to reference their purchase to support, and it is a random v4
 * value rather than a sequential id, so publishing it enables no enumeration. The numeric
 * id is exposed alongside it only because the read endpoints accept either.
 *
 * `user_id` is not exposed, the raw metadata blob is not exposed, and no money is
 * calculated here: `total_amount` is the stored decimal:2 string forwarded verbatim.
 *
 * @mixin Ticket
 */
final class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->resource;

        return [
            'id' => (int) $ticket->getKey(),
            'uuid' => $ticket->uuid,
            'ticket_number' => (string) $ticket->ticket_number,

            'draw_id' => (int) $ticket->draw_id,
            'status' => $ticket->status?->value,

            'currency' => $ticket->currency?->value,
            'total_amount' => (string) $ticket->total_amount,
            'total_bets' => (int) $ticket->total_bets,
            'total_numbers' => (int) $ticket->total_numbers,

            'issued_at' => $ticket->issued_at?->toIso8601String(),
            'confirmed_at' => $ticket->confirmed_at?->toIso8601String(),

            'bets' => BetResource::collection($this->whenLoaded('bets')),
        ];
    }
}
