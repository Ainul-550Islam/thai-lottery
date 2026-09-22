<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AdminOperation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ExecuteAdminOperationJob — executes an APPROVED operation exactly
 * once by its operation fingerprint: terminal rows and missing rows
 * are replays, never double work; every pathway is safe to revisit.
 */
final class ExecuteAdminOperationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $operationFingerprint,
    ) {
    }

    public function handle(\App\Services\Operations\AdminOperationService $operations): void
    {
        $operation = AdminOperation::query()
            ->where('operation_fingerprint', $this->operationFingerprint)
            ->first();

        if (! $operation instanceof AdminOperation) {
            return; // vanished before execution: nothing to seat
        }

        $operations->execute((int) $operation->id);
    }
}
