<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SecuritySession — the security lane's session ledger, standing
 * beside Laravel's transport tables (sessions, personal_access_
 * tokens) as the authoritative life-cycle record.
 *
 * @property int $id
 * @property string $session_fingerprint
 * @property int $user_id
 * @property UserSessionStatus $status
 * @property string|null $device_fingerprint
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revocation_reason
 */
class SecuritySession extends Model
{
    protected $table = 'security_sessions';

    public const UPDATED_AT = null;

    protected $fillable = [
        'session_fingerprint',
        'user_id',
        'status',
        'device_fingerprint',
        'ip_address',
        'user_agent',
        'issued_at',
        'expires_at',
        'last_seen_at',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => UserSessionStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
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
     * Server-time validity, derived at every read — never a flag.
     */
    public function currentlyValid(): bool
    {
        return $this->status === UserSessionStatus::Active && now()->lt($this->expires_at);
    }
}
