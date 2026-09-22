<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ResponsibleGamingLimitStatus;
use App\Enums\ResponsibleGamingLimitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ResponsibleGamingLimitVersion — one immutable limit pronouncement.
 * Limits are never edited; newer versions stand beside the old.
 *
 * @property int $id
 * @property int $user_id
 * @property ResponsibleGamingLimitType $limit_type
 * @property ResponsibleGamingLimitStatus $limit_status
 * @property string $amount
 * @property string $currency
 * @property string $limit_key
 * @property string|null $replaced_by_key
 * @property Carbon|null $effective_from
 * @property Carbon|null $effective_to
 * @property Carbon|null $activated_at
 * @property Carbon|null $expired_at
 * @property Carbon|null $cancelled_at
 */
class ResponsibleGamingLimitVersion extends Model
{
    protected $table = 'responsible_gaming_limit_versions';

    protected $fillable = [
        'user_id',
        'limit_type',
        'limit_status',
        'amount',
        'currency',
        'limit_key',
        'replaced_by_key',
        'effective_from',
        'effective_to',
        'activated_at',
        'expired_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limit_type' => ResponsibleGamingLimitType::class,
            'limit_status' => ResponsibleGamingLimitStatus::class,
            'amount' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
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
}
