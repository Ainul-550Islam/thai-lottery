<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionCalculationResult;
use App\Enums\Currency;
use App\Models\Agent;
use App\Models\Bet;
use App\Services\Finance\Money;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Pure calculation service for agent commissions.
 */
class CommissionCalculationService
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Calculate commission amount for an agent based on a placed bet.
     */
    public function calculate(Bet $bet, Agent $agent, int $hierarchyLevel = 1): CommissionCalculationResult
    {
        Money::assertExactArithmeticIsAvailable();

        $mode = (string) $this->config->get('agent.commission.mode', 'turnover');
        $currency = $bet->currency ?? Currency::THB;
        $stake = (string) $bet->stake_amount;

        $baseAmount = match ($mode) {
            'net_revenue' => $this->calculateNetRevenueBase($bet),
            default => $stake, // turnover mode
        };

        $rate = (string) $agent->commission_rate;

        // Level 2 (Parent agent) commission rate override or standard split if applicable
        if ($hierarchyLevel === 2) {
            $parentSplit = (string) $this->config->get('agent.hierarchy.parent_commission_rate', '0.0100');
            $rate = $parentSplit;
        }

        // Commission = Base Amount * Rate
        $rawCommission = bcmul($baseAmount, $rate, 4);
        $roundedCommission = $this->roundHalfUp($rawCommission, 2);

        // Ensure not negative
        if (bccomp($roundedCommission, '0.00', 2) < 0) {
            $roundedCommission = '0.00';
        }

        return new CommissionCalculationResult(
            agentId: (int) $agent->getKey(),
            userId: (int) $bet->user_id,
            betId: (int) $bet->getKey(),
            drawId: (int) $bet->draw_id,
            baseAmount: $baseAmount,
            commissionRate: $rate,
            commissionAmount: $roundedCommission,
            currency: $currency,
            hierarchyLevel: $hierarchyLevel,
            metadata: [
                'mode' => $mode,
                'stake' => $stake,
                'hierarchy_level' => $hierarchyLevel,
            ],
        );
    }

    private function calculateNetRevenueBase(Bet $bet): string
    {
        $stake = (string) $bet->stake_amount;
        $payout = (string) ($bet->actual_payout ?? '0.00');

        $net = bcsub($stake, $payout, 2);

        return bccomp($net, '0.00', 2) > 0 ? $net : '0.00';
    }

    private function roundHalfUp(string $value, int $scale): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = $negative ? substr($value, 1) : $value;

        $increment = '0.'.str_repeat('0', $scale).'5';
        $rounded = bcadd($absolute, $increment, $scale);

        return $negative ? '-'.$rounded : $rounded;
    }
}
