<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DrawStatus;
use App\Enums\DrawType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single lottery draw.
 *
 * The draw is the scheduling and settlement boundary of the whole system: bets
 * belong to a draw, betting opens and closes on its timestamps, and results,
 * winning numbers, payouts and number limits are all scoped to it. The counters
 * (total_bets, total_amount_wagered, total_payout, house_profit) are cached
 * aggregates written only by the draw and settlement services.
 *
 * No draw processing, number drawing or settlement logic lives here.
 *
 * @property int $id
 * @property string $draw_number
 * @property DrawType $type
 * @property DrawStatus $status
 * @property \Illuminate\Support\Carbon $scheduled_at
 * @property \Illuminate\Support\Carbon|null $opened_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 * @property \Illuminate\Support\Carbon|null $drawn_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property int $total_bets
 * @property string $total_amount_wagered
 * @property string $total_payout
 * @property string $house_profit
 * @property array<string, mixed>|null $metadata
 *
 * The authoritative winning numbers of a draw live in the winning_numbers table
 * and are reached through the winningNumbers() relationship. The draws table has
 * no winning_numbers column and this model therefore declares no cast for one.
 */
class Draw extends Model
{
    /** @use HasFactory<\Database\Factories\DrawFactory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Scheduling fields only.
     *
     * Lifecycle timestamps, the status and every money counter are excluded:
     * they are advanced by the draw service as the draw moves through its
     * states, never by mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'draw_number',
        'type',
        'scheduled_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DrawType::class,
            'status' => DrawStatus::class,
            'scheduled_at' => 'datetime',
            'betting_open_at' => 'datetime',
            'betting_close_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'drawn_at' => 'datetime',
            'completed_at' => 'datetime',
            'result_published_at' => 'datetime',
            'total_bets' => 'integer',
            'total_amount_wagered' => 'decimal:2',
            'total_payout' => 'decimal:2',
            'house_profit' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<Bet>
     */
    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    /**
     * @return HasMany<Ticket>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * The published official result of the draw (one row per draw).
     *
     * @return HasOne<DrawResult>
     */
    public function result(): HasOne
    {
        return $this->hasOne(DrawResult::class);
    }

    /**
     * @return HasMany<WinningNumber>
     */
    public function winningNumbers(): HasMany
    {
        return $this->hasMany(WinningNumber::class);
    }

    /**
     * @return HasMany<Payout>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * @return HasMany<NumberLimit>
     */
    public function numberLimits(): HasMany
    {
        return $this->hasMany(NumberLimit::class);
    }

    public function isOpen(): bool
    {
        return $this->status === DrawStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === DrawStatus::Closed;
    }

    public function isCompleted(): bool
    {
        return $this->status === DrawStatus::Completed;
    }

    public function canAcceptBets(): bool
    {
        return $this->status->canAcceptBets();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function hasResult(): bool
    {
        return $this->drawn_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', DrawStatus::Open);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', DrawStatus::Scheduled);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', DrawStatus::Completed);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, DrawType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByDrawNumber(Builder $query, string $drawNumber): Builder
    {
        return $query->where('draw_number', $drawNumber);
    }
}
