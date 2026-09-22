<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\KycStatus;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\AuditLog;
use App\Models\User;

/**
 * The KYC gate at the point of withdrawal.
 *
 * WHY A SERVICE AND NOT A MIDDLEWARE CHECK
 * A gate that can cause a refusal worth money must be answerable the same way
 * everywhere the question is asked: the player's own screen ("can I press
 * Withdraw?"), the controller handling the request, the dev tools drama, and
 * the support operator looking at a stuck request. A single service with a
 * named refusal exception is that answer; a scattering of inline
 * `$user->kycStatus()->isVerified()` checks across five files each drifts
 * toward a different definition one upgrade later.
 *
 * THE RULE
 * --------
 * kyc_gate_enabled (config finance.withdrawal) ⇄ amount at or ABOVE
 * kyc_gate_threshold ⇄ aggregate KYC standing (User::kycStatus()) VERIFIED.
 * Below the threshold, small withdrawals flow without a verified document —
 * forcing every ฿100 withdrawal through a national-ID review queue is
 * revenue-hostile with no compliance benefit at that scale. An UNVERIFIED
 * player is also refused above-threshold, as is PENDING (document in review
 * is not yet proof; the player may re-ask once verified).
 *
 * REFUSALS CARRY MONEY-CONSEQUENCES, SO THEY CARRY AUDITS
 * A gate that blocks a withdrawal writes an audit line by default (config
 * finance.withdrawal.kyc_gate_audit) naming player, amount, currency, status
 * — the compliance report of who was blocked and why. The gate itself
 * performs no transaction itself, so a refusal mid-request cannot leave
 * anything half-posted.
 */
class WithdrawalKycGateService
{
    /**
     * Whether the gate is switched on. A single boolean: nothing about the
     * gate is environment-dependent, and a bug here must not silently widen.
     */
    public function isEnabled(): bool
    {
        return (bool) config('finance.withdrawal.kyc_gate_enabled', true);
    }

    /**
     * The configured threshold as a 2-decimal string.
     */
    public function threshold(): string
    {
        $configured = config('finance.withdrawal.kyc_gate_threshold');

        return is_numeric($configured) || is_string($configured)
            ? bcadd((string) $configured, '0.00', 2)
            : '5000.00';
    }

    /**
     * Whether this withdrawal request must pass KYC: the amount is at least
     * the configured floor.
     */
    public function requiresKyc(string $amount, Currency $currency): bool
    {
        return $this->isEnabled()
            && bccomp($amount, $this->threshold(), 2) >= 0;
    }

    /**
     * The gate, answered with no side effects: may this user request this
     * withdrawal right now? A refusal here means assertCanWithdraw() would
     * throw immediately — this is for screens, never for the write path.
     */
    public function mayWithdraw(User $user, string $amount, Currency $currency): bool
    {
        if (! $this->requiresKyc($amount, $currency)) {
            return true;
        }

        return $user->kycStatus()->isVerified();
    }

    /**
     * The gate, hard. The only call inside a request's write path.
     *
     * @throws FinancialException with code 'kyc_gate_withdrawal_denied'
     */
    public function assertCanWithdraw(User $user, string $amount, Currency $currency): void
    {
        if ($this->mayWithdraw($user, $amount, $currency)) {
            return;
        }

        $status = $user->kycStatus();

        $refusal = sprintf(
            'Withdrawal of %s %s requires a verified identity; aggregate KYC status is %s. '
            .'Complete identity verification to withdraw at or above %s %s.',
            $amount,
            $currency->value,
            $status->value,
            $this->threshold(),
            $currency->value,
        );

        $this->recordRefusal($user, $amount, $currency, $status, $refusal);

        throw FinancialException::withCode(
            'kyc_gate_withdrawal_denied',
            $refusal,
            [
                'user_id' => (int) $user->getKey(),
                'amount' => $amount,
                'currency' => $currency->value,
                'kyc_status' => $status->value,
                'threshold' => $this->threshold(),
            ],
        );
    }

    /**
     * The exact reason a request that failed the gate failed — for support
     * and the same message the player saw. Shared by error rendering and the
     * audit description, so they're byte-identical.
     */
    public function refusalReasonFor(User $user, string $amount, Currency $currency): string
    {
        $status = $user->kycStatus();

        return sprintf(
            'Withdrawal of %s %s requires a verified identity; aggregate KYC status is %s. '
            .'Complete identity verification to withdraw at or above %s %s.',
            $amount,
            $currency->value,
            $status->value,
            $this->threshold(),
            $currency->value,
        );
    }

    /**
     * Was this refusal worth a compliance audit line? Config switchable so a
     * high-volume operation can silence redundant noise on the player's own
     * masked screen, without losing the refusal itself.
     */
    private function auditRefusals(): bool
    {
        return (bool) config('finance.withdrawal.kyc_gate_audit', true);
    }

    /**
     * @param  Currency  $currency
     */
    private function recordRefusal(User $user, string $amount, $currency, KycStatus $status, string $refusal): void
    {
        if (! $this->auditRefusals()) {
            return;
        }

        $log = new AuditLog;

        $log->fill([
            'user_id' => (int) $user->getKey(),
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => User::class,
            'auditable_id' => $user->getKey(),
            'description' => sprintf(
                'KYC gate refused withdrawal of %s %s: aggregate KYC status %s.',
                $amount,
                $currency->value,
                $status->value,
            ),
            'metadata' => [
                'amount' => $amount,
                'currency' => $currency->value,
                'kyc_status' => $status->value,
                'threshold' => $this->threshold(),
                'gate' => 'withdrawal_kyc',
            ],
        ]);

        $log->save();
    }
}
