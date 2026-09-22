<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TicketProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Public fixed-ticket product representation.
 *
 * WHAT THE CATALOGUE MAY SAY
 * Denomination, the draw/product identifiers, the window it sells within,
 * and an availability summary any buyer legitimately needs (how many units
 * remain, whether the product is ACTIVE right now). What it must never say:
 * internal product keys beyond the canonical code, operator notes from the
 * metadata lane, lifecycle forensics (suspension reasons and the like) —
 * the catalogue answers "can I buy it and for how much", not "what
 * happened to it behind the counter".
 *
 * @mixin TicketProduct
 */
final class TicketProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TicketProduct $product */
        $product = $this->resource;

        $remaining = max(0, (int) $product->units_total - (int) $product->units_allocated);

        $withinWindow = ($product->starts_at === null || $product->starts_at->isPast())
            && ($product->ends_at === null || $product->ends_at->isFuture());

        return [
            'product_code' => (string) $product->product_code,
            'draw_id' => $product->draw_id !== null ? (int) $product->draw_id : null,
            'denomination' => (string) $product->denomination,
            'currency' => $product->currency?->value ?? (string) $product->currency,
            'status' => $product->status?->value ?? (string) $product->status,
            'units_total' => (int) $product->units_total,
            'units_allocated' => (int) $product->units_allocated,
            'units_remaining' => $remaining,
            'available' => $product->status !== null
                && $product->status->isSellable()
                && $remaining > 0
                && $withinWindow,
            'starts_at' => $product->starts_at?->toIso8601String(),
            'ends_at' => $product->ends_at?->toIso8601String(),
        ];
    }
}
