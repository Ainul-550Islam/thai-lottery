<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Currency;
use App\Enums\PayoutDocumentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The statement paper for ONE completed payout.
 *
 * Doctrine lives in the migration: identity derived, money decimal, one
 * statement per payout, lifecycle stamps on the row. Statements NEVER
 * mutate money — this model reads the ledger's math, never writes it.
 *
 * @property int $id
 * @property string $document_key
 * @property string $statement_number
 * @property int $payout_id
 * @property int $claimant_user_id
 * @property string $payout_reference
 * @property string|null $claim_reference
 * @property string|null $batch_reference
 * @property string $gross_amount
 * @property string $tax_amount
 * @property string $net_amount
 * @property Currency $currency
 * @property PayoutDocumentStatus $status
 * @property Carbon $completed_at
 * @property Carbon|null $generated_at
 * @property Carbon|null $issued_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $archived_at
 * @property array<string, mixed>|null $metadata
 */
class PayoutDocument extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'payout_documents';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'document_key',
        'statement_number',
        'payout_id',
        'claimant_user_id',
        'payout_reference',
        'claim_reference',
        'batch_reference',
        'gross_amount',
        'tax_amount',
        'net_amount',
        'currency',
        'status',
        'completed_at',
        'generated_at',
        'issued_at',
        'cancelled_at',
        'archived_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'status' => PayoutDocumentStatus::class,
            'gross_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'completed_at' => 'datetime',
            'generated_at' => 'datetime',
            'issued_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'archived_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Payout, self>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    /**
     * The claimant the statement was issued to.
     *
     * @return BelongsTo<User, self>
     */
    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_user_id');
    }
}
