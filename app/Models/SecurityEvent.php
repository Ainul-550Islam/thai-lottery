<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SecurityEvent — one immutable row of the security ledger, keyed by
 * its fingerprint: the same occurrence re-pronounced from any caller
 * lands exactly once.
 *
 * @property int $id
 * @property string $event_fingerprint
 * @property SecurityEventType $event_type
 * @property int|null $user_id
 * @property SecurityRiskLevel $risk_level
 * @property string|null $ip_address
 * @property string|null $device_fingerprint
 * @property string|null $session_fingerprint
 * @property array<string, mixed>|null $payload
 * @property bool $reviewed
 * @property Carbon $occurred_at
 */
class SecurityEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_fingerprint',
        'event_type',
        'user_id',
        'risk_level',
        'ip_address',
        'device_fingerprint',
        'session_fingerprint',
        'payload',
        'reviewed',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => SecurityEventType::class,
            'risk_level' => SecurityRiskLevel::class,
            'payload' => 'array',
            'reviewed' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
