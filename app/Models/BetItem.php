<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One selected number inside a bet.
 *
 * The number is always stored and compared as a string so that leading zeros
 * ("007", "05") are never lost, and the stake is an exact decimal string. The
 * bet type is not duplicated on this row: it is owned by the parent bet, and the
 * bet_type accessor reads it from there.
 *
 * 3D, 2D, Tod and Run matching, and any payout calculation, belong to the
 * betting and settlement services.
 *
 * @property int $id
 * @property int $bet_id
 * @property string $number
 * @property string|null $position
 * @property string $amount
 * @property int $payout_multiplier
 * @property string $potential_payout
 * @property bool $is_winner
 * @property string $actual_payout
 * @property array<string, mixed>|null $metadata
 * @property-read BetType|null $bet_type
 * @property-read string $stake
 */
class BetItem extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Placement-time fields only.
     *
     * is_winner and actual_payout are settlement results and are excluded from
     * mass assignment so they can only be written by the settlement service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'bet_id',
        'number',
        'position',
        'amount',
        'payout_multiplier',
        'potential_payout',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'string',
            'position' => 'string',
            'amount' => 'decimal:2',
            'payout_multiplier' => 'integer',
            'potential_payout' => 'decimal:2',
            'is_winner' => 'boolean',
            'actual_payout' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Bet, self>
     */
    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    /**
     * The draw this selection competes in, reached through the parent bet.
     *
     * @return HasOneThrough<Draw>
     */
    public function draw(): HasOneThrough
    {
        return $this->hasOneThrough(
            Draw::class,
            Bet::class,
            'id',       // bets.id, matched against bet_items.bet_id
            'id',       // draws.id, matched against bets.draw_id
            'bet_id',
            'draw_id'
        );
    }

    /**
     * Bet type of the parent bet, exposed for convenience.
     *
     * @return Attribute<BetType|null, never>
     */
    protected function betType(): Attribute
    {
        return Attribute::get(fn (): ?BetType => $this->bet?->type)->shouldCache();
    }

    /**
     * Readable alias of the amount column, which is the stake of this selection.
     *
     * @return Attribute<string, never>
     */
    protected function stake(): Attribute
    {
        return Attribute::get(fn (): string => bcadd((string) $this->amount, '0', 2));
    }

    public function isWinner(): bool
    {
        return $this->is_winner === true;
    }

    /**
     * Stake multiplied by the multiplier recorded at placement time, as an exact
     * decimal string. This is a pure read of stored values, not a pricing rule:
     * the multiplier itself comes from configuration through the betting service.
     */
    public function calculatePayout(): string
    {
        return bcmul((string) $this->amount, (string) $this->payout_multiplier, 2);
    }

    public function digitLength(): int
    {
        return mb_strlen($this->number);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWinners(Builder $query): Builder
    {
        return $query->where('is_winner', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForBet(Builder $query, int $betId): Builder
    {
        return $query->where('bet_id', $betId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForNumber(Builder $query, string $number): Builder
    {
        return $query->where('number', $number);
    }
}
