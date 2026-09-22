<?php

declare(strict_types=1);

namespace App\Jobs\Notification;

use App\Enums\AuditAction;
use App\Enums\QueueName;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production Asynchronous Notification & Financial Alert Job.
 *
 * Dispatches critical financial discrepancy alerts, suspicious activity flags,
 * and operator alerts to configured channels without blocking monetary workflows.
 */
class SendFinancialAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum retry attempts.
     */
    public int $tries = 3;

    /**
     * Backoff delays in seconds.
     *
     * @var list<int>
     */
    public array $backoff = [5, 30, 60];

    /**
     * Execution timeout in seconds.
     */
    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $title,
        public readonly string $message,
        public readonly RiskLevel $severity = RiskLevel::High,
        public readonly array $metadata = [],
    ) {
        $this->onQueue(QueueName::Notifications->value);
    }

    /**
     * Execute the notification alert dispatch.
     */
    public function handle(): void
    {
        Log::channel('single')->log(
            $this->severity === RiskLevel::Critical ? 'critical' : 'warning',
            sprintf('[FINANCIAL ALERT] %s: %s', $this->title, $this->message),
            $this->metadata,
        );

        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Reconcile,
            'risk_level' => $this->severity,
            'auditable_type' => AuditLog::class,
            'auditable_id' => 0,
            'description' => sprintf('%s: %s', $this->title, $this->message),
            'metadata' => $this->metadata,
        ]);
        $log->save();
    }

    /**
     * Handle job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('SendFinancialAlertJob failed to deliver alert', [
            'title' => $this->title,
            'error' => $exception->getMessage(),
        ]);
    }
}
