<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AmlRiskLevel;
use App\Enums\ComplianceCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One compliance investigation file. `status` moves strictly through
 * ComplianceCaseStatus transitions — property-set after checks.
 */
class ComplianceCase extends Model
{
    protected $fillable = [
        'case_key',
        'subject_user_id',
        'case_type',
        'risk_level',
        'trigger_reference',
        'evidence_fingerprint',
        'assigned_desk',
        'escalation_reason',
        'resolution_note',
        'investigating_since',
        'escalated_at',
        'resolved_at',
        'resolved_by',
        'closed_at',
        'closed_by',
        'metadata',
    ];

    protected $casts = [
        'status' => ComplianceCaseStatus::class,
        'risk_level' => AmlRiskLevel::class,
        'investigating_since' => 'datetime',
        'escalated_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    /**
     * @return HasMany<ComplianceAction, self>
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ComplianceAction::class, 'case_id');
    }
}
