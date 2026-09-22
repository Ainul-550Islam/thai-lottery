<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthenticationMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AuthenticationAttempt — one stamped authentication attempt.
 * Identifiers arrive pre-hashed: no cleartext identifier ever rests
 * in this ledger.
 *
 * @property int $id
 * @property string $attempt_fingerprint
 * @property int|null $user_id
 * @property AuthenticationMethod $method
 * @property string $identifier_hash
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $device_fingerprint
 * @property string $outcome
 * @property string|null $outcome_reason
 * @property Carbon $attempted_at
 */
class AuthenticationAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'attempt_fingerprint',
        'user_id',
        'method',
        'identifier_hash',
        'ip_address',
        'user_agent',
        'device_fingerprint',
        'outcome',
        'outcome_reason',
        'attempted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => AuthenticationMethod::class,
            'attempted_at' => 'datetime',
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
