<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\LedgerReconciliationStatus;
use App\Models\PaymentReconciliation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Immutable pronouncement event for ONE provider-vs-internal
 * reconciliation. Carries the conversation row, its end status, and
 * the drift evidence lines; the audit listener anchors them verbatim,
 * once per distinct fingerprint.
 */
final class PaymentTransactionReconciled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PaymentReconciliation $reconciliation,
        public readonly LedgerReconciliationStatus $status,
        public readonly array $driftLines = [],
    ) {}

    public function auditAnchor(): string
    {
        return sprintf(
            'pay-recon-audit:%s:%s',
            $this->reconciliation->reconciliation_key,
            (string) $this->reconciliation->fingerprint,
        );
    }
}
