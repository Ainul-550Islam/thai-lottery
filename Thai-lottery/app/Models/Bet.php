<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetStatus;
use App\Enums\BetType;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Betting aggregate: one bet of one type placed by one user on one draw.
 *
 * The individual selected numbers live in bet_items; this row carries the
 * type, the total stake, the currency and the settlement outcome. Every money
 * field is an exact decimal string.
 *
 * Winning detection, payout calculation and wallet deduction are the job of the
 * betting and settlement services. This class only describes the bet.
 *
 * @property int $id
 * @property string $bet_number
 * @property int $user_id
 * @property int $draw_id
 * @property int|null $ticket_id
 * @property int|null $payout_id
 * @property BetType $type
 * @property BetStatus $status
 * @property Currency $currency
 * @property string $stake_amount
 * @property string $potential_payout
 * @property string $actual_payout
 * @property int $total_numbers
 * @property string|null $idempotency_key
 * @property \Illuminate\Support\Carbon|null $placed_at
 * @property \Illuminate\Support\Carbon|null $won_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancelled_reason
 * @property array<string, mixed>|null $metadata
 */
class Bet extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Placement-time fields only.
     *
     * status, payout_id, actual_payout, won_at and the cancellation fields are
     * settlement results: they are written by the settlement service after the
     * draw, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'bet_number',
        'user_id',
        'draw_id',
        'ticket_id',
        'type',
        'currency',
        'stake_amount',
        'potential_payout',
        'total_numbers',
        'idempotency_key',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BetType::class,
            'status' => BetStatus::class,
            'currency' => Currency::class,
            'stake_amount' => 'decimal:2',
            'potential_payout' => 'decimal:2',
            'actual_payout' => 'decimal:2',
            'total_numbers' => 'integer',
            'placed_at' => 'datetime',
            'won_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    /**
     * @return BelongsTo<Draw, self>
     */
    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    /**
     * @return BelongsTo<Ticket, self>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * The selected numbers of this bet.
     *
     * @return HasMany<BetItem>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BetItem::class);
    }

    /**
     * Alias of items(), named after the table for call sites that prefer it.
     *
     * @return HasMany<BetItem>
     */
    public function betItems(): HasMany
    {
        return $this->hasMany(BetItem::class);
    }

    /**
     * @return BelongsTo<Payout, self>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    /**
     * Ledger entries posted with this bet as their reference. The bets table has
     * no financial_transaction_id column, so the link to the money movement runs
     * through the polymorphic reference of the ledger entries.
     *
     * @return MorphMany<LedgerEntry>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'reference', 'reference_type', 'reference_id');
    }

    public function isWinning(): bool
    {
        return $this->status === BetStatus::Won;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function canCancel(): bool
    {
        return $this->status->canCancel();
    }

    public function canRefund(): bool
    {
        return $this->status->canRefund();
    }

    public function isSettled(): bool
    {
        return $this->status->isFinal() && $this->payout_id !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', BetStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWon(Builder $query): Builder
    {
        return $query->where('status', BetStatus::Won);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLost(Builder $query): Builder
    {
        return $query->where('status', BetStatus::Lost);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
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
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByBetNumber(Builder $query, string $betNumber): Builder
    {
        return $query->where('bet_number', $betNumber);
    }
}
