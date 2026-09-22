<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Payment\PaymentReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Periodic provider reconciliation, paged by payment id cursor.
 *
 * THE JOB NEVER MUTATES MONEY: it pronounces comparison rows (matched
 * paper or drift evidence) and pages itself forward; a mid-sweep
 * hiccup resumes without doubling drift evidence.
 */
final class ReconcilePaymentProviderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGE_SIZE = 100;

    public function __construct(
        public readonly int $afterPaymentId = 0,
    ) {
        $this->onQueue('finance-reconciliation');
    }

    public function handle(PaymentReconciliationService $reconciliations): void
    {
        $outcome = $reconciliations->sweep($this->afterPaymentId, self::PAGE_SIZE);

        if ($outcome['drift'] > 0) {
            Log::warning('Payment-provider reconciliation sweep pronounced drift.', [
                'reconciled' => $outcome['reconciled'],
                'drift' => $outcome['drift'],
                'after_payment_id' => $this->afterPaymentId,
            ]);
        }

        if ($outcome['reconciled'] === self::PAGE_SIZE && $outcome['next_after_id'] > $this->afterPaymentId) {
            self::dispatch($outcome['next_after_id']);
        }
    }
}
