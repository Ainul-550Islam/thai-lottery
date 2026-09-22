<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethodConfig extends Model
{
    protected $fillable = [
        'method_key',
        'provider_id',
        'method_code',
        'currency',
        'min_amount',
        'max_amount',
        'fee_bps',
        'retired_at',
        'metadata',
    ];

    protected $casts = [
        'status' => PaymentMethodStatus::class,
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'retired_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class, 'provider_id');
    }
}
