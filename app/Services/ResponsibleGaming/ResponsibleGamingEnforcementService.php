<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\Enums\DepositStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Models\Deposit;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;

/**
 * ResponsibleGamingEnforcementService — the central FAIL-CLOSED
 * facade consumed by betting / deposit / withdrawal flows.
 *
 * It answers one question per act: MAY this player perform this act
 * right now? The facade never implements money logic; it only reads
 * evidence (deposit rows, wallet-ledger transactions) and compares
 * with exact bcmath rules pronounced by the limit service. Every
 * refusal carries the desk's named code — never a bare boolean.
 */
final class ResponsibleGamingEnforcementService
{
    public function __construct(
        private readonly SelfExclusionService $selfExclusions,
        private readonly ResponsibleGamingLimitService $limits,
    ) {}

    /**
     * THE GATE for betting: exclusion first, per-act stake ceilings,
     * periodic loss ceilings (evaluated over the rolling window with
     * the projected stake included).
     *
     * @throws SelfExclusionException|ResponsibleGamingLimitException
     */
    public function assertBetAllowed(User $user, string $stakeAmount): void
    {
        $this->refuseIfExcluded($user);

        foreach ([ResponsibleGamingLimitType::SingleBet, ResponsibleGamingLimitType::StakeLimit] as $cap) {
            $binding = $this->limits->bindingLimitFor((int) $user->id, $cap);

            if ($binding !== null && bccomp($stakeAmount, (string) $binding->amount, 2) > 0) {
                throw ResponsibleGamingLimitException::invalidAmount(sprintf(
                    'stake %s exceeds the %s ceiling of %s %s',
                    $stakeAmount, $cap->label(), (string) $binding->amount, $binding->currency,
                ));
            }
        }

        foreach ([ResponsibleGamingLimitType::DailyLoss, ResponsibleGamingLimitType::WeeklyLoss, ResponsibleGamingLimitType::MonthlyLoss] as $cap) {
            $this->assertLossHeadroom($user, $cap, $stakeAmount);
        }
    }

    /**
     * THE GATE for deposits: exclusion, then every periodic deposit
     * ceiling with the projected amount included.
     *
     * @throws SelfExclusionException|ResponsibleGamingLimitException
     */
    public function assertDepositAllowed(User $user, string $amount): void
    {
        $this->refuseIfExcluded($user);

        foreach ([
            ResponsibleGamingLimitType::DailyDeposit,
            ResponsibleGamingLimitType::WeeklyDeposit,
            ResponsibleGamingLimitType::MonthlyDeposit,
        ] as $cap) {
            $binding = $this->limits->bindingLimitFor((int) $user->id, $cap);

            if ($binding === null) {
                continue;
            }

            $days = (int) ($cap->rollingPeriodDays() ?? 1);
            $spent = $this->depositVolume((int) $user->id, $days);
            $projected = bcadd($spent, $amount, 2);

            if (bccomp($projected, (string) $binding->amount, 2) > 0) {
                throw ResponsibleGamingLimitException::invalidAmount(sprintf(
                    'deposit %s would carry the %s window to %s, over the %s %s ceiling',
                    $amount, $cap->label(), $projected, (string) $binding->amount, $binding->currency,
                ));
            }
        }
    }

    /**
     * THE GATE for withdrawals: money OUT of the game is the player's
     * exit path — an active self-exclusion never stands in front of
     * it from the RG lane (wallet locks pronounced by compliance or
     * protection acts still gate independently of this facade).
     */
    public function assertWithdrawalAllowed(User $user): void
    {
        // Deliberately permissive: the desk closes the door to play,
        // never to the player's own money leaving.
    }

    /**
     * Post-hoc reconciliation read: the rolling loss of the window.
     */
    public function rollingLoss(User $user, int $days): string
    {
        return $this->netLoss((int) $user->id, $days);
    }

    private function refuseIfExcluded(User $user): void
    {
        $active = $this->selfExclusions->currentActiveFor((int) $user->id);

        if ($active !== null) {
            throw SelfExclusionException::activeExclusion((int) $user->id, $active->ends_at->toIso8601String());
        }
    }

    private function assertLossHeadroom(User $user, ResponsibleGamingLimitType $cap, string $projectedStake): void
    {
        $binding = $this->limits->bindingLimitFor((int) $user->id, $cap);

        if ($binding === null) {
            return;
        }

        $days = (int) ($cap->rollingPeriodDays() ?? 1);
        $loss = $this->netLoss((int) $user->id, $days);
        $projected = bcadd($loss, $projectedStake, 2);

        if (bccomp($projected, (string) $binding->amount, 2) > 0) {
            throw ResponsibleGamingLimitException::invalidAmount(sprintf(
                'stake %s would carry the %s window loss to %s, over the %s %s ceiling',
                $projectedStake, $cap->label(), $projected, (string) $binding->amount, $binding->currency,
            ));
        }
    }

    private function depositVolume(int $userId, int $days): string
    {
        $sum = Deposit::query()
            ->where('user_id', $userId)
            ->whereIn('status', [DepositStatus::completedCase(), DepositStatus::Pending])
            ->where('created_at', '>=', now()->subDays($days))
            ->sum('amount');

        return bcadd(sprintf('%0.2f', 0), (string) $sum, 2);
    }

    /**
     * Net loss over the rolling window against the wallet ledger:
     * completed stakes OUT minus completed winnings/refunds IN.
     * Read-only evidence — never arithmetic that moves money.
     */
    private function netLoss(int $userId, int $days): string
    {
        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->where('user_id', $userId)->first();

        if (! $wallet instanceof Wallet) {
            return '0.00';
        }

        $out = FinancialTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->get(['type', 'amount', 'status'])
            ->reduce(
                static function (string $carry, FinancialTransaction $tx): string {
                    $status = $tx->status instanceof TransactionStatus ? $tx->status : TransactionStatus::tryFrom((string) $tx->status);
                    $type = $tx->type instanceof TransactionType ? $tx->type : TransactionType::tryFrom((string) $tx->type);

                    if ($status !== TransactionStatus::Completed) {
                        return $carry;
                    }

                    return match ($type) {
                        TransactionType::BetPlacement => bcadd($carry, (string) $tx->amount, 2),
                        TransactionType::Payout, TransactionType::BetRefund => bcsub($carry, (string) $tx->amount, 2),
                        default => $carry,
                    };
                },
                '0.00',
            );

        return bccomp($out, '0', 2) < 0 ? '0.00' : $out;
    }
}
