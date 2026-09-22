<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PaymentMethod;
use App\Enums\WithdrawalStatus;
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
 * A request to take money out of a wallet.
 *
 * The payout destination (bank account, wallet number, crypto address) is stored
 * in payout_details, which is encrypted at rest and hidden from serialization so
 * it never leaks into an API response, a log line or an audit payload.
 *
 * The request carries its own review trail (reviewed_by, reviewed_at, approved_at,
 * rejected_at) so a four-eyes approval flow can be audited. Holding funds,
 * approving, sending and completing the withdrawal are the job of the withdrawal
 * service; this model only describes the request.
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
 * @property WithdrawalStatus $status
 * @property Currency $currency
 * @property string $amount
 * @property string $fee
 * @property string $net_amount
 * @property array<string, mixed>|null $payout_details
 * @property int|null $reviewed_by
 * @property Carbon|null $requested_at
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $approved_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property array<string, mixed>|null $metadata
 */
class Withdrawal extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Request-time fields only.
     *
     * The status, the whole review trail and the financial transaction link are
     * written by the withdrawal service and by administrators through explicit
     * actions, never through mass assignment: an approval must never be settable
     * from request input.
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
        'payout_details',
        'metadata',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'payout_details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => PaymentMethod::class,
            'status' => WithdrawalStatus::class,
            'currency' => Currency::class,
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'payout_details' => 'encrypted:array',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'rejected_at' => 'datetime',
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
     * The administrator who reviewed the request.
     *
     * @return BelongsTo<User, self>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * The gateway attempt that sends the money out.
     *
     * @return MorphOne<Payment>
     */
    public function payment(): MorphOne
    {
        return $this->morphOne(Payment::class, 'payable');
    }

    /**
     * @return MorphMany<Payment>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * Ledger entries that reference this withdrawal polymorphically.
     *
     * @return MorphMany<LedgerEntry>
     */
    public function ledgerEntries(): MorphMany
    {
        return $this->morphMany(LedgerEntry::class, 'reference', 'reference_type', 'reference_id');
    }

    public function isCompleted(): bool
    {
        return $this->status === WithdrawalStatus::Completed;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function isRejected(): bool
    {
        return $this->rejected_at !== null;
    }

    public function awaitsReview(): bool
    {
        return $this->reviewed_at === null && ! $this->status->isFinal();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePendingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [
            WithdrawalStatus::Pending,
            WithdrawalStatus::UnderReview,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', WithdrawalStatus::Completed);
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
