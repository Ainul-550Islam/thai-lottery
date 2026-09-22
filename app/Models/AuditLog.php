<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Services\Observability\CorrelationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only record of a security-sensitive or money-sensitive action.
 *
 * The table has only created_at, so UPDATED_AT is disabled: a log line is written
 * once and never updated, and the model uses no soft deletes because audit
 * history must not be removable through the ORM.
 *
 * Passwords, tokens, API keys, webhook secrets, card data and withdrawal payout
 * details must never be written into old_values, new_values or metadata. The
 * redaction list in config/security.php ('audit.sensitive_fields', replaced with
 * 'audit.redaction_placeholder') names the keys the audit service has to strip
 * before it calls this model.
 *
 * @property int $id
 * @property int|null $user_id
 * @property AuditAction $action
 * @property RiskLevel|null $risk_level
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property string|null $description
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $url
 * @property string|null $method
 * @property string|null $request_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;

    /**
     * Audit records are append-only: they are never updated.
     */
    public const UPDATED_AT = null;

    /**
     * Every column is written once, at insert time, by the audit service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'risk_level',
        'auditable_type',
        'auditable_id',
        'description',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'url',
        'method',
        'request_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'risk_level' => RiskLevel::class,
            'auditable_id' => 'integer',
            'request_id' => 'string',
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Enforce the two append-time guarantees the redaction/correlation policy
     * asks of EVERY audit row, no matter which code path writes it:
     *
     * 1. CORRELATION — a row written without an explicit request_id inherits
     *    the correlation id of the current execution context (HTTP request,
     *    queued job, scheduler run) so a whole action chain is traceable.
     * 2. SCRUBBING — old_values, new_values and metadata are stripped of every
     *    key named in config('security.audit.sensitive_fields') (recursive,
     *    replaced with the configured placeholder) before the row reaches the
     *    database. Writers may also scrub upstream; this is the last line.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $auditLog): void {
            if ($auditLog->request_id === null || $auditLog->request_id === '') {
                $auditLog->request_id = CorrelationContext::get();
            }

            $auditLog->old_values = self::scrubSensitivePayload($auditLog->old_values);
            $auditLog->new_values = self::scrubSensitivePayload($auditLog->new_values);
            $auditLog->metadata = self::scrubSensitivePayload($auditLog->metadata);
        });
    }

    /**
     * Recursively redact sensitive keys from a payload. A key is sensitive
     * when its normalized form (lowercase, '-' and ' ' folded to '_') either
     * equals or CONTAINS a configured sensitive field name — this rejects
     * obfuscations like 'card_number_encrypted' while leaving innocent keys
     * such as 'client_ip' untouched.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    private static function scrubSensitivePayload(?array $payload): ?array
    {
        if ($payload === null || $payload === []) {
            return $payload;
        }

        /** @var list<string> $sensitive */
        $sensitive = (array) config('security.audit.sensitive_fields', []);
        $sensitive = array_map(
            static fn (mixed $field): string => strtolower((string) $field),
            $sensitive,
        );

        $placeholder = (string) config('security.audit.redaction_placeholder', '[REDACTED]');

        $scrubbed = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));

            $sensitiveHit = false;

            foreach ($sensitive as $field) {
                if ($field !== '' && ($normalized === $field || str_contains($normalized, $field))) {
                    $sensitiveHit = true;

                    break;
                }
            }

            if ($sensitiveHit) {
                $scrubbed[$key] = $placeholder;
            } elseif (is_array($value)) {
                /** @var array<string, mixed> $value */
                $scrubbed[$key] = self::scrubSensitivePayload($value);
            } else {
                $scrubbed[$key] = $value;
            }
        }

        return $scrubbed;
    }

    /**
     * The actor, when the action was performed by a signed-in user.
     *
     * @return BelongsTo<User, self>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The record the action was performed on.
     *
     * @return MorphTo<Model, self>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isSystemAction(): bool
    {
        return $this->user_id === null;
    }

    public function isHighRisk(): bool
    {
        return in_array($this->risk_level, [RiskLevel::High, RiskLevel::Critical], true);
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
    public function scopeOfAction(Builder $query, AuditAction $action): Builder
    {
        return $query->where('action', $action);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeHighRisk(Builder $query): Builder
    {
        return $query->whereIn('risk_level', [RiskLevel::High, RiskLevel::Critical]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAuditable(Builder $query, string $type, int $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeBetween(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
