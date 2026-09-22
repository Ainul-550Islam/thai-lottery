<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerProtectionAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PlayerProtectionAct — one stamped protection action against a case.
 * (Model name is Act to keep it distinct from the Action enum.)
 *
 * @property int $id
 * @property string $action_key
 * @property string $case_key
 * @property PlayerProtectionAction $action_type
 * @property string $scope
 * @property int $actor_user_id
 * @property string $reason_code
 * @property bool $is_released
 * @property string|null $wallet_fact
 * @property int|null $wallet_id
 * @property Carbon $applied_at
 * @property Carbon|null $released_at
 * @property int|null $released_by
 */
class PlayerProtectionAct extends Model
{
    protected $table = 'player_protection_actions';

    protected $fillable = [
        'action_key',
        'case_key',
        'action_type',
        'scope',
        'actor_user_id',
        'reason_code',
        'is_released',
        'wallet_fact',
        'wallet_id',
        'applied_at',
        'released_at',
        'released_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action_type' => PlayerProtectionAction::class,
            'is_released' => 'boolean',
            'applied_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlayerProtectionCase, self>
     */
    public function protectionCase(): BelongsTo
    {
        return $this->belongsTo(PlayerProtectionCase::class, 'case_key', 'case_key');
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
