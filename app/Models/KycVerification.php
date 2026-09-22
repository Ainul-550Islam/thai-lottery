<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycVerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One server-authoritative KYC decision evidence row. `status` moves
 * only through KycVerificationStatus transitions (property-set after
 * checks — never mass-assignable).
 */
class KycVerification extends Model
{
    protected $fillable = [
        'verification_reference',
        'user_id',
        'document_set_fingerprint',
        'reviewer_id',
        'source',
        'rejection_reason',
        'decided_at',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'status' => KycVerificationStatus::class,
        'decided_at' => 'datetime',
        'expires_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
