<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\CommissionSettlementResult;
use App\Enums\AgentStatus;
use App\Enums\AuditAction;
use App\Enums\CommissionStatus;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AgentCommission;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service settling accrued agent commissions and disbursing funds to agent wallets.
 */
class AgentCommissionSettlementService
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly WalletService $wallets,
        private readonly WalletLockService $walletLocks,
    ) {
    }

    /**
     * Settle all accrued commissions for a completed draw.
     *
     * @throws FinancialException
     */
    public function settleForDraw(int $drawId): CommissionSettlementResult
    {
        return DB::transaction(function () use ($drawId): CommissionSettlementResult {
            $draw = Draw::query()->find($drawId);
            $drawNumber = $draw ? (string) $draw->draw_number : (string) $drawId;

            $commissions = AgentCommission::query()
                ->where('draw_id', $drawId)
                ->whereIn('status', [CommissionStatus::Accrued, CommissionStatus::Calculated, CommissionStatus::Payable])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($commissions->isEmpty()) {
                return new CommissionSettlementResult(
                    drawId: $drawId,
                    commissionsSettled: 0,
                    totalCommissionPaid: '0.00',
                    currency: Currency::THB->value,
                    alreadySettled: true,
                );
            }

            $settledCount = 0;
            $totalPaid = '0.00';
            $currency = Currency::THB;
            $paidRecords = [];

            foreach ($commissions as $commission) {
                $commissionCurrency = $commission->currency ?? Currency::THB;
                $currency = $commissionCurrency;
                $amount = (string) $commission->commission_amount;

                if (bccomp($amount, '0.00', 2) <= 0) {
                    continue;
                }

                $agent = Agent::query()->find($commission->agent_id);

                if (! $agent instanceof Agent) {
                    continue;
                }

                // If agent is inactive or suspended, skip payout or cancel
                if (! $agent->canEarnCommission()) {
                    $commission->status = CommissionStatus::Cancelled;
                    $commission->save();
                    continue;
                }

                $wallet = $this->resolveAndLockAgentWallet($agent, $commissionCurrency);

                $idempotencyKey = sprintf('agent-commission-draw-%06d-comm-%06d', $drawId, (int) $commission->getKey());

                $transaction = $this->wallets->credit(
                    wallet: $wallet,
                    amount: Money::of($amount, $commissionCurrency),
                    type: FinancialTransactionType::Commission,
                    idempotencyKey: $idempotencyKey,
                    options: [
                        'description' => sprintf('Agent commission for Draw %s (Ref %s)', $drawNumber, $commission->reference_number),
                        'reference_type' => AgentCommission::class,
                        'reference_id' => (int) $commission->getKey(),
                        'metadata' => [
                            'agent_id' => $agent->id,
                            'agent_code' => $agent->agent_code,
                            'draw_id' => $drawId,
                            'draw_number' => $drawNumber,
                            'commission_id' => $commission->id,
                        ],
                    ],
                );

                $commission->financial_transaction_id = (int) $transaction->getKey();
                $commission->status = CommissionStatus::Paid;
                $commission->paid_at = Carbon::now();
                $commission->save();

                $agent->total_commission_paid = bcadd((string) $agent->total_commission_paid, $amount, 2);
                $agent->save();

                $settledCount++;
                $totalPaid = bcadd($totalPaid, $amount, 2);
                $paidRecords[] = $commission;
            }

            $result = new CommissionSettlementResult(
                drawId: $drawId,
                commissionsSettled: $settledCount,
                totalCommissionPaid: $totalPaid,
                currency: $currency->value,
                alreadySettled: false,
                paidCommissions: $paidRecords,
            );

            $this->recordAudit($drawId, $drawNumber, $result);

            return $result;
        });
    }

    private function resolveAndLockAgentWallet(Agent $agent, Currency $currency): Wallet
    {
        $wallet = Wallet::query()
            ->where('user_id', $agent->user_id)
            ->where('currency', $currency->value)
            ->orderBy('id')
            ->first();

        if (! $wallet instanceof Wallet) {
            throw FinancialException::withCode(
                'agent_wallet_not_found',
                sprintf('Agent %s (User #%d) has no wallet in %s to receive commission.', $agent->agent_code, $agent->user_id, $currency->value),
                ['agent_id' => $agent->id, 'currency' => $currency->value],
            );
        }

        return $this->walletLocks->lockForCredit((int) $wallet->getKey(), $currency);
    }

    private function recordAudit(int $drawId, string $drawNumber, CommissionSettlementResult $result): void
    {
        $log = new AuditLog();
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Payout,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => Draw::class,
            'auditable_id' => $drawId,
            'description' => sprintf(
                'Settled %d agent commission(s) for Draw %s totaling %s %s.',
                $result->commissionsSettled,
                $drawNumber,
                $result->totalCommissionPaid,
                $result->currency,
            ),
            'metadata' => [
                'draw_id' => $drawId,
                'draw_number' => $drawNumber,
                'commissions_settled' => $result->commissionsSettled,
                'total_paid' => $result->totalCommissionPaid,
                'currency' => $result->currency,
            ],
        ]);
        $log->save();
    }
}
