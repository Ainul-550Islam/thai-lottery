<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RetailVendorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A retail channel row. Lifecycle-typed so every read is enum-honest.
 */
final class RetailVendor extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vendor_code',
        'name',
        'contact_email',
        'contact_phone',
        'quota_capacity',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RetailVendorStatus::class,
            'quota_capacity' => 'integer',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<TicketAllocation, self>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(TicketAllocation::class, 'vendor_id');
    }
}
