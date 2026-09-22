<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\PrizeEligibilityData;
use App\Enums\AuditAction;
use App\Enums\KycStatus;
use App\Enums\PrizeEligibilityStatus;
use App\Enums\RiskLevel;
use App\Enums\TicketStatus;
use App\Exceptions\PrizeEligibilityException;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\PrizeEligibilityDecision;
use App\Models\User;
use App\Services\Prize\ClaimWindowService;
use Illuminate\Support\Facades\DB;

/**
 * The eligibility lane: evaluates whether a claimant may touch a prize,
 * built ENTIRELY from authoritative surfaces (payout/ticket/ownership,
 * KYC on the account itself, the claim window court, responsible-gaming
 * lane). NOTHING about the caller is trusted — a client can only ever
 * name WHICH payout it wants judged, never describe itself.
 *
 * DECISION VOCABULARY
 * - Blocked   — self-exclusion (hard gate) fired.
 * - Ineligible — a soft gate failed (window, KYC, ticket state).
 * - Eligible  — every gate passed at snapshot time.
 *
 * The verdict lands on a decision row keyed by (payout, principal);
 * re-evaluation rotates in place and is pronounced, never rewritten
 * underneath.
 */
final class PrizeEligibilityService
{
    public function __construct(
        private readonly ClaimWindowService $claimWindows,
    ) {
    }

    /* --------------------------------------------------- evaluate --- */

    /**
     * Judge a claimant against a payout the claimant names. The CLAIMANT
     * identity comes from the payout row itself (never from input); the
     * "claimant" caller is only ever the keyholder asking the question.
     *
     * @return array{decision: PrizeEligibilityDecision, status: PrizeEligibilityStatus, grounds: ?string}
     *
     * @throws PrizeEligibilityException only for structural failures
     *         (missing payout / principal-less payout) — never for a
     *         verdict; verdicts are served, not thrown.
     */
    public function evaluate(int $payoutId): array
    {
        return DB::transaction(function () use ($payoutId): array {
            /** @var Payout|null $payout */
            $payout = Payout::query()->lockForUpdate()->find($payoutId);

            if (! $payout instanceof Payout) {
                throw PrizeEligibilityException::notFound('payout:'.$payoutId);
            }

            /** @var User|null $principal */
            $principal = User::query()->lockForUpdate()->find((int) $payout->user_id);

            if (! $principal instanceof User) {
                throw PrizeEligibilityException::notFound('principal-of-payout:'.$payoutId);
            }

            // -------- gather the facts, authoritative lanes only --------
            $kyc = $principal->kycStatus();
            $kycValue = $kyc instanceof KycStatus ? $kyc->value : (string) $kyc;

            $windowAllows = $this->claimWindows->allowsClaim($payout);

            $selfExcluded = $principal->isSelfExcluded();

            $ticketState = (string) (optional($payout->ticket)->status?->value ?? 'record-free');

            $snapshot = new PrizeEligibilityData(
                payoutId: (int) $payout->getKey(),
                authoritativeUserId: (int) $principal->getKey(),
                kycStatus: $kycValue,
                claimWindowAllows: $windowAllows,
                selfExcluded: $selfExcluded,
                ticketState: $ticketState,
            );

            // -------- the gates, in severity order ---------------------
            [$status, $grounds, $refusal] = $this->decide($snapshot);

            $row = PrizeEligibilityDecision::query()
                ->lockForUpdate()
                ->where('decision_key', PrizeEligibilityData::decisionKey((int) $payout->getKey(), (int) $principal->getKey()))
                ->first()
                ?? new PrizeEligibilityDecision();

            $previous = $row->status instanceof PrizeEligibilityStatus ? $row->status : PrizeEligibilityStatus::Pending;

            $row->fill([
                'decision_key' => PrizeEligibilityData::decisionKey((int) $payout->getKey(), (int) $principal->getKey()),
                'payout_id' => (int) $payout->getKey(),
                'user_id' => (int) $principal->getKey(),
                'snapshot' => $snapshot->toArray(),
                'refusal_code' => $refusal,
                'refusal_reason' => $grounds,
            ]);
            $row->status = $status;
            $row->save();

            if ($previous !== $status) {
                $this->recordAudit($row, sprintf(
                    'Decision rotated %s → %s%s',
                    $previous->value,
                    $status->value,
                    $grounds !== null ? ' ('.$grounds.')' : ''
                ), $status->isHardGate() ? RiskLevel::Critical : RiskLevel::High);
            }

            return ['decision' => $row, 'status' => $status, 'grounds' => $grounds];
        });
    }

    /* ------------------------------------------------- admission ---- */

    /**
     * Fail-closed admission for consumers (disbursement, claim approval
     * must call this and refuse on anything but admit).
     *
     * @throws PrizeEligibilityException
     */
    public function assertAdmits(int $payoutId): PrizeEligibilityStatus
    {
        $out = $this->evaluate($payoutId);

        if (!$out['status']->admits()) {
            throw match ($out['status']) {
                PrizeEligibilityStatus::Blocked => PrizeEligibilityException::blockedClaimant(
                    (int) $out['decision']->user_id,
                ),
                default => PrizeEligibilityException::expiredClaim($payoutId, [
                    'status' => $out['status']->value,
                    'grounds' => $out['grounds'],
                ]),
            };
        }

        return $out['status'];
    }

    /* -------------------------------------------------- internals --- */

    /**
     * The decision itself: gates in severity order. Blocked first — a
     * self-excluded claimant never hears the soft gates' schedule, and
     * a soft gate never hides behind hard-gate grammar.
     *
     * @return array{0: PrizeEligibilityStatus, 1: ?string, 2: ?string}
     */
    private function decide(PrizeEligibilityData $snapshot): array
    {
        if ($snapshot->selfExcluded) {
            return [PrizeEligibilityStatus::Blocked, 'self-exclusion in force', 'PRIZE_ELIG_BLOCKED_CLAIMANT'];
        }

        if (! in_array($snapshot->ticketState, [TicketStatus::Confirmed->value, TicketStatus::Pending->value], true)) {
            return [PrizeEligibilityStatus::Ineligible, 'ticket state admits no claim', 'PRIZE_ELIG_INVALID_TICKET'];
        }

        if (! $snapshot->claimWindowAllows) {
            return [PrizeEligibilityStatus::Ineligible, 'claim window closed or not begun', 'PRIZE_ELIG_EXPIRED_CLAIM'];
        }

        if ($snapshot->kycStatus !== KycStatus::Verified->value) {
            return [PrizeEligibilityStatus::Ineligible, 'KYC lane is not verified', 'PRIZE_ELIG_MALFORMED:kyc'];
        }

        return [PrizeEligibilityStatus::Eligible, null, null];
    }

    private function recordAudit(PrizeEligibilityDecision $decision, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => PrizeEligibilityDecision::class,
            'auditable_id' => (int) $decision->getKey(),
            'description' => sprintf('%s (decision %s)', $description, substr($decision->decision_key, 0, 12)),
            'metadata' => [
                'decision_key' => $decision->decision_key,
                'payout_id' => (int) $decision->payout_id,
                'user_id' => (int) $decision->user_id,
                'lane' => 'prize-eligibility',
            ],
        ]);

        $log->save();
    }
}
