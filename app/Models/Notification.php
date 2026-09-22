<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\NotificationFailureReason;
use App\Enums\NotificationPriority;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One transactional notification pronouncement (identity by
 * message_fingerprint). Message identity NEVER re-keys per attempt:
 * attempts get their own rows on a related table.
 *
 * @property int $id
 * @property string $message_fingerprint
 * @property int $user_id
 * @property NotificationEventType $event_type
 * @property NotificationChannel $channel
 * @property NotificationPriority $priority
 * @property NotificationStatus $status
 * @property NotificationFailureReason|null $failure_reason
 * @property string $locale
 * @property string|null $subject
 * @property string|null $body
 * @property string|null $payload_fingerprint
 * @property int|null $template_id
 * @property int $attempts
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon $expires_at
 * @property Carbon|null $read_at
 */
class Notification extends Model
{
    protected $fillable = [
        'message_fingerprint', 'user_id', 'event_type', 'channel',
        'priority', 'status', 'failure_reason', 'locale',
        'subject', 'body', 'payload_fingerprint', 'template_id',
        'attempts', 'queued_at', 'sent_at', 'delivered_at',
        'expires_at', 'read_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => NotificationEventType::class,
            'channel' => NotificationChannel::class,
            'priority' => NotificationPriority::class,
            'status' => NotificationStatus::class,
            'failure_reason' => NotificationFailureReason::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'expires_at' => 'datetime',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
     * @return HasMany<NotificationDeliveryAttempt>
     */
    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(NotificationDeliveryAttempt::class, 'notification_id');
    }

    /**
     * @return HasMany<NotificationReceipt>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(NotificationReceipt::class, 'notification_id');
    }

    /**
     * Server-horizon physics helper.
     */
    public function isPastHorizon(): bool
    {
        return now()->gte($this->expires_at);
    }
}
