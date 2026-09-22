<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BetAmendmentStatus;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The recorded request to replace one bet with another.
 *
 * A REPORT ROW, NOT A MONEY ROW
 * This table never holds money movement: the refund of the original bet went
 * through FinancialTransactionService (BetRefund) and the charge of the
 * replacement went through BetPurchaseService (BetDebit). This row links the two
 * and records the deltas and the terminal status, so an operator can always
 * answer "what happened to this amendment" without re-deriving it from the
 * ledger.
 *
 * IMMUTABLE AFTER TERMINAL STATUS
 * Once status is applied/failed/expired the row is a historical record and the
 * service layer never rewrites it except to stamp failure_reason on Failed.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $bet_id
 * @property int|null $replacement_bet_id
 * @property BetAmendmentStatus $status
 * @property Currency $currency
 * @property string $old_number
 * @property string $old_stake
 * @property string|null $new_number
 * @property string|null $new_stake
 * @property string $refunded_amount
 * @property string|null $charged_amount
 * @property string|null $failure_reason
 * @property string|null $idempotency_key
 * @property Carbon|null $applied_at
 * @property Carbon|null $expires_at
 * @property array<string, mixed>|null $metadata
 */
class BetAmendment extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'user_id',
        'bet_id',
        'replacement_bet_id',
        'status',
        'currency',
        'old_number',
        'old_stake',
        'new_number',
        'new_stake',
        'refunded_amount',
        'charged_amount',
        'failure_reason',
        'idempotency_key',
        'applied_at',
        'expires_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BetAmendmentStatus::class,
            'currency' => Currency::class,
            'old_stake' => 'decimal:2',
            'new_stake' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'charged_amount' => 'decimal:2',
            'applied_at' => 'datetime',
            'expires_at' => 'datetime',
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
     * The original bet this amendment replaced.
     *
     * @return BelongsTo<Bet, self>
     */
    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    /**
     * The bet created in place of the original, when the amendment applied.
     *
     * @return BelongsTo<Bet, self>
     */
    public function replacementBet(): BelongsTo
    {
        return $this->belongsTo(Bet::class, 'replacement_bet_id');
    }

    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
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
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', BetAmendmentStatus::Pending);
    }

    /**
     * Open amendments whose expiry moment has passed — the sweeper's pick list.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiredPending(Builder $query): Builder
    {
        return $query->pending()->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }
}
