<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketInventoryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One unit of inventory paper. The ledger discriminates everything by
 * row + status; nothing drift-compatible is ever derived from it sideways.
 */
final class TicketInventoryItem extends Model
{
    protected $fillable = [
        'inventory_key',
        'serial',
        'ticket_product_id',
        'draw_id',
        'ticket_allocation_id',
        'fingerprint',
        'reserved_by_vendor_id',
        'reserved_at',
        'reserved_until',
        'voided_reason',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketInventoryStatus::class,
            'reserved_at' => 'datetime',
            'reserved_until' => 'datetime',
            'metadata' => 'array',
        ];
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
     * @return BelongsTo<TicketAllocation, self>
     */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(TicketAllocation::class, 'ticket_allocation_id');
    }

    /**
     * @return BelongsTo<RetailVendor, self>
     */
    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(RetailVendor::class, 'reserved_by_vendor_id');
    }
}
