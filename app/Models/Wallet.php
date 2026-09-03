<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * User wallet.
 *
 * A user has exactly one wallet per (type, currency) pair; the primary THB
 * wallet is the spendable one. The stored balance is the authoritative cached
 * total, and every change to it must be produced by the wallet service inside a
 * database transaction together with balanced ledger entries.
 *
 * This model never mutates money. It only reads state and answers questions
 * about that state using bcmath string arithmetic, so no float ever touches a
 * balance.
 *
 * @property int $id
 * @property int $user_id
 * @property WalletType $type
 * @property WalletStatus $status
 * @property Currency $currency
 * @property string $balance
 * @property string $locked_balance
 * @property string $total_deposited
 * @property string $total_withdrawn
 * @property string $total_wagered
 * @property string $total_won
 * @property int $version
 * @property \Illuminate\Support\Carbon|null $locked_at
 * @property string|null $locked_reason
 * @property-read string $available_balance
 */
class Wallet extends Model
{
    /** @use HasFactory<\Database\Factories\WalletFactory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Only the wallet identity is mass assignable.
     *
     * Balances, running totals, the status and the lock fields are financial
     * state: they are written exclusively by the wallet service through explicit
     * assignment inside a locked transaction, never from request input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WalletType::class,
            'status' => WalletStatus::class,
            'currency' => Currency::class,
            'balance' => 'decimal:2',
            'locked_balance' => 'decimal:2',
            'total_deposited' => 'decimal:2',
            'total_withdrawn' => 'decimal:2',
            'total_wagered' => 'decimal:2',
            'total_won' => 'decimal:2',
            'version' => 'integer',
            'locked_at' => 'datetime',
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
     * @return HasMany<LedgerEntry>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'wallet_id');
    }

    /**
     * @return HasMany<FinancialTransaction>
     */
    public function financialTransactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class, 'wallet_id');
    }

    /**
     * @return HasMany<Deposit>
     */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class, 'wallet_id');
    }

    /**
     * @return HasMany<Withdrawal>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class, 'wallet_id');
    }

    /**
     * @return HasMany<Payout>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class, 'wallet_id');
    }

    /**
     * Spendable balance: total balance minus the amount held by open holds.
     *
     * Derived at read time with bcmath, because the schema stores only
     * balance and locked_balance.
     *
     * @return Attribute<string, never>
     */
    protected function availableBalance(): Attribute
    {
        return Attribute::get(fn (): string => bcsub(
            (string) $this->balance,
            (string) $this->locked_balance,
            2
        ))->shouldCache();
    }

    /**
     * Kept as a method as well, because existing application code calls it.
     */
    public function getAvailableBalance(): string
    {
        return bcsub((string) $this->balance, (string) $this->locked_balance, 2);
    }

    public function canTransact(): bool
    {
        return $this->status->canTransact();
    }

    public function canCredit(): bool
    {
        return $this->status->canCredit();
    }

    public function canDebit(): bool
    {
        return $this->status->canDebit();
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function hasSufficientBalance(string $amount): bool
    {
        return bccomp($this->getAvailableBalance(), $amount, 2) >= 0;
    }

    public function isPrimary(): bool
    {
        return $this->type === WalletType::Primary;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', WalletStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, WalletType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfCurrency(Builder $query, Currency $currency): Builder
    {
        return $query->where('currency', $currency);
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
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('type', WalletType::Primary);
    }
}
