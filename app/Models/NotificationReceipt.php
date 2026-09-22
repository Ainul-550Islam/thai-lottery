<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationFailureReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $receipt_fingerprint
 * @property int $notification_id
 * @property string $provider_reference
 * @property string $delivery_state
 * @property NotificationFailureReason|null $failure_reason
 * @property Carbon $reported_at
 */
class NotificationReceipt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'receipt_fingerprint', 'notification_id', 'provider_reference',
        'delivery_state', 'failure_reason', 'reported_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'failure_reason' => NotificationFailureReason::class,
            'reported_at' => 'datetime',
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
