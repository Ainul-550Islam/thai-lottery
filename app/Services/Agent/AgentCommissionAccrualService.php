<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service handling commission accrual on placed bets.
 */
class AgentCommissionAccrualService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly AgentReferralService $referrals,
        private readonly CommissionCalculationService $calculator,
    ) {
    }

    /**
     * Accrue commissions for a newly placed bet across the agent hierarchy.
     *
     * @return list<AgentCommission>
     */
    public function accrueForBet(Bet $bet): array
    {
        return DB::transaction(function () use ($bet): array {
            $directAgent = $this->referrals->resolveAgentForUser((int) $bet->user_id);

            if (! $directAgent instanceof Agent) {
                return [];
            }

            $created = [];

            // 1. Level 1: Direct Agent Commission
            $directCalc = $this->calculator->calculate($bet, $directAgent, 1);

            if (bccomp($directCalc->commissionAmount, '0.00', 2) > 0) {
                $directCommission = $this->createCommissionRecord($directAgent, $bet, $directCalc->commissionRate, $directCalc->baseAmount, $directCalc->commissionAmount, 1);
                $created[] = $directCommission;

                $directAgent->total_commission_earned = bcadd((string) $directAgent->total_commission_earned, $directCalc->commissionAmount, 2);
                $directAgent->save();
            }

            // 2. Level 2: Parent Agent Hierarchy Commission (if configured & parent is active)
            $hierarchyEnabled = (bool) $this->config->get('agent.hierarchy.enabled', true);
            if ($hierarchyEnabled && $directAgent->parent_agent_id !== null) {
                $parentAgent = Agent::query()->find($directAgent->parent_agent_id);

                if ($parentAgent instanceof Agent && $parentAgent->canEarnCommission()) {
                    $parentCalc = $this->calculator->calculate($bet, $parentAgent, 2);

                    if (bccomp($parentCalc->commissionAmount, '0.00', 2) > 0) {
                        $parentCommission = $this->createCommissionRecord($parentAgent, $bet, $parentCalc->commissionRate, $parentCalc->baseAmount, $parentCalc->commissionAmount, 2);
                        $created[] = $parentCommission;

                        $parentAgent->total_commission_earned = bcadd((string) $parentAgent->total_commission_earned, $parentCalc->commissionAmount, 2);
                        $parentAgent->save();
                    }
                }
            }

            return $created;
        });
    }

    private function createCommissionRecord(
        Agent $agent,
        Bet $bet,
        string $rate,
        string $baseAmount,
        string $commissionAmount,
        int $hierarchyLevel,
    ): AgentCommission {
        $commission = new AgentCommission();
        $commission->fill([
            'reference_number' => $this->generateCommissionReferenceNumber(),
            'agent_id' => $agent->id,
            'user_id' => $bet->user_id,
            'bet_id' => $bet->id,
            'draw_id' => $bet->draw_id,
            'status' => CommissionStatus::Accrued,
            'currency' => $bet->currency ?? Currency::THB,
            'base_amount' => $baseAmount,
            'commission_rate' => $rate,
            'commission_amount' => $commissionAmount,
            'accrued_at' => Carbon::now(),
            'metadata' => [
                'hierarchy_level' => $hierarchyLevel,
                'bet_number' => $bet->bet_number,
                'draw_id' => $bet->draw_id,
            ],
        ]);
        $commission->save();

        return $commission;
    }

    private function generateCommissionReferenceNumber(): string
    {
        return sprintf(
            'COM-%s-%s',
            Carbon::now()->format('Ymd'),
            strtoupper(bin2hex(random_bytes(6))),
        );
    }
}
