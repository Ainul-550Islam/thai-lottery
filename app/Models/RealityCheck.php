<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RealityCheckStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * RealityCheck — one reality-check pronouncement against a session.
 *
 * @property int $id
 * @property int $user_id
 * @property RealityCheckStatus $status
 * @property string $session_reference
 * @property int $threshold_minutes
 * @property Carbon $due_at
 * @property string $delivery_fingerprint
 * @property string $delivery_channel
 * @property Carbon|null $delivered_at
 * @property Carbon|null $acknowledged_at
 * @property string|null $acknowledgement_fingerprint
 * @property Carbon $expires_at
 */
class RealityCheck extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'session_reference',
        'threshold_minutes',
        'due_at',
        'delivery_fingerprint',
        'delivery_channel',
        'delivered_at',
        'acknowledged_at',
        'acknowledgement_fingerprint',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RealityCheckStatus::class,
            'due_at' => 'datetime',
            'delivered_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * Server-authoritative "is this check past its horizon" — physics,
     * never a stored flag.
     */
    public function isOverdue(): bool
    {
        return ! $this->status->isTerminal() && now()->gt($this->expires_at);
    }
}
