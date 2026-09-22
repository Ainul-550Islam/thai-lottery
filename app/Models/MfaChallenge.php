<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MfaChallengeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * MfaChallenge — one MFA challenge. The secret itself never lives
 * here; only its fingerprint, so verification binds to the secret
 * without the key resting in the ledger.
 *
 * @property int $id
 * @property string $challenge_key
 * @property int $user_id
 * @property MfaChallengeStatus $status
 * @property string $channel
 * @property string $secret_fingerprint
 * @property int $attempts
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property Carbon|null $locked_at
 */
class MfaChallenge extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'challenge_key',
        'user_id',
        'status',
        'channel',
        'secret_fingerprint',
        'attempts',
        'issued_at',
        'expires_at',
        'verified_at',
        'locked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MfaChallengeStatus::class,
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'locked_at' => 'datetime',
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
     * Physical horizon check — server clock only.
     */
    public function isPastHorizon(): bool
    {
        return now()->gte($this->expires_at);
    }
}
