<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerReconciliation extends Model
{
    protected $fillable = [
        'reconciliation_key',
        'wallet_id',
        'currency',
        'scope',
        'expected_balance',
        'ledger_aggregate',
        'reservation_effect',
        'fingerprint',
        'drift_lines',
        'resolved_note',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'status' => LedgerReconciliationStatus::class,
        'expected_balance' => 'decimal:2',
        'ledger_aggregate' => 'decimal:2',
        'reservation_effect' => 'decimal:2',
        'drift_lines' => 'array',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
