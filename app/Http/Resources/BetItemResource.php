<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BetItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The public shape of one bet line.
 *
 * @mixin BetItem
 */
final class BetItemResource extends JsonResource
{
    /**
     * Metadata keys that are safe to surface.
     *
     * `covered_numbers` and `covered_number_count` describe a 3D Tod selection's
     * arrangements and are exactly what a player needs to see to confirm what they bought.
     * `charges` is included because it is the field that proves one Tod selection was
     * charged once, not once per arrangement. Everything else in metadata - internal risk
     * context, reservation identifiers, diagnostic breadcrumbs - is withheld.
     *
     * @var list<string>
     */
    private const PUBLIC_METADATA_KEYS = [
        'covered_numbers',
        'covered_number_count',
        'charges',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var BetItem $item */
        $item = $this->resource;

        return [
            'id' => (int) $item->getKey(),

            // bet_items has no `market` column in the Phase 1 schema. Phase 4.3 records
            // the market key inside the item's metadata, so it is read from there rather
            // than from a column that does not exist. No migration is added for this: the
            // value is already persisted and already retrievable.
            'market' => $this->marketKey($item),

            // Returned as the stored string. The column is string(16) and the value is
            // read straight out of it, so '007' comes back as '007'. No cast, no
            // number_format, no arithmetic touches this field anywhere in this class.
            'number' => (string) $item->number,

            'position' => $item->position,

            // Amounts are the model's decimal:2 strings, forwarded verbatim.
            'stake' => (string) $item->amount,

            // The rate the DOMAIN resolved from config/lottery.php and stored at purchase
            // time. It is read back from the row rather than recomputed here, so this
            // response cannot disagree with the liability the platform actually booked.
            'payout_multiplier' => (int) $item->payout_multiplier,

            'potential_payout' => (string) $item->potential_payout,

            'metadata' => $this->publicMetadata($item),
        ];
    }

    private function marketKey(BetItem $item): ?string
    {
        $metadata = $item->metadata;

        if (! is_array($metadata)) {
            return null;
        }

        $market = $metadata['market'] ?? null;

        return is_string($market) ? $market : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicMetadata(BetItem $item): array
    {
        $metadata = $item->metadata;

        if (! is_array($metadata)) {
            return [];
        }

        $public = [];

        foreach (self::PUBLIC_METADATA_KEYS as $key) {
            if (array_key_exists($key, $metadata)) {
                $public[$key] = $metadata[$key];
            }
        }

        return $public;
    }
}
