<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\KycStatus;
use App\Enums\UserStatus;
use App\Enums\WalletType;
use App\Support\Admin\AdminAccess;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Application user (player, agent or administrator).
 *
 * The user aggregate owns wallets, bets, tickets and payment requests, but it
 * never performs financial work itself: no balance reads, no balance mutation
 * and no money arithmetic live in this class.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $username
 * @property string|null $phone
 * @property string $password
 * @property UserStatus $status
 * @property string|null $avatar_url
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property array<string, mixed>|null $preferences
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens;

    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    /**
     * Profile-level attributes only.
     *
     * Verification timestamps, login telemetry and the account status are
     * deliberately excluded: they are set by the authentication, verification
     * and moderation flows through explicit assignment, never by user input.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'username',
        'phone',
        'password',
        'avatar_url',
        'preferences',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    /**
     * The primary spendable wallet of the user.
     *
     * @return HasOne<Wallet>
     */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class)
            ->where('type', WalletType::Primary->value);
    }

    /**
     * @return HasMany<Wallet>
     */
    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    /**
     * @return HasMany<Bet>
     */
    public function bets(): HasMany
    {
        return $this->hasMany(Bet::class);
    }

    /**
     * @return HasMany<Ticket>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<Payout>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }

    /**
     * @return HasMany<Deposit>
     */
    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    /**
     * @return HasMany<Withdrawal>
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }

    /**
     * @return HasMany<FinancialTransaction>
     */
    public function financialTransactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    /**
     * @return HasOne<Agent>
     */
    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class);
    }

    /**
     * Every KYC document the player has ever submitted, in any status.
     *
     * @return HasMany<KycDocument>
     */
    public function kycDocuments(): HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    /**
     * @return HasMany<AuditLog>
     */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * May this user open a Filament panel?
     *
     * Filament calls this on every panel request. The decision itself lives in
     * App\Support\Admin\AdminAccess so that the panel's authorization model is readable
     * in one place; the model only forwards.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && AdminAccess::canAccessPanel($this);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * The player's responsible-gaming record (deposit / wager / single-bet
     * limits and self-exclusion window), when one has ever been set.
     *
     * @return HasOne<ResponsibleGamingLimit, self>
     */
    public function responsibleGamingLimit(): HasOne
    {
        return $this->hasOne(ResponsibleGamingLimit::class);
    }

    /**
     * Whether the player is currently inside a self-exclusion window. The
     * window is authoritative when its `self_excluded_until` timestamp is in
     * the future; a past timestamp means the exclusion lapsed and the player
     * is clear again.
     */
    public function isSelfExcluded(): bool
    {
        $until = $this->responsibleGamingLimit?->self_excluded_until;

        return $until !== null && $until->isFuture();
    }

    /**
     * Whether the player may move money right now. Both gates must pass:
     * account status is Active AND the player is not self-excluded. A
     * suspended or closed account, or an Active account mid self-exclusion,
     * is equally barred from depositing, withdrawing, and betting.
     */
    public function canTransact(): bool
    {
        return $this->isActive() && ! $this->isSelfExcluded();
    }

    /**
     * The player's aggregate KYC standing, derived from the submitted document
     * set rather than stored anywhere: a verified identity document is the
     * only thing that can make it Verified (any single approval passes the
     * player even if earlier submissions were rejected), otherwise the most
     * recent document's state governs (Pending/UnderReview/Rejected/Expired),
     * and a player with no submissions at all is Unverified.
     */
    public function kycStatus(): KycStatus
    {
        /** @var Collection<int, KycDocument> $documents */
        $documents = $this->kycDocuments()->get();

        if ($documents->isEmpty()) {
            return KycStatus::Unverified;
        }

        $verified = $documents->contains(
            static fn (KycDocument $document): bool => $document->status === KycStatus::Verified,
        );

        if ($verified) {
            return KycStatus::Verified;
        }

        /** @var KycDocument|null $latest */
        $latest = $documents->sortByDesc(static fn (KycDocument $document): int => (int) $document->id)->first();

        if ($latest !== null && $latest->status instanceof KycStatus) {
            return $latest->status;
        }

        return KycStatus::Unverified;
    }

    public function isAgent(): bool
    {
        return $this->hasRole('agent');
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(['admin', 'super-admin']);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super-admin');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Suspended);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfStatus(Builder $query, UserStatus $status): Builder
    {
        return $query->where('status', $status);
    }
}
