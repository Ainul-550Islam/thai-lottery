<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComplianceActionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceAction extends Model
{
    protected $fillable = [
        'action_key',
        'case_id',
        'reason_code',
        'actor_user_id',
        'evidence_reference',
        'is_released',
        'wallet_fact',
        'applied_at',
        'released_at',
        'release_note',
        'metadata',
    ];

    protected $casts = [
        'action_type' => ComplianceActionType::class,
        'is_released' => 'boolean',
        'applied_at' => 'datetime',
        'released_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function case(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class, 'case_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
