<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentDirection;
use App\Enums\PaymentFailureReason;
use App\Enums\PaymentTransactionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The house's own pre-payment fact. `status` moves through
 * PaymentTransactionStatus transitions ONLY (property set after
 * checks — never mass-assignable).
 */
class PaymentIntent extends Model
{
    protected $fillable = [
        'intent_key',
        'user_id',
        'wallet_id',
        'payment_id',
        'direction',
        'method_code',
        'amount',
        'currency',
        'idempotency_key',
        'expires_at',
        'succeeded_at',
        'failed_at',
        'reversed_at',
        'metadata',
    ];

    protected $casts = [
        'status' => PaymentTransactionStatus::class,
        'direction' => PaymentDirection::class,
        'failure_reason' => PaymentFailureReason::class,
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'failed_at' => 'datetime',
        'reversed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
