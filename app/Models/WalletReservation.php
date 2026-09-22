<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerEntryPurpose;
use App\Enums\WalletReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletReservation extends Model
{
    protected $fillable = [
        'reservation_key',
        'wallet_id',
        'reference',
        'amount',
        'currency',
        'purpose',
        'reserved_at',
        'consumed_at',
        'released_at',
        'expires_at',
        'expired_at',
        'metadata',
    ];

    protected $casts = [
        'status' => WalletReservationStatus::class,
        'purpose' => LedgerEntryPurpose::class,
        'amount' => 'decimal:2',
        'reserved_at' => 'datetime',
        'consumed_at' => 'datetime',
        'released_at' => 'datetime',
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
