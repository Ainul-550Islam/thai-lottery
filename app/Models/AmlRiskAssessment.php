<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmlRiskLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmlRiskAssessment extends Model
{
    public const STATUS_CURRENT = 'current';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'assessment_key',
        'user_id',
        'score',
        'reason_codes',
        'assessment_version',
        'evidence_fingerprint',
        'status',
        'assessed_at',
        'superseded_at',
        'metadata',
    ];

    protected $casts = [
        'risk_level' => AmlRiskLevel::class,
        'reason_codes' => 'array',
        'assessed_at' => 'datetime',
        'superseded_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
