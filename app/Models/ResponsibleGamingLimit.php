<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Player Responsible Gaming Limits and Exclusion Controls.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $daily_deposit_limit
 * @property string|null $single_bet_limit
 * @property string|null $daily_wagering_limit
 * @property Carbon|null $self_excluded_until
 * @property Carbon|null $cool_off_until
 * @property string|null $self_exclusion_reason
 * @property array<string, mixed>|null $metadata
 */
class ResponsibleGamingLimit extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'daily_deposit_limit',
        'single_bet_limit',
        'daily_wagering_limit',
        'self_excluded_until',
        'cool_off_until',
        'self_exclusion_reason',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_deposit_limit' => 'decimal:2',
            'single_bet_limit' => 'decimal:2',
            'daily_wagering_limit' => 'decimal:2',
            'self_excluded_until' => 'datetime',
            'cool_off_until' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isSelfExcluded(): bool
    {
        if ($this->self_excluded_until !== null && $this->self_excluded_until->isFuture()) {
            return true;
        }

        if ($this->cool_off_until !== null && $this->cool_off_until->isFuture()) {
            return true;
        }

        return false;
    }
}
