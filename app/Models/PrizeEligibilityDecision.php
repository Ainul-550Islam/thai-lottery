<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrizeEligibilityStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrizeEligibilityDecision extends Model
{
    protected $fillable = [
        'decision_key',
        'payout_id',
        'user_id',
        'snapshot',
        'refusal_code',
        'refusal_reason',
    ];

    protected $casts = [
        'status' => PrizeEligibilityStatus::class,
        'snapshot' => 'array',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
