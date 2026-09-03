<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetType;
use App\Enums\LimitStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Exposure limit for one number, in one bet type, in one draw.
 *
 * max_amount is the accepted stake ceiling and current_amount is the stake
 * accumulated so far, both exact decimal strings. The unique key
 * (draw_id, bet_type, number) is what makes an atomic conditional update
 * possible later.
 *
 * The read helpers below answer questions about the stored values only. They are
 * not safe to use as a gate on their own: the number-limit service must re-check
 * the limit inside a locked transaction, because between a read here and a write
 * there another request may already have consumed the remaining capacity.
 *
 * @property int $id
 * @property int $draw_id
 * @property BetType $bet_type
 * @property string $number
 * @property string $max_amount
 * @property string $current_amount
 * @property string|null $maximum_payout_exposure
 * @property string $current_payout_exposure
 * @property LimitStatus $status
 * @property \Illuminate\Support\Carbon|null $exceeded_at
 * @property array<string, mixed>|null $metadata
 * @property-read string $remaining_amount
 * @property-read string $utilisation_percent
 */
class NumberLimit extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Limit definition fields only.
     *
     * current_amount, status and exceeded_at are consumption state written by
     * the number-limit service through atomic updates, never mass assigned.
     *
     * @var list<string>
     */
    protected $fillable = [
        'draw_id',
        'bet_type',
        'number',
        'max_amount',
        'maximum_payout_exposure',
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
            'status' => LimitStatus::class,
            'max_amount' => 'decimal:2',
            'current_amount' => 'decimal:2',
            'maximum_payout_exposure' => 'decimal:2',
            'current_payout_exposure' => 'decimal:2',
            'exceeded_at' => 'datetime',
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

    /**
     * Stake still acceptable according to the stored values, never below zero.
     *
     * @return Attribute<string, never>
     */
    protected function remainingAmount(): Attribute
    {
        return Attribute::get(function (): string {
            $remaining = bcsub((string) $this->max_amount, (string) $this->current_amount, 2);

            return bccomp($remaining, '0.00', 2) < 0 ? '0.00' : $remaining;
        })->shouldCache();
    }

    /**
     * Consumed share of the limit as an exact decimal string percentage.
     *
     * @return Attribute<string, never>
     */
    protected function utilisationPercent(): Attribute
    {
        return Attribute::get(function (): string {
            if (bccomp((string) $this->max_amount, '0.00', 2) <= 0) {
                return '0.00';
            }

            return bcdiv(
                bcmul((string) $this->current_amount, '100', 4),
                (string) $this->max_amount,
                2
            );
        })->shouldCache();
    }

    public function remainingCapacity(): string
    {
        return $this->remaining_amount;
    }

    public function isExceeded(): bool
    {
        return bccomp((string) $this->current_amount, (string) $this->max_amount, 2) >= 0;
    }

    public function isActive(): bool
    {
        return $this->status === LimitStatus::Active;
    }

    /**
     * Whether the stored values still leave room for the given stake. This is an
     * indication only; the authoritative check is the atomic update performed by
     * the number-limit service.
     */
    public function canAccept(string $amount): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        return bccomp(
            bcadd((string) $this->current_amount, $amount, 2),
            (string) $this->max_amount,
            2
        ) <= 0;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', LimitStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExceeded(Builder $query): Builder
    {
        return $query->where('status', LimitStatus::Exceeded);
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
    public function scopeForNumber(Builder $query, string $number): Builder
    {
        return $query->where('number', $number);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, BetType $type): Builder
    {
        return $query->where('bet_type', $type);
    }
}
