<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceTrustStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * TrustedDevice — one device-identity row per (user, device
 * fingerprint). The presentation hash lets the desk recognize a
 * device without storing what a recognizer needs most of it.
 *
 * @property int $id
 * @property string $device_fingerprint
 * @property int $user_id
 * @property DeviceTrustStatus $trust_status
 * @property string $presentation_hash
 * @property string $evidence_fingerprint
 * @property Carbon $registered_at
 * @property Carbon|null $trusted_at
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property string|null $revocation_reason
 */
class TrustedDevice extends Model
{
    protected $table = 'trusted_devices';

    public const UPDATED_AT = null;

    protected $fillable = [
        'device_fingerprint',
        'user_id',
        'trust_status',
        'presentation_hash',
        'evidence_fingerprint',
        'registered_at',
        'trusted_at',
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
            'trust_status' => DeviceTrustStatus::class,
            'registered_at' => 'datetime',
            'trusted_at' => 'datetime',
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
}
