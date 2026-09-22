<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Financial transaction aggregate.
 *
 * One row represents one atomic money movement request (deposit, withdrawal,
 * bet placement, payout, refund, commission, adjustment). It owns the ledger
 * entries that implement it, is guarded by a unique idempotency key, and is
 * identified externally by reference_number.
 *
 * A completed transaction is never rewritten: it is corrected by a reversal that
 * records reversed_by and reversed_at and posts opposite ledger entries. All of
 * that is performed by the transaction service, not here.
 *
 * @property int $id
 * @property string $reference_number
 * @property int|null $user_id
 * @property int|null $wallet_id
 * @property TransactionType $type
 * @property TransactionStatus $status
 * @property Currency $currency
 * @property string $amount
 * @property string $fee
 * @property string|null $description
 * @property array<string, mixed>|null $metadata
 * @property string|null $idempotency_key
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $reversed_by
 * @property Carbon|null $reversed_at
 * @property Carbon|null $processed_at
 * @property-read string $net_amount
 */
class FinancialTransaction extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Creation-time fields only.
     *
     * status, processed_at, reversed_by and reversed_at describe the lifecycle
     * and are excluded on purpose: a status transition must go through the
     * transaction service, never through mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference_number',
        'user_id',
        'wallet_id',
        'type',
        'currency',
        'amount',
        'fee',
        'description',
        'metadata',
        'idempotency_key',
        'reference_type',
        'reference_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'status' => TransactionStatus::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'metadata' => 'array',
            'reference_id' => 'integer',
            'reversed_at' => 'datetime',
            'processed_at' => 'datetime',
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
     * @return BelongsTo<Wallet, self>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * @return HasMany<LedgerEntry>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The domain record that caused this transaction (bet, payout, deposit, ...).
     *
     * The table stores reference_type / reference_id, so the link is a plain
     * polymorphic relation rather than a hard foreign key.
     *
     * @return MorphTo<Model, self>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @return HasMany<Deposit>
     */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class, 'financial_transaction_id');
    }

    /**
     * @return HasMany<Withdrawal>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class, 'financial_transaction_id');
    }

    /**
     * @return HasMany<Payout>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'financial_transaction_id');
    }

    /**
     * Amount actually moved after the fee, as an exact decimal string.
     *
     * @return Attribute<string, never>
     */
    protected function netAmount(): Attribute
    {
        return Attribute::get(fn (): string => bcsub(
            (string) $this->amount,
            (string) $this->fee,
            2
        ))->shouldCache();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isCompleted(): bool
    {
        return $this->status === TransactionStatus::Completed;
    }

    public function isReversed(): bool
    {
        return $this->status === TransactionStatus::Reversed;
    }

    public function canReverse(): bool
    {
        return $this->status->canReverse();
    }

    public function canCancel(): bool
    {
        return $this->status->canCancel();
    }

    public function canTransitionTo(TransactionStatus $status): bool
    {
        return $this->status->canTransitionTo($status);
    }

    /**
     * Whether the debits and credits recorded for this transaction cancel out.
     * A balanced transaction sums to exactly zero once each entry is signed.
     */
    public function isBalanced(): bool
    {
        $sum = '0.00';

        foreach ($this->ledgerEntries as $entry) {
            $sum = bcadd($sum, $entry->signedAmount(), 2);
        }

        return bccomp($sum, '0.00', 2) === 0;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Completed);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', TransactionStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, TransactionType $type): Builder
    {
        return $query->where('type', $type);
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
    public function scopeForWallet(Builder $query, int $walletId): Builder
    {
        return $query->where('wallet_id', $walletId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByIdempotencyKey(Builder $query, string $key): Builder
    {
        return $query->where('idempotency_key', $key);
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
