<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FinancialHoldStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialHold extends Model
{
    protected $fillable = [
        'hold_key',
        'wallet_id',
        'amount',
        'currency',
        'reason',
        'source_reference',
        'reviewed_at',
        'released_at',
        'converted_at',
        'expires_at',
        'expired_at',
        'evidence',
        'metadata',
    ];

    protected $casts = [
        'status' => FinancialHoldStatus::class,
        'amount' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'released_at' => 'datetime',
        'converted_at' => 'datetime',
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
        'evidence' => 'array',
        'metadata' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
