<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DrawReconciliationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrawReconciliation extends Model
{
    protected $fillable = [
        'reconciliation_key',
        'draw_id',
        'asserted_totals',
        'actual_totals',
        'drift_lines',
        'resolved_note',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'status' => DrawReconciliationStatus::class,
        'asserted_totals' => 'array',
        'actual_totals' => 'array',
        'drift_lines' => 'array',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }
}
