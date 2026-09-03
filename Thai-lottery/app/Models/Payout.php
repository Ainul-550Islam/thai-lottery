<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PayoutStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A prize payment owed to a user for a winning bet in a draw.
 *
 * The payout is the record of the obligation; the money reaches the wallet only
 * through the linked financial transaction and its balanced ledger entries. This
 * model never credits a wallet and never computes a prize: the payout service
 * does both inside a database transaction.
 *
 * @property int $id
 * @property string $reference_number
 * @property int $draw_id
 * @property int|null $bet_id
 * @property int $user_id
 * @property int|null $wallet_id
 * @property int|null $financial_transaction_id
 * @property PayoutStatus $status
 * @property Currency $currency
 * @property string $amount
 * @property string|null $multiplier
 * @property int|null $ticket_id
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property \Illuminate\Support\Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 */
class Payout extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Creation-time fields only.
     *
     * status, the lifecycle timestamps, the failure reason and the link to the
     * financial transaction are written by the payout service as the payment
     * progresses, so they are not mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference_number',
        'draw_id',
        'bet_id',
        'user_id',
        'wallet_id',
        'ticket_id',
        'currency',
        'amount',
        'multiplier',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'multiplier' => 'decimal:4',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
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
     * The winning bet this payout settles.
     *
     * @return BelongsTo<Bet, self>
     */
    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    /**
     * The ticket this payout belongs to.
     *
     * The payouts table carries its own nullable ticket_id foreign key, so the
     * link is direct and does not have to be resolved through the bet.
     *
     * @return BelongsTo<Ticket, self>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Wallet, self>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return BelongsTo<FinancialTransaction, self>
     */
    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class);
    }

    /**
     * Ledger entries that reference this payout polymorphically.
     *
     * @return MorphMany<LedgerEntry>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'reference', 'reference_type', 'reference_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === PayoutStatus::Completed;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isFailed(): bool
    {
        return $this->failed_at !== null;
    }

    public function isCredited(): bool
    {
        return $this->financial_transaction_id !== null && $this->processed_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', PayoutStatus::Completed);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PayoutStatus::Pending);
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
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByReferenceNumber(Builder $query, string $reference): Builder
    {
        return $query->where('reference_number', $reference);
    }
}
