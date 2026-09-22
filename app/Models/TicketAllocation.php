<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketAllocationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One vendor-allocation conversation row. No counters: membership truth is
 * projected from the units naming this allocation as their holder.
 */
final class TicketAllocation extends Model
{
    protected $fillable = [
        'allocation_key',
        'vendor_id',
        'ticket_product_id',
        'draw_id',
        'quantity',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketAllocationStatus::class,
            'quantity' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RetailVendor, self>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(RetailVendor::class, 'vendor_id');
    }

    /**
     * @return BelongsTo<TicketProduct, self>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(TicketProduct::class, 'ticket_product_id');
    }

    /**
     * @return BelongsTo<Draw, self>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class, 'draw_id');
    }

    /**
     * The member stamps: units this allocation currently holds.
     *
     * @return HasMany<TicketInventoryItem, self>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TicketInventoryItem::class, 'ticket_allocation_id');
    }
}
