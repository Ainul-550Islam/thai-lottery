<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\DTOs\Betting\BetAmendmentResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a bet amendment outcome.
 *
 * THE FAILED STATE IS DATA, NOT AN ERROR ENVELOPE
 * When the replacement purchase was refused, HTTP stays 200 and this resource
 * reports status "failed" with the failure_reason, because both halves of the
 * outcome are successful information the client must render: "refunded — and
 * replacement refused, reason X". The controller only maps pre-state refusals
 * (window, settled bet) onto 4xx.
 *
 * @mixin BetAmendmentResult
 */
final class BetAmendmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BetAmendmentResult $result */
        $result = $this->resource;

        return [
            'amendment_id' => (int) $result->amendment->getKey(),
            'amendment_uuid' => $result->amendment->uuid,
            'status' => $result->status->value,
            'original_bet' => [
                'id' => (int) $result->originalBet->getKey(),
                'bet_number' => (string) $result->originalBet->bet_number,
                'status' => $result->originalBet->status->value,
            ],
            'replacement_bet' => $result->replacementBet === null ? null : [
                'id' => (int) $result->replacementBet->getKey(),
                'bet_number' => (string) $result->replacementBet->bet_number,
                'status' => $result->replacementBet->status->value,
                'stake' => (string) $result->replacementBet->stake_amount,
                'potential_payout' => (string) $result->replacementBet->potential_payout,
            ],
            'money' => [
                'refunded' => $result->refundedAmount,
                'charged' => $result->chargedAmount,
                'currency' => $result->currency,
            ],
            'failure_reason' => $result->failureReason,
            'applied_at' => $result->amendment->applied_at?->toIso8601String(),
        ];
    }
}
