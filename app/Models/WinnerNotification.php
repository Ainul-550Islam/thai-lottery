<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WinnerNotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WinnerNotification extends Model
{
    protected $table = 'winner_notifications';

    protected $fillable = [
        'notification_key',
        'payout_id',
        'user_id',
        'channel',
        'draw_reference',
        'result_version',
        'dedupe_key',
        'queued_at',
        'sent_at',
        'failed_at',
        'acknowledged_at',
        'failure_reason',
        'attempt_count',
        'metadata',
    ];

    protected $casts = [
        'status' => WinnerNotificationStatus::class,
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
