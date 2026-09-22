<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\QueueName;
use App\Exceptions\WinnerNotificationException;
use App\Models\WinnerNotification;
use App\Services\Prize\WinnerNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue-safe winner-notification delivery.
 *
 * DISCIPLINE
 * - Retry/backoff: 30s, 120s, 600s — a transient provider hiccup is a
 *   retry, a structural refusal is pronounced Failed at once (never
 *   silent loops).
 * - Duplicate suppression: the notification lane's own state machine
 *   closes the dispatch window at Sent/Acknowledged; a job retry after
 *   a successful send-side-but-not-committed is a pronounced skip.
 * - Never invents recipients: the notification row IS the answer.
 */
final class SendWinnerNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 1800;

    public array $backoff = [30, 120, 600];

    public function __construct(
        public readonly int $winnerNotificationId,
    ) {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'send_winner_notification_'.$this->winnerNotificationId.'_'.now()->format('Y-m-d-H');
    }

    /**
     * @return array{sent: bool, skipped: ?string}
     */
    public function handle(WinnerNotificationService $notifications): array
    {
        /** @var WinnerNotification|null $notification */
        $notification = WinnerNotification::query()->find($this->winnerNotificationId);

        if (! $notification instanceof WinnerNotification) {
            Log::info('SendWinnerNotificationJob: notification gone — skipped', [
                'notification_id' => $this->winnerNotificationId,
            ]);

            return ['sent' => false, 'skipped' => 'gone'];
        }

        try {
            $notifications->dispatch($notification);

            Log::info('SendWinnerNotificationJob: delivered', [
                'notification_id' => $this->winnerNotificationId,
                'channel' => (string) $notification->channel,
            ]);

            return ['sent' => true, 'skipped' => null];
        } catch (WinnerNotificationException $e) {
            if ($e->errorCode() === WinnerNotificationException::CODE_DUPLICATE) {
                // Already spoken for: the suppression window held.
                Log::info('SendWinnerNotificationJob: duplicate suppressed', [
                    'notification_id' => $this->winnerNotificationId,
                ]);

                return ['sent' => false, 'skipped' => 'duplicate'];
            }

            $notifications->pronounceFailed($notification, $e->errorCode());

            Log::warning('SendWinnerNotificationJob: delivery refused', [
                'notification_id' => $this->winnerNotificationId,
                'reason' => $e->errorCode(),
            ]);

            return ['sent' => false, 'skipped' => $e->errorCode()];
        }
    }
}
