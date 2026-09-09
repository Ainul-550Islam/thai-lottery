<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WinningNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of an official winning number.
 *
 * @mixin WinningNumber
 */
final class WinningNumberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WinningNumber $winningNumber */
        $winningNumber = $this->resource;

        return [
            'id' => (int) $winningNumber->getKey(),
            'draw_id' => (int) $winningNumber->draw_id,
            'bet_type' => $winningNumber->bet_type?->value,
            'number' => (string) $winningNumber->number,
            'prize_tier' => $winningNumber->prize_tier,
            'position' => $winningNumber->position,
            'payout_multiplier' => (int) $winningNumber->payout_multiplier,
            'total_winners' => (int) $winningNumber->total_winners,
            'published_at' => $winningNumber->published_at?->toIso8601String(),
        ];
    }
}
