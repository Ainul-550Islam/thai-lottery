<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

use App\Enums\Currency;
use App\Enums\ReconciliationStatus;
use Carbon\CarbonInterface;

final readonly class FinancialReconciliationReport
{
    /**
     * @param  list<ReconciliationDiscrepancy>  $discrepancies
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $executionId,
        public ReconciliationStatus $status,
        public ?CarbonInterface $periodStart,
        public ?CarbonInterface $periodEnd,
        public ?Currency $currency,
        public string $totalDeposits,
        public string $totalBetPurchases,
        public string $totalPrizePayouts,
        public string $totalWithdrawals,
        public string $totalCommissions,
        public string $totalLedgerDebits,
        public string $totalLedgerCredits,
        public string $ledgerDifference,
        public array $discrepancies,
        public int $anomalyCount,
        public int $criticalAnomalyCount,
        public CarbonInterface $executedAt,
        public ?string $initiatedBy = null,
        public array $metadata = [],
        public float $durationSeconds = 0.0,
    ) {
    }

    public function isPassing(): bool
    {
        return $this->status->isPassing();
    }

    public function isCritical(): bool
    {
        return $this->status->isCritical();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'execution_id' => $this->executionId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'period_start' => $this->periodStart?->toIso8601String(),
            'period_end' => $this->periodEnd?->toIso8601String(),
            'currency' => $this->currency?->value,
            'totals' => [
                'deposits' => $this->totalDeposits,
                'bet_purchases' => $this->totalBetPurchases,
                'prize_payouts' => $this->totalPrizePayouts,
                'withdrawals' => $this->totalWithdrawals,
                'commissions' => $this->totalCommissions,
                'ledger_debits' => $this->totalLedgerDebits,
                'ledger_credits' => $this->totalLedgerCredits,
                'ledger_difference' => $this->ledgerDifference,
            ],
            'anomaly_count' => $this->anomalyCount,
            'critical_anomaly_count' => $this->criticalAnomalyCount,
            'discrepancies' => array_map(
                static fn (ReconciliationDiscrepancy $d): array => $d->toArray(),
                $this->discrepancies,
            ),
            'executed_at' => $this->executedAt->toIso8601String(),
            'initiated_by' => $this->initiatedBy,
            'metadata' => $this->metadata,
        ];
    }
}
