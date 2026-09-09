<?php

declare(strict_types=1);

namespace App\DTOs\Agent;

use App\Models\AgentCommission;

/**
 * Summary result of commission settlement across a draw or period.
 */
final class CommissionSettlementResult
{
    /**
     * @param  list<AgentCommission>  $paidCommissions
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly int $drawId,
        public readonly int $commissionsSettled,
        public readonly string $totalCommissionPaid,
        public readonly string $currency,
        public readonly bool $alreadySettled = false,
        public readonly array $paidCommissions = [],
        public readonly array $context = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'draw_id' => $this->drawId,
            'commissions_settled' => $this->commissionsSettled,
            'total_commission_paid' => $this->totalCommissionPaid,
            'currency' => $this->currency,
            'already_settled' => $this->alreadySettled,
            'context' => $this->context,
        ];
    }
}
