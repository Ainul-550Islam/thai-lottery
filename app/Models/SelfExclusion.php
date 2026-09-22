<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SelfExclusionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * SelfExclusion — one self-exclusion pronouncement (immutable facts).
 *
 * @property int $id
 * @property int $user_id
 * @property SelfExclusionStatus $status
 * @property string $scope
 * @property string $reason_code
 * @property string $request_fingerprint
 * @property Carbon $effective_at
 * @property Carbon $ends_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancelled_by
 */
class SelfExclusion extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'scope',
        'reason_code',
        'request_fingerprint',
        'effective_at',
        'ends_at',
        'activated_at',
        'expired_at',
        'cancelled_at',
        'cancelled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SelfExclusionStatus::class,
            'effective_at' => 'datetime',
            'ends_at' => 'datetime',
            'activated_at' => 'datetime',
            'expired_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * The server-authoritative live gate: Active AND before end-of-time.
     * This boolean is derived anew at every read — the row never caches
     * its own liveness.
     */
    public function currentlyGates(): bool
    {
        return $this->status === SelfExclusionStatus::Active
            && now()->lt($this->ends_at);
    }
}
