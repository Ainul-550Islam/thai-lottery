<?php

declare(strict_types=1);

namespace App\DTOs\Agent;

/**
 * Performance report data for an agent.
 */
final class AgentReportData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $agentId,
        public readonly string $agentCode,
        public readonly string $period,
        public readonly int $referredPlayers,
        public readonly int $activePlayers,
        public readonly string $totalTurnover,
        public readonly string $totalPayout,
        public readonly string $netRevenue,
        public readonly string $commissionAccrued,
        public readonly string $commissionPaid,
        public readonly string $currency,
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agent_id' => $this->agentId,
            'agent_code' => $this->agentCode,
            'period' => $this->period,
            'referred_players' => $this->referredPlayers,
            'active_players' => $this->activePlayers,
            'total_turnover' => $this->totalTurnover,
            'total_payout' => $this->totalPayout,
            'net_revenue' => $this->netRevenue,
            'commission_accrued' => $this->commissionAccrued,
            'commission_paid' => $this->commissionPaid,
            'currency' => $this->currency,
            'metadata' => $this->metadata,
        ];
    }
}
