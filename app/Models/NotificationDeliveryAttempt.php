<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationFailureReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $attempt_identity
 * @property int $notification_id
 * @property int $attempt_number
 * @property NotificationChannel $channel
 * @property string $status
 * @property NotificationFailureReason|null $failure_reason
 * @property string|null $provider_reference
 * @property Carbon $attempted_at
 * @property Carbon|null $completed_at
 */
class NotificationDeliveryAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'attempt_identity', 'notification_id', 'attempt_number',
        'channel', 'status', 'failure_reason', 'provider_reference',
        'attempted_at', 'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'failure_reason' => NotificationFailureReason::class,
            'attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, self>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
