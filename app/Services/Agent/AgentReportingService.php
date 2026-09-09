<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\AgentReportData;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Service aggregating agent performance and commission reporting.
 */
class AgentReportingService
{
    /**
     * Generate an aggregated performance report for an agent.
     */
    public function getAgentReport(
        Agent $agent,
        string $period = 'monthly',
        ?Carbon $startDate = null,
        ?Carbon $endDate = null,
    ): AgentReportData {
        $start = $startDate ?? Carbon::now()->startOfMonth();
        $end = $endDate ?? Carbon::now()->endOfMonth();

        // 1. Referred users
        $referredUserIds = User::query()
            ->whereJsonContains('preferences->referred_by_agent_id', $agent->id)
            ->pluck('id')
            ->all();

        $referredPlayersCount = count($referredUserIds);

        if ($referredPlayersCount === 0) {
            return new AgentReportData(
                agentId: (int) $agent->getKey(),
                agentCode: $agent->agent_code,
                period: $period,
                referredPlayers: 0,
                activePlayers: 0,
                totalTurnover: '0.00',
                totalPayout: '0.00',
                netRevenue: '0.00',
                commissionAccrued: '0.00',
                commissionPaid: '0.00',
                currency: ($agent->currency ?? Currency::THB)->value,
            );
        }

        // 2. Bets by referred players
        $bets = Bet::query()
            ->whereIn('user_id', $referredUserIds)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $activePlayersCount = $bets->pluck('user_id')->unique()->count();

        $totalTurnover = '0.00';
        $totalPayout = '0.00';

        foreach ($bets as $bet) {
            $totalTurnover = bcadd($totalTurnover, (string) $bet->stake_amount, 2);
            $totalPayout = bcadd($totalPayout, (string) ($bet->actual_payout ?? '0.00'), 2);
        }

        $netRevenue = bcsub($totalTurnover, $totalPayout, 2);
        if (bccomp($netRevenue, '0.00', 2) < 0) {
            $netRevenue = '0.00';
        }

        // 3. Commissions
        $commissions = AgentCommission::query()
            ->where('agent_id', $agent->id)
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $commissionAccrued = '0.00';
        $commissionPaid = '0.00';

        foreach ($commissions as $comm) {
            if ($comm->status !== CommissionStatus::Reversed && $comm->status !== CommissionStatus::Cancelled) {
                $commissionAccrued = bcadd($commissionAccrued, (string) $comm->commission_amount, 2);
            }
            if ($comm->status === CommissionStatus::Paid) {
                $commissionPaid = bcadd($commissionPaid, (string) $comm->commission_amount, 2);
            }
        }

        return new AgentReportData(
            agentId: (int) $agent->getKey(),
            agentCode: $agent->agent_code,
            period: $period,
            referredPlayers: $referredPlayersCount,
            activePlayers: $activePlayersCount,
            totalTurnover: $totalTurnover,
            totalPayout: $totalPayout,
            netRevenue: $netRevenue,
            commissionAccrued: $commissionAccrued,
            commissionPaid: $commissionPaid,
            currency: ($agent->currency ?? Currency::THB)->value,
            metadata: [
                'start_date' => $start->toIso8601String(),
                'end_date' => $end->toIso8601String(),
            ],
        );
    }
}
