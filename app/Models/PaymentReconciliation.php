<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Provider-vs-internal reconciliation conversation row. Reuses the
 * ledger lane's status vocabulary (Pending/Matched/DriftDetected/
 * Resolved) for one alphabet of drift across the estate.
 */
class PaymentReconciliation extends Model
{
    protected $fillable = [
        'reconciliation_key',
        'payment_id',
        'provider',
        'external_reference',
        'expected_amount',
        'observed_amount',
        'currency',
        'internal_status',
        'observed_status',
        'fingerprint',
        'drift_lines',
        'resolved_note',
        'resolved_at',
    ];

    protected $casts = [
        'status' => LedgerReconciliationStatus::class,
        'expected_amount' => 'decimal:2',
        'observed_amount' => 'decimal:2',
        'drift_lines' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
