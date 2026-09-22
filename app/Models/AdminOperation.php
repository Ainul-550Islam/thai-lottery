<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdminOperationStatus;
use App\Enums\AdminOperationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One evidence-backed admin operation; identity = operation_fingerprint.
 * Maker (actor) and checker (approver) are always different humans.
 *
 * @property int $id
 * @property string $operation_fingerprint
 * @property int $actor_user_id
 * @property AdminOperationType $type
 * @property AdminOperationStatus $status
 * @property string $target_lane
 * @property string|null $target_reference
 * @property string $payload_fingerprint
 * @property array $payload
 * @property string|null $evidence_fingerprint
 * @property int|null $approver_user_id
 * @property string|null $approval_note
 * @property string|null $denial_reason
 * @property string|null $result_summary
 * @property Carbon|null $approved_at
 * @property Carbon|null $executed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 */
class AdminOperation extends Model
{
    protected $fillable = [
        'operation_fingerprint', 'actor_user_id', 'type', 'status',
        'target_lane', 'target_reference', 'payload_fingerprint', 'payload',
        'evidence_fingerprint', 'approver_user_id', 'approval_note',
        'denial_reason', 'result_summary',
        'approved_at', 'executed_at', 'completed_at', 'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AdminOperationType::class,
            'status' => AdminOperationStatus::class,
            'payload' => 'array',
            'approved_at' => 'datetime',
            'executed_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
