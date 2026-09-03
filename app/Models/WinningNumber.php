<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An officially published winning number of a draw.
 *
 * One row per (draw, bet type, number, prize tier). The number is a string so
 * leading zeros survive, and total_winners / total_payout are settlement
 * summaries written once by the settlement service.
 *
 * These rows are the official record of the draw: they are inserted when the
 * result is published and are then treated as read-only. Winner matching and
 * payout calculation happen in the settlement service, not here.
 *
 * @property int $id
 * @property int $draw_id
 * @property BetType $bet_type
 * @property string $number
 * @property string|null $prize_tier
 * @property string|null $position
 * @property int $payout_multiplier
 * @property int $total_winners
 * @property string $total_payout
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property array<string, mixed>|null $metadata
 */
class WinningNumber extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Publication-time fields only.
     *
     * total_winners and total_payout are settlement results and are excluded
     * from mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'draw_id',
        'bet_type',
        'number',
        'prize_tier',
        'position',
        'payout_multiplier',
        'published_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bet_type' => BetType::class,
            'number' => 'string',
            'prize_tier' => 'string',
            'position' => 'string',
            'payout_multiplier' => 'integer',
            'total_winners' => 'integer',
            'total_payout' => 'decimal:2',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Draw, self>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function hasWinners(): bool
    {
        return $this->total_winners > 0;
    }

    public function digitLength(): int
    {
        return mb_strlen($this->number);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForDraw(Builder $query, int $drawId): Builder
    {
        return $query->where('draw_id', $drawId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, BetType $type): Builder
    {
        return $query->where('bet_type', $type);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForNumber(Builder $query, string $number): Builder
    {
        return $query->where('number', $number);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfTier(Builder $query, string $tier): Builder
    {
        return $query->where('prize_tier', $tier);
    }
}
