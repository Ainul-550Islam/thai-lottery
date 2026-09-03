<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\LedgerAccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Chart-of-accounts entry for the double-entry ledger.
 *
 * Accounts form a tree (system cash, deposits, withdrawals, prize liability,
 * fees, commissions, player liability, and so on). current_balance is a cached
 * roll-up that only the ledger service may write, always together with the
 * entries that justify it.
 *
 * No posting, balancing or roll-up arithmetic lives in this class.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property LedgerAccountType $type
 * @property Currency $currency
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $parent_account_id
 * @property string $opening_balance
 * @property string $current_balance
 * @property array<string, mixed>|null $metadata
 */
class LedgerAccount extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Account definition fields only.
     *
     * opening_balance and current_balance are ledger values and are therefore
     * not mass assignable; they are set by the ledger service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'type',
        'currency',
        'description',
        'is_active',
        'parent_account_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerAccountType::class,
            'currency' => Currency::class,
            'is_active' => 'boolean',
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    /**
     * All entries posted against this account.
     *
     * @return HasMany<LedgerEntry>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_account_id');
    }

    /**
     * Alias kept for readability at call sites that speak in ledger terms.
     *
     * @return HasMany<LedgerEntry>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'ledger_account_id');
    }

    /**
     * @return BelongsTo<self, self>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    /**
     * @return HasMany<self>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function isRoot(): bool
    {
        return $this->parent_account_id === null;
    }

    /**
     * Whether a debit increases this account, following standard accounting
     * normal balances.
     */
    public function isDebitNormal(): bool
    {
        return in_array($this->type, [
            LedgerAccountType::Asset,
            LedgerAccountType::Expense,
        ], true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, LedgerAccountType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfCode(Builder $query, string $code): Builder
    {
        return $query->where('code', $code);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_account_id');
    }
}
