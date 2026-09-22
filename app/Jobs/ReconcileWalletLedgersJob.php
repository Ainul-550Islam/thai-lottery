<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Finance\LedgerReconciliationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Page-by-page page-by-page sweep of the wallet-liability lane.
 *
 * Pages itself by wallet id cursor (each invocation handles one page
 * and dispatches the next while work remains) so a mid-sweep hiccup
 * resumes WITHOUT doubling drift evidence; respectfully-backed-off on
 * failure. Pronouncements are drift-as-evidence: reconciling never
 * throws on drift, it writes the line.
 */
final class ReconcileWalletLedgersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAGE_SIZE = 100;

    public function __construct(
        public readonly int $afterWalletId = 0,
    ) {
        $this->onQueue('finance-reconciliation');
    }

    public function handle(LedgerReconciliationService $reconciliations): void
    {
        $outcome = $reconciliations->sweep($this->afterWalletId, self::PAGE_SIZE);

        if ($outcome['drift'] > 0) {
            Log::warning('Wallet-ledger reconciliation sweep pronounced drift.', [
                'reconciled' => $outcome['reconciled'],
                'drift' => $outcome['drift'],
                'after_wallet_id' => $this->afterWalletId,
            ]);
        }

        // Page forward: if the last page was full there may be more
        // wallets; resume from the cursor.
        if ($outcome['reconciled'] === self::PAGE_SIZE && $outcome['next_after_id'] > $this->afterWalletId) {
            self::dispatch($outcome['next_after_id']);
        }
    }
}
