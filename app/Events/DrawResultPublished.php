<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Draw;
use App\Models\DrawResult;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast when official draw results are published.
 */
final class DrawResultPublished implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Draw $draw,
        public readonly DrawResult $result,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('draws'),
            new Channel('draw.'.$this->draw->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'draw.result.published';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $this->result->loadMissing('winningNumbers');

        return [
            'draw_id' => (int) $this->draw->id,
            'draw_number' => (string) $this->draw->draw_number,
            'first_prize' => (string) $this->result->first_prize,
            'second_prize' => $this->result->second_prize,
            'third_prize' => $this->result->third_prize,
            'two_digit_bottom' => $this->result->metadata['bottom_two'] ?? $this->result->metadata['two_digit_bottom'] ?? null,
            'total_winners' => (int) $this->result->total_winners,
            'total_payout' => (string) $this->result->total_payout,
            'published_at' => $this->result->published_at?->toIso8601String(),
            'winning_numbers' => $this->result->winningNumbers->map(fn ($wn): array => [
                'bet_type' => $wn->bet_type?->value,
                'number' => (string) $wn->number,
                'prize_tier' => $wn->prize_tier,
                'position' => $wn->position,
                'payout_multiplier' => (int) $wn->payout_multiplier,
            ])->values()->all(),
        ];
    }
}
