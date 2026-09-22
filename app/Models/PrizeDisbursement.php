<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrizeDisbursementStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrizeDisbursement extends Model
{
    protected $fillable = [
        'disbursement_key',
        'payout_id',
        'payout_batch_id',
        'amount',
        'currency',
        'settlement_fingerprint',
        'reserved_at',
        'disbursed_at',
        'reversed_at',
        'failed_at',
        'failure_reason',
        'reversal_reason',
        'metadata',
    ];

    protected $casts = [
        'status' => PrizeDisbursementStatus::class,
        'amount' => 'decimal:2',
        'reserved_at' => 'datetime',
        'disbursed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'failed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }
}
