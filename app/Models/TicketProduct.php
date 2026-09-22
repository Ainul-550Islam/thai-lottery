<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TicketProductStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A manufactured fixed lottery-ticket product (offering line).
 *
 * See the migration for the doctrine: identity derived at the engine
 * (product_key unique), money as DECIMAL strings, lifecycle stamps on the
 * row, and allocation counters that advance only atomically through
 * AllocateTicketInventoryJob — never directly.
 *
 * @property int $id
 * @property string $product_key
 * @property string $product_code
 * @property int $draw_id
 * @property string $denomination
 * @property Currency $currency
 * @property int $units_total
 * @property int $units_allocated
 * @property TicketProductStatus $status
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $closed_at
 * @property array<string, mixed>|null $metadata
 */
class TicketProduct extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'ticket_products';

    /**
     * The mutation surface. product_key is included for creation writes
     * only; re-writing it after creation would rename a printed line.
     *
     * @var list<string>
     */
    protected $fillable = [
        'product_key',
        'product_code',
        'draw_id',
        'denomination',
        'currency',
        'units_total',
        'units_allocated',
        'status',
        'starts_at',
        'ends_at',
        'activated_at',
        'closed_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'status' => TicketProductStatus::class,
            'denomination' => 'decimal:2',
            'units_total' => 'integer',
            'units_allocated' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The draw this product is offered against.
     *
     * @return BelongsTo<Draw, self>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    /**
     * Remaining allocation headroom: units_total − units_allocated.
     */
    public function remainingUnits(): int
    {
        return max(0, (int) $this->units_total - (int) $this->units_allocated);
    }

    /**
     * Is the product acceptably retailable right now (Active AND inside
     * its optional availability window when one exists)?
     */
    public function isCurrentlySellable(): bool
    {
        if (! $this->status->isSellable()) {
            return false;
        }

        $now = now();

        if ($this->starts_at !== null && $now->lt($this->starts_at)) {
            return false;
        }

        if ($this->ends_at !== null && $now->gt($this->ends_at)) {
            return false;
        }

        return true;
    }
}
