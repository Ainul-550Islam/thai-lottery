<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\BetPurchaseResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a completed purchase.
 *
 * This wraps the Phase 4.3 BetPurchaseResult DTO rather than a model, because the DTO is
 * what the purchase engine actually returns and it already carries the exact stake, the
 * exact potential payout and whether the call was a replay. Re-deriving any of that from
 * the models here would risk reporting something other than what was committed.
 *
 * WHAT IS DELIBERATELY WITHHELD FROM THE DTO
 * BetPurchaseResult::toArray() is an OPERATOR view: it includes the financial transaction
 * id, the ledger entry ids, the ledger entry count, the verbatim Phase 3.1 risk
 * reservation and a diagnostic context array. None of that is in this response.
 * - Ledger and transaction ids are accounting internals the player has no use for.
 * - The risk reservation exposes engine posture, exposure levels and limit state, which
 *   would let a client probe the platform's ceilings one purchase at a time.
 * - The derived idempotency key is withheld; the client's own client_key is echoed
 *   instead, which is the value the client actually needs to correlate a retry.
 *
 * This is why the resource does not simply forward $result->toArray().
 *
 * NO WRITES, NO ARITHMETIC
 * Amount strings come from the domain value objects (BetAmount and Money) and from the
 * persisted rows. Nothing is added, multiplied, rounded or cast to a float.
 */
final class BetPurchaseResource extends JsonResource
{
    /**
     * @param  string  $clientKey  the caller's own request key, echoed back so a client
     *                             can correlate the response with the request it retried
     */
    public function __construct(
        BetPurchaseResult $result,
        private readonly string $clientKey,
    ) {
        parent::__construct($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BetPurchaseResult $result */
        $result = $this->resource;

        $bet = $result->bet;
        $ticket = $result->ticket;

        return [
            // `replayed` tells the client whether this call created the purchase or read
            // back an existing one. It is the single most useful field on a retry and is
            // reported honestly rather than hidden behind an identical 200.
            'status' => $result->status->value,
            'replayed' => $result->isReplay(),

            'client_key' => $this->clientKey,

            'bet' => [
                'id' => $result->betId(),
                'uuid' => $bet->uuid,
                'bet_number' => $result->betNumber(),
                'status' => $bet->status?->value,
                'type' => $bet->type?->value,
            ],

            'ticket' => [
                'id' => $result->ticketId(),
                'uuid' => $ticket->uuid,
                'ticket_number' => $result->ticketNumber(),
                'status' => $ticket->status?->value,
            ],

            'draw_id' => (int) $bet->draw_id,

            // The stake exactly as the domain recorded it, and the currency it was
            // recorded in.
            'currency' => $result->stake->currency()->value,
            'total_stake' => $result->stake->amount(),

            // The liability the platform accepted, from the authoritative Money value
            // object. Never from request input.
            'potential_payout' => $result->potentialPayout->toString(),

            'placed_at' => $bet->placed_at?->toIso8601String(),

            'items' => [
                (new BetItemResource($result->item))->toArray($request),
            ],
        ];
    }
}
