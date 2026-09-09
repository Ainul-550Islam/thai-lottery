<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Draw;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public shape of a lottery draw.
 *
 * @mixin Draw
 */
final class DrawResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Draw $draw */
        $draw = $this->resource;

        $hasPublishedResult = in_array($draw->status?->value, ['result_published', 'completed'], true);

        return [
            'id' => (int) $draw->getKey(),
            'draw_number' => (string) $draw->draw_number,
            'type' => $draw->type?->value,
            'status' => $draw->status?->value,
            'is_open' => $draw->isOpen(),
            'can_accept_bets' => $draw->canAcceptBets(),
            'scheduled_at' => $draw->scheduled_at?->toIso8601String(),
            'betting_open_at' => $draw->betting_open_at?->toIso8601String() ?? $draw->opened_at?->toIso8601String(),
            'betting_close_at' => $draw->betting_close_at?->toIso8601String() ?? $draw->closed_at?->toIso8601String(),
            'opened_at' => $draw->opened_at?->toIso8601String(),
            'closed_at' => $draw->closed_at?->toIso8601String(),
            'drawn_at' => $draw->drawn_at?->toIso8601String(),
            'completed_at' => $draw->completed_at?->toIso8601String(),
            'result_published_at' => $draw->result_published_at?->toIso8601String(),
            'total_bets' => (int) $draw->total_bets,
            'created_at' => $draw->created_at?->toIso8601String(),
            'result' => $hasPublishedResult ? new DrawResultResource($this->whenLoaded('result')) : null,
            'winning_numbers' => $hasPublishedResult ? WinningNumberResource::collection($this->whenLoaded('winningNumbers')) : [],
        ];
    }
}
