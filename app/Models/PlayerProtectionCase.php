<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerProtectionCaseStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * PlayerProtectionCase — one live protection file on the desk.
 *
 * @property int $id
 * @property string $case_key
 * @property int $user_id
 * @property PlayerProtectionCaseStatus $status
 * @property string $trigger_reason
 * @property string|null $action_summary
 * @property array<string, mixed> $risk_indicators
 * @property string $evidence_fingerprint
 * @property Carbon $opened_at
 * @property Carbon|null $monitoring_since
 * @property Carbon|null $escalated_at
 * @property string|null $escalated_by
 * @property string|null $escalation_reason
 * @property Carbon|null $resolved_at
 * @property string|null $resolved_by
 * @property string|null $resolution_note
 * @property Carbon|null $closed_at
 * @property string|null $closed_by
 */
class PlayerProtectionCase extends Model
{
    protected $fillable = [
        'case_key',
        'user_id',
        'status',
        'trigger_reason',
        'action_summary',
        'risk_indicators',
        'evidence_fingerprint',
        'opened_at',
        'monitoring_since',
        'escalated_at',
        'escalated_by',
        'escalation_reason',
        'resolved_at',
        'resolved_by',
        'resolution_note',
        'closed_at',
        'closed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlayerProtectionCaseStatus::class,
            'risk_indicators' => 'array',
            'opened_at' => 'datetime',
            'monitoring_since' => 'datetime',
            'escalated_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PlayerProtectionAct>
     */
    public function acts(): HasMany
    {
        return $this->hasMany(PlayerProtectionAct::class, 'case_key', 'case_key');
    }
}
