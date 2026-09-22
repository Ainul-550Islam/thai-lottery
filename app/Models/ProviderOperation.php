<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProviderOperationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Immutable ledger of provider operational-seat changes.
 * The CURRENT seat for a provider = the latest effective_at row.
 * Rows never edit; a mistaken seat is corrected by a further seat.
 *
 * @property int $id
 * @property string $change_fingerprint
 * @property string $provider
 * @property ProviderOperationStatus $status
 * @property string|null $evidence_fingerprint
 * @property int $changed_by_user_id
 * @property string|null $note
 * @property Carbon $effective_at
 */
class ProviderOperation extends Model
{
    protected $fillable = [
        'change_fingerprint', 'provider', 'status', 'evidence_fingerprint',
        'changed_by_user_id', 'note', 'effective_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProviderOperationStatus::class,
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
