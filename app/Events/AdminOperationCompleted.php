<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AdminOperation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Exactly-once envelope after an admin operation seats its outcome
 * (completed OR failed): identity rides the operation fingerprint;
 * the result_summary carries sanitized evidence only.
 */
final class AdminOperationCompleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AdminOperation $operation,
    ) {
    }

    public function completionFingerprint(): string
    {
        return hash('sha256', 'glo-adminop-done|'.$this->operation->operation_fingerprint.'|'.$this->operation->status->value);
    }
}
