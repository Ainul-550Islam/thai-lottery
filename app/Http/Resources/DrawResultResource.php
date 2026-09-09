<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DrawResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a published official draw result.
 *
 * @mixin DrawResult
 */
final class DrawResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DrawResult $result */
        $result = $this->resource;

        return [
            'id' => (int) $result->getKey(),
            'draw_id' => (int) $result->draw_id,
            'first_prize' => (string) $result->first_prize,
            'second_prize' => $result->second_prize,
            'third_prize' => $result->third_prize,
            'consolation_prizes' => $result->consolation_prizes,
            'all_numbers' => $result->all_numbers,
            'two_digit_bottom' => $result->metadata['bottom_two'] ?? $result->metadata['two_digit_bottom'] ?? null,
            'total_winners' => (int) $result->total_winners,
            'total_payout' => (string) $result->total_payout,
            'published_at' => $result->published_at?->toIso8601String(),
            'winning_numbers' => WinningNumberResource::collection($this->whenLoaded('winningNumbers')),
        ];
    }
}
