<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\LedgerReconciliationStatus;
use App\Models\LedgerReconciliation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired every time a wallet-liability reconciliation is pronounced or
 * refreshed (both Matched and DriftDetected outcomes). Carries the
 * reconciled row, the outcome status and the drift evidence lines so
 * the audit listener can anchor them verbatim.
 */
final class FinancialLedgerReconciled
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<int, string>  $driftLines
     */
    public function __construct(
        public readonly LedgerReconciliation $reconciliation,
        public readonly LedgerReconciliationStatus $status,
        public readonly array $driftLines = [],
    ) {}

    /**
     * The dedup anchor for the audit listener: one audit line per
     * (row, fingerprint) — a fingerprint ROTATION is a new fact and
     * must audit again.
     */
    public function auditAnchor(): string
    {
        return sprintf(
            'recon-audit:%s:%s',
            $this->reconciliation->reconciliation_key,
            (string) $this->reconciliation->fingerprint,
        );
    }
}
