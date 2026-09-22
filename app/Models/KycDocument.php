<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycDocumentType;
use App\Enums\KycStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * KYC Verification Document Model.
 *
 * @property int $id
 * @property int $user_id
 * @property KycDocumentType $document_type
 * @property string|null $document_number
 * @property string $file_path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $file_size
 * @property KycStatus $status
 * @property string|null $rejection_reason
 * @property Carbon|null $verified_at
 * @property int|null $verified_by
 * @property array<string, mixed>|null $metadata
 */
class KycDocument extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'document_type',
        'document_number',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'status',
        'rejection_reason',
        'verified_at',
        'verified_by',
        'metadata',
        // Batch-13 authoritative lane (additive columns).
        'issuer_country',
        'document_fingerprint',
        'verification_reference',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => KycDocumentType::class,
            'status' => KycStatus::class,
            'file_size' => 'integer',
            'verified_at' => 'datetime',
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
     * @return BelongsTo<User, self>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function isVerified(): bool
    {
        return $this->status === KycStatus::Verified;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', KycStatus::Pending);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', KycStatus::Verified);
    }
}
