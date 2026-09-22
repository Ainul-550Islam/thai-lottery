<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of a bulk purchase report.
 *
 * PARTIAL SUCCESS IS DATA
 * The report distinguishes purchased / replayed / refused per item — one
 * refused selection never erases the bets that committed, and the client must
 * render both halves faithfully, so HTTP is 200 whenever the slip itself was
 * processed and per-item outcomes live here. The money line (total_charged)
 * always equals the sum of the purchased items' stakes: bcmath, never float.
 *
 * @mixin array<string, mixed>
 */
final class BulkBetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $report */
        $report = $this->resource;

        return [
            'draw_id' => (int) $report['draw_id'],
            'summary' => [
                'requested' => (int) $report['requested'],
                'purchased' => (int) $report['purchased'],
                'replayed' => (int) $report['replayed'],
                'refused' => (int) $report['refused'],
            ],
            'money' => [
                'total_charged' => (string) $report['total_charged'],
                'currency' => (string) $report['currency'],
            ],
            'items' => array_map(
                static fn (array $item): array => [
                    'index' => (int) $item['index'],
                    'outcome' => (string) $item['outcome'],
                    'market' => (string) $item['selection']['market'],
                    'number' => (string) $item['selection']['number'],
                    'stake' => (string) $item['stake'],
                    'bet_id' => $item['bet_id'],
                    'bet_number' => $item['bet_number'],
                    'ticket_number' => $item['ticket_number'],
                    'potential_payout' => $item['potential_payout'],
                    'reason' => $item['reason'],
                ],
                (array) $report['items'],
            ),
        ];
    }
}
