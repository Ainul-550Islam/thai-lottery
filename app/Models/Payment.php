<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference_number',
        'user_id',
        'payable_type',
        'payable_id',
        'method',
        'status',
        'currency',
        'amount',
        'fee',
        'gateway',
        'gateway_reference',
        'gateway_response',
        'authorized_at',
        'captured_at',
        'failed_at',
        'failure_reason',
        'metadata',
    ];

    protected $hidden = [
        'gateway_response',
    ];

    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'gateway_response' => 'array',
            'authorized_at' => 'datetime',
            'captured_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function isSuccessful(): bool
    {
        return $this->status === PaymentStatus::Captured;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function scopeCaptured($query)
    {
        return $query->where('status', PaymentStatus::Captured);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUsingMethod($query, PaymentMethod $method)
    {
        return $query->where('method', $method);
    }
}
