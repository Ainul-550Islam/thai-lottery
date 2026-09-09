<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\CommissionStatus;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\Bet;
use App\Models\FinancialTransaction;
use App\Services\Finance\FinancialReversalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service handling commission cancellations and financial reversals upon bet refund / void.
 */
class AgentCommissionReversalService
{
    public function __construct(
        private readonly FinancialReversalService $reversalService,
    ) {
    }

    /**
     * Reverse all commissions associated with a refunded or cancelled bet.
     *
     * @return list<AgentCommission>
     *
     * @throws FinancialException
     */
    public function reverseForBet(Bet $bet, string $reason = 'Bet refunded'): array
    {
        return DB::transaction(function () use ($bet, $reason): array {
            $commissions = AgentCommission::query()
                ->where('bet_id', $bet->getKey())
                ->whereNotIn('status', [CommissionStatus::Reversed, CommissionStatus::Cancelled])
                ->lockForUpdate()
                ->get();

            $reversed = [];

            foreach ($commissions as $commission) {
                $agent = Agent::query()->find($commission->agent_id);
                $amount = (string) $commission->commission_amount;

                if ($commission->status === CommissionStatus::Paid && $commission->financial_transaction_id !== null) {
                    // Commission was already paid out: reverse the financial transaction and debit the wallet
                    $tx = FinancialTransaction::query()->find($commission->financial_transaction_id);

                    if ($tx instanceof FinancialTransaction && $this->reversalService->canReverse($tx)) {
                        $this->reversalService->reverse(
                            original: $tx,
                            reason: sprintf('Reversal of agent commission for bet %s: %s', (string) $bet->bet_number, $reason),
                        );
                    }

                    if ($agent instanceof Agent) {
                        $agent->total_commission_paid = bcsub((string) $agent->total_commission_paid, $amount, 2);
                        if (bccomp((string) $agent->total_commission_paid, '0.00', 2) < 0) {
                            $agent->total_commission_paid = '0.00';
                        }
                    }
                }

                if ($agent instanceof Agent) {
                    $agent->total_commission_earned = bcsub((string) $agent->total_commission_earned, $amount, 2);
                    if (bccomp((string) $agent->total_commission_earned, '0.00', 2) < 0) {
                        $agent->total_commission_earned = '0.00';
                    }
                    $agent->save();
                }

                $commission->status = CommissionStatus::Reversed;
                $commission->reversed_at = Carbon::now();
                $metadata = is_array($commission->metadata) ? $commission->metadata : [];
                $metadata['reversal_reason'] = $reason;
                $commission->metadata = $metadata;
                $commission->save();

                $reversed[] = $commission;
            }

            return $reversed;
        });
    }

    /**
     * Reverse all commissions for a cancelled draw.
     *
     * @return list<AgentCommission>
     */
    public function reverseForDraw(int $drawId, string $reason = 'Draw cancelled'): array
    {
        return DB::transaction(function () use ($drawId, $reason): array {
            $commissions = AgentCommission::query()
                ->where('draw_id', $drawId)
                ->whereNotIn('status', [CommissionStatus::Reversed, CommissionStatus::Cancelled])
                ->lockForUpdate()
                ->get();

            $reversed = [];

            foreach ($commissions as $commission) {
                $agent = Agent::query()->find($commission->agent_id);
                $amount = (string) $commission->commission_amount;

                if ($commission->status === CommissionStatus::Paid && $commission->financial_transaction_id !== null) {
                    $tx = FinancialTransaction::query()->find($commission->financial_transaction_id);

                    if ($tx instanceof FinancialTransaction && $this->reversalService->canReverse($tx)) {
                        $this->reversalService->reverse(
                            original: $tx,
                            reason: sprintf('Reversal of agent commission for Draw %d: %s', $drawId, $reason),
                        );
                    }

                    if ($agent instanceof Agent) {
                        $agent->total_commission_paid = bcsub((string) $agent->total_commission_paid, $amount, 2);
                        if (bccomp((string) $agent->total_commission_paid, '0.00', 2) < 0) {
                            $agent->total_commission_paid = '0.00';
                        }
                    }
                }

                if ($agent instanceof Agent) {
                    $agent->total_commission_earned = bcsub((string) $agent->total_commission_earned, $amount, 2);
                    if (bccomp((string) $agent->total_commission_earned, '0.00', 2) < 0) {
                        $agent->total_commission_earned = '0.00';
                    }
                    $agent->save();
                }

                $commission->status = CommissionStatus::Reversed;
                $commission->reversed_at = Carbon::now();
                $metadata = is_array($commission->metadata) ? $commission->metadata : [];
                $metadata['reversal_reason'] = $reason;
                $commission->metadata = $metadata;
                $commission->save();

                $reversed[] = $commission;
            }

            return $reversed;
        });
    }
}
