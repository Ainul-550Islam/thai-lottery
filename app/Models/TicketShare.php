<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketShareStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A revocable bearer link that exposes a ticket's coarse summary to anyone
 * holding the token.
 *
 * TOKEN STORAGE RULE
 * Only token_hash (SHA-256 of the raw bearer token) is stored. The raw token is
 * shown to the owner once at creation and cannot be recovered from this row.
 * A full-table read leaks nothing usable.
 *
 * EXPIRY IS COMPUTE-ON-READ
 * effectiveStatus() compares expires_at to now() at read time, so a lapsed link
 * stops working even if no sweeper ever runs. The stored status column only
 * records owner/operator intent (active / revoked).
 *
 * @property int $id
 * @property string $uuid
 * @property int $ticket_id
 * @property int $user_id
 * @property string $token_hash
 * @property TicketShareStatus $status
 * @property int $views
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property array<string, mixed>|null $metadata
 */
class TicketShare extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'ticket_id',
        'user_id',
        'token_hash',
        'status',
        'views',
        'expires_at',
        'revoked_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketShareStatus::class,
            'views' => 'integer',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Ticket, self>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * The owner who created the share.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The status as of right now, factoring elapsed time.
     */
    public function effectiveStatus(): TicketShareStatus
    {
        if ($this->status !== TicketShareStatus::Active) {
            return $this->status;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return TicketShareStatus::Expired;
        }

        return TicketShareStatus::Active;
    }

    /**
     * Whether the link resolves right now.
     */
    public function isUsable(): bool
    {
        return $this->effectiveStatus() === TicketShareStatus::Active;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', TicketShareStatus::Active);
    }

    /**
     * Stored-active rows past their expiry moment — the sweeper's pick list for
     * backfilling the stored status.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeExpiredActive(Builder $query): Builder
    {
        return $query->active()->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }
}
