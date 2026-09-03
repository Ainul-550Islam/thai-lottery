<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\LedgerEntryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single line of the double-entry ledger.
 *
 * The schema stores one signed side per row: `type` says whether the row is a
 * debit or a credit and `amount` carries the always-positive magnitude. Every
 * financial transaction must produce at least one debit row and one credit row
 * whose amounts sum to the same value.
 *
 * Entries are treated as historical records: once posted they are never edited
 * and never deleted. A mistake is corrected by posting a compensating entry, so
 * this class exposes read-only helpers and no mutators. Enforcement of that rule
 * belongs to the ledger service, which is the only writer.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property int $financial_transaction_id
 * @property int|null $wallet_id
 * @property LedgerEntryType $type
 * @property string $amount
 * @property Currency $currency
 * @property string|null $balance_after
 * @property string|null $description
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $posted_at
 * @property-read string $debit
 * @property-read string $credit
 */
class LedgerEntry extends Model
{
    /** @use HasFactory<\Illuminate\Database\Eloquent\Factories\Factory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * Populated once, at posting time, by the ledger service.
     *
     * The list is explicit so that no request payload can ever reach a ledger
     * value; balance_after is excluded because only the wallet/ledger service
     * knows the balance that followed the entry.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ledger_account_id',
        'financial_transaction_id',
        'wallet_id',
        'type',
        'amount',
        'currency',
        'description',
        'reference_type',
        'reference_id',
        'metadata',
        'posted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'reference_id' => 'integer',
            'metadata' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LedgerAccount, self>
     */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }

    /**
     * @return BelongsTo<FinancialTransaction, self>
     */
    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class);
    }

    /**
     * @return BelongsTo<Wallet, self>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * The domain object that caused this entry (bet, payout, deposit, ...).
     *
     * @return MorphTo<Model, self>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'reference_type', 'reference_id');
    }

    /**
     * Debit column view of the row: the amount when this row is a debit,
     * otherwise zero. Provided so reports can render classic two-column
     * ledgers without the schema storing two columns.
     *
     * @return Attribute<string, never>
     */
    protected function debit(): Attribute
    {
        return Attribute::get(fn (): string => $this->isDebit()
            ? bcadd((string) $this->amount, '0', 2)
            : '0.00');
    }

    /**
     * Credit column view of the row.
     *
     * @return Attribute<string, never>
     */
    protected function credit(): Attribute
    {
        return Attribute::get(fn (): string => $this->isCredit()
            ? bcadd((string) $this->amount, '0', 2)
            : '0.00');
    }

    public function isDebit(): bool
    {
        return $this->type === LedgerEntryType::Debit;
    }

    public function isCredit(): bool
    {
        return $this->type === LedgerEntryType::Credit;
    }

    public function isPosted(): bool
    {
        return $this->posted_at !== null;
    }

    /**
     * Amount signed by entry type, so a set of entries can be summed directly.
     */
    public function signedAmount(): string
    {
        return $this->isDebit()
            ? bcmul((string) $this->amount, '-1', 2)
            : bcadd((string) $this->amount, '0', 2);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDebits(Builder $query): Builder
    {
        return $query->where('type', LedgerEntryType::Debit);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('type', LedgerEntryType::Credit);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->whereNotNull('posted_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAccount(Builder $query, int $ledgerAccountId): Builder
    {
        return $query->where('ledger_account_id', $ledgerAccountId);
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
    public function scopeForReference(Builder $query, string $type, int $id): Builder
    {
        return $query->where('reference_type', $type)->where('reference_id', $id);
    }
}
