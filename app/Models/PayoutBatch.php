<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PayoutBatchStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A manufactured group of approved payout obligations executed in one run.
 *
 * See the migration for the full doctrine: identity via deterministic
 * batch_key (sha256 of sorted member references + currency), claiming via
 * one-row atomic Pending→Processing flip, membership projected from the
 * member payouts' `metadata.batch` stamps rather than stored here, money
 * as DECIMAL strings, and status as the PayoutBatchStatus enum's value —
 * never trusted as a stamped string, always re-derived through the enum.
 *
 * @property int $id
 * @property string $batch_key
 * @property PayoutBatchStatus $status
 * @property Currency $currency
 * @property int $member_count
 * @property int $processed_count
 * @property int $paid_count
 * @property int $failed_count
 * @property int $replayed_count
 * @property string $aggregate_amount
 * @property string $paid_amount
 * @property Carbon|null $claimed_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $failure_reason
 * @property string|null $note
 * @property array<string, mixed>|null $metadata
 */
class PayoutBatch extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'payout_batches';

    /**
     * The mutation surface: everything a batch's lifecycle legitimately
     * writes. batch_key is excluded — identity is immutable once created
     * (never re-write the batch's own name).
     *
     * @var list<string>
     */
    protected $fillable = [
        'batch_key',
        'status',
        'currency',
        'member_count',
        'processed_count',
        'paid_count',
        'failed_count',
        'replayed_count',
        'aggregate_amount',
        'paid_amount',
        'claimed_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'failure_reason',
        'note',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayoutBatchStatus::class,
            'currency' => Currency::class,
            'member_count' => 'integer',
            'processed_count' => 'integer',
            'paid_count' => 'integer',
            'failed_count' => 'integer',
            'replayed_count' => 'integer',
            'aggregate_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'claimed_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * The batch's per-member stamps: the payouts that name this batch in
     * their own metadata.batch.batch_key lane. Query-level relation
     * (metadata JSON), not a foreign key — membership is projected.
     *
     * @return Collection<int, Payout>
     */
    public function memberPayouts(): Collection
    {
        return Payout::query()
            ->whereNull('deleted_at')
            ->whereRaw("json_extract(metadata, '$.batch.batch_key') = ?", [$this->batch_key])
            ->orderBy('id')
            ->get();
    }

    /**
     * The member reference list for aggregate/identity re-verification.
     *
     * @return list<string>
     */
    public function memberReferences(): array
    {
        return $this->memberPayouts()
            ->map(static fn (Payout $payout): string => (string) $payout->reference_number)
            ->all();
    }

    /**
     * Is the batch still claimable by an executor?
     */
    public function isClaimable(): bool
    {
        return $this->status === PayoutBatchStatus::Pending
            || $this->status === PayoutBatchStatus::Failed;
    }
}
