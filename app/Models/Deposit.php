<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\DepositStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A request to add money to a wallet.
 *
 * The deposit is the user-facing record; the gateway conversation lives in the
 * related payment row, and the balance change happens only through the linked
 * financial transaction and its ledger entries.
 *
 * net_amount is the amount that reaches the wallet after the fee. It is stored,
 * not derived on the fly, because the fee that applied at request time must stay
 * auditable even if the fee configuration changes later.
 *
 * This model calls no payment provider and handles no callback.
 *
 * @property int $id
 * @property string $reference_number
 * @property int $user_id
 * @property int $wallet_id
 * @property int|null $financial_transaction_id
 * @property PaymentMethod $method
 * @property string|null $provider
 * @property string|null $provider_reference
 * @property string|null $idempotency_key
 * @property DepositStatus $status
 * @property Currency $currency
 * @property string $amount
 * @property string $fee
 * @property string $net_amount
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $metadata
 */
class Deposit extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Request-time fields only.
     *
     * status, the lifecycle timestamps, the failure reason and the financial
     * transaction link are written by the deposit service when the gateway
     * result is processed, so they are excluded from mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'reference_number',
        'user_id',
        'wallet_id',
        'method',
        'provider',
        'provider_reference',
        'idempotency_key',
        'currency',
        'amount',
        'fee',
        'net_amount',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => DepositStatus::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'failed_at' => 'datetime',
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
     * The gateway attempt behind this deposit.
     *
     * @return MorphOne<Payment>
     */
    public function payment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable');
    }

    /**
     * All gateway attempts, including earlier failed ones.
     *
     * @return MorphMany<Payment>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Ledger entries that reference this deposit polymorphically.
     *
     * @return MorphMany<LedgerEntry>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'reference', 'reference_type', 'reference_id');
    }

    public function isConfirmed(): bool
    {
        return $this->status === DepositStatus::Confirmed;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isCredited(): bool
    {
        return $this->financial_transaction_id !== null && $this->confirmed_at !== null;
    }

    public function isManual(): bool
    {
        return $this->method === PaymentMethod::Manual;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', DepositStatus::Confirmed);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DepositStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfMethod(Builder $query, PaymentMethod $method): Builder
    {
        return $query->where('method', $method);
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
