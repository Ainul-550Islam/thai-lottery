<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentProviderStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment gateway provider as the house registers it. Status may
 * only move through PaymentProviderStatus transitions and is therefore
 * NOT mass-assignable: lanes set it by property after checks.
 */
class PaymentProvider extends Model
{
    protected $fillable = [
        'code',
        'name',
        'driver',
        'supported_currencies',
        'non_secrets',
        'suspended_at',
        'disabled_at',
        'status_reason',
    ];

    protected $casts = [
        'status' => PaymentProviderStatus::class,
        'supported_currencies' => 'array',
        'non_secrets' => 'array',
        'suspended_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];
}
