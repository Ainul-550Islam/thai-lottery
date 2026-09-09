<?php

declare(strict_types=1);

namespace App\DTOs\Agent;

use App\Enums\Currency;

/**
 * Immutable outcome of a commission calculation for a single agent on a bet.
 */
final class CommissionCalculationResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $agentId,
        public readonly int $userId,
        public readonly int $betId,
        public readonly ?int $drawId,
        public readonly string $baseAmount,
        public readonly string $commissionRate,
        public readonly string $commissionAmount,
        public readonly Currency $currency,
        public readonly int $hierarchyLevel = 1,
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
            'user_id' => $this->userId,
            'bet_id' => $this->betId,
            'draw_id' => $this->drawId,
            'base_amount' => $this->baseAmount,
            'commission_rate' => $this->commissionRate,
            'commission_amount' => $this->commissionAmount,
            'currency' => $this->currency->value,
            'hierarchy_level' => $this->hierarchyLevel,
            'metadata' => $this->metadata,
        ];
    }
}
