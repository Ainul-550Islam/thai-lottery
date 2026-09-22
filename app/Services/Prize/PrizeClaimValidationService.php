<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\PrizeClaimData;
use App\DTOs\Prize\PrizeClaimResultData;
use App\Enums\BetStatus;
use App\Enums\ClaimWindowStatus;
use App\Enums\PrizeClaimStatus;
use App\Models\Bet;
use App\Models\Payout;

/**
 * Pure validation of a prize claim — the "can this claim be ALLOWED"
 * question, answered once, in full, and without writing a thing.
 *
 * RULE INVENTORY (IN ORDER OF EXPENSIVENESS)
 * ------------------------------------------
 *   existence           — the bet exists and is not soft-deleted
 *   winning             — the bet's status is Won and actual_payout > 0
 *   ownership           — the claim's claimant is the bet's owner
 *   payout_association  — the bet's prize payout exists (settlement's anchor)
 *   claim_window        — the claim window is effectively Open
 *   duplicate_claim     — no live claim record already occupies the prize —
 *                         except the SAME claimant re-asserting idempotently,
 *                         which is answered as ributes.passed duplicate replay
 *                         rather than failure
 *   ticket_verification — when the claim asserts ownership through a physical
 *                         ticket, the verification numbers are non-empty
 *
 * WHY A SEPARATE SERVICE
 * ----------------------
 * The claim SERVICE writes the claim; the validation SERVICE reads. Claim
 * flow could do its checks inline — but then the player-facing preview and
 * the actual write path would duplicate rules, and they would drift (one
 * finds the window open, another says closed). Validation service exists so
 * the question is posed once and answered the same way every time: every
 * answer is a structured DTO naming each failing rule, not a thrown
 * exception with one string.
 *
 * The verdict DTO also names the claim's NEXT state when eligible (auto-
 * Approved under the review threshold — the service looks up the threshold
 * exactly once per validation — or UnderReview), so the claim service just
 * seeds it into the claim record.
 */
class PrizeClaimValidationService
{
    /**
     * Stable rule identifiers the verdict's passed/failed lists name.
     */
    public const RULE_EXISTENCE = 'existence';
    public const RULE_WINNING = 'winning';
    public const RULE_OWNERSHIP = 'ownership';
    public const RULE_PAYOUT_ASSOCIATION = 'payout_association';
    public const RULE_CLAIM_WINDOW = 'claim_window';
    public const RULE_DUPLICATE_CLAIM = 'duplicate_claim';
    public const RULE_TICKET_VERIFICATION = 'ticket_verification';

    public function __construct(
        private readonly ClaimWindowService $windowService,
    ) {
    }

    /**
     * Validate a claim against every rule. Read-only by contract.
     */
    public function validate(PrizeClaimData $data): PrizeClaimResultData
    {
        $passed = [];
        $failed = [];
        $reason = null;

        $bet = Bet::query()->find($data->betId);

        if (! $bet instanceof Bet) {
            $failed[] = self::RULE_EXISTENCE;
            $reason = sprintf('Bet #%d does not exist.', $data->betId);

            return $this->ineligible($data, $failed, $passed, $reason);
        }

        $passed[] = self::RULE_EXISTENCE;

        if ($bet->status !== BetStatus::Won) {
            $failed[] = self::RULE_WINNING;
            $reason = sprintf('Bet #%d is %s, not won — there is no prize to assert.', $data->betId, $bet->status->value);

            return $this->ineligible($data, $failed, $passed, $reason);
        }

        if (bccomp((string) $bet->actual_payout, '0.00', 2) <= 0) {
            $failed[] = self::RULE_WINNING;
            $reason = sprintf('Bet #%d won a prize of zero; a claim record would fake an obligation of nothing.', $data->betId);

            return $this->ineligible($data, $failed, $passed, $reason);
        }

        $passed[] = self::RULE_WINNING;

        if ((int) $bet->user_id !== $data->claimantUserId) {
            $failed[] = self::RULE_OWNERSHIP;
            $reason = sprintf('Bet #%d belongs to another player; asserting for someone else would concede their money.', $data->betId);

            return $this->ineligible($data, $failed, $passed, $reason);
        }

        $passed[] = self::RULE_OWNERSHIP;

        $payout = $this->payoutForBet($bet);

        if (! $payout instanceof Payout) {
            $failed[] = self::RULE_PAYOUT_ASSOCIATION;
            $reason = sprintf('Bet #%d has no recorded payout obligation; the prize lane is not there to claim.', $data->betId);

            return $this->ineligible($data, $failed, $passed, $reason);
        }

        $passed[] = self::RULE_PAYOUT_ASSOCIATION;

        // CLAIM WINDOW — derived state, not stamped state.
        $windowStatus = $this->windowService->statusFor($payout);

        if ($windowStatus !== ClaimWindowStatus::Open) {
            $failed[] = self::RULE_CLAIM_WINDOW;
            $closes = $this->windowService->closesAtFor($payout);
            $reason = sprintf(
                'The claim window for the prize of bet #%d is %s%s.',
                $data->betId,
                $windowStatus->value,
                $closes instanceof \Illuminate\Support\Carbon ? '; it closed at '.$closes->toIso8601String() : '',
            );

            return $this->ineligible($data, $failed, $passed, $reason, $payout, $closes);
        }

        $passed[] = self::RULE_CLAIM_WINDOW;

        // DUPLICATE CLAIM — same claimant retrying is a replay (passes,
        // resolved by the claim service onto the same record); a DIFFERENT
        // claimant asserting the same prize would refuse.
        $claim = is_array($payout->metadata['claim'] ?? null) ? $payout->metadata['claim'] : null;

        if (is_array($claim)
            && ($claim['status'] ?? null) !== \App\Enums\PrizeClaimStatus::Rejected->value
        ) {
            $priorClaimant = (int) ($claim['claimant_user_id'] ?? 0);

            if ($priorClaimant !== 0 && $priorClaimant !== $data->claimantUserId) {
                $failed[] = self::RULE_DUPLICATE_CLAIM;
                $reason = sprintf(
                    'The prize for bet #%d is already occupied by another claim; asserting against it now is a duplicate.',
                    $data->betId,
                );

                return $this->ineligible($data, $failed, $passed, $reason, $payout);
            }
        }

        $passed[] = self::RULE_DUPLICATE_CLAIM;

        // TICKET VERIFICATION, only when the claim invoked that lane.
        if ($data->verificationCode !== null || $data->ticketNumber !== null) {
            if (! $data->hasTicketVerification()) {
                $failed[] = self::RULE_TICKET_VERIFICATION;
                $reason = 'The ticket-verification claim lane needs BOTH the ticket number and its verification code.';

                return $this->ineligible($data, $failed, $passed, $reason, $payout);
            }
        }

        $passed[] = self::RULE_TICKET_VERIFICATION;

        return new PrizeClaimResultData(
            eligible: true,
            nextStatus: $this->nextStatusFor((string) $bet->actual_payout),
            amount: (string) $bet->actual_payout,
            currency: $payout->currency->value,
            windowClosesAt: $this->windowService->closesAtFor($payout)?->toIso8601String(),
            failedRules: [],
            passedRules: $passed,
            reason: null,
            context: [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
            ],
        );
    }

    /**
     * Answer the eligible-path cheaply for screens that repaint the gate.
     */
    public function mayProceed(PrizeClaimData $data): bool
    {
        return $this->validate($data)->mayProceed();
    }

    /**
     * The payout obligation settlement created for a bet — the one the claim
     * rides on. Linked by bets.payout_id normally, with the reverse bet side
     * as fallback one-way join.
     */
    private function payoutForBet(Bet $bet): ?Payout
    {
        if ($bet->payout_id !== null) {
            $payout = Payout::query()->find((int) $bet->payout_id);

            if ($payout instanceof Payout) {
                return $payout;
            }
        }

        return Payout::query()
            ->where('bet_id', (int) $bet->getKey())
            ->orderBy('id')
            ->first();
    }

    /**
     * The next state for an eligible claim: auto-Approved under the manual
     * review threshold, UnderReview at or above it. Same rule the claim
     * service applies — one place to change.
     */
    private function nextStatusFor(string $amount): PrizeClaimStatus
    {
        $threshold = config('lottery.claims.review_threshold');

        if (! is_string($threshold) && ! is_numeric($threshold)) {
            return PrizeClaimStatus::UnderReview;
        }

        return bccomp($amount, (string) $threshold, 2) >= 0
            ? PrizeClaimStatus::UnderReview
            : PrizeClaimStatus::Approved;
    }

    /**
     * @param  list<string>  $failed
     * @param  list<string>  $passed
     */
    private function ineligible(
        PrizeClaimData $data,
        array $failed,
        array $passed,
        string $reason,
        ?Payout $payout = null,
        ?\Illuminate\Support\Carbon $windowClosesAt = null,
    ): PrizeClaimResultData {
        return new PrizeClaimResultData(
            eligible: false,
            nextStatus: PrizeClaimStatus::Rejected,
            amount: '0.00',
            currency: 'THB',
            windowClosesAt: $windowClosesAt?->toIso8601String(),
            failedRules: $failed,
            passedRules: $passed,
            reason: $reason,
            context: [
                'bet_id' => $data->betId,
                'claimant_user_id' => $data->claimantUserId,
                'payout_id' => $payout instanceof Payout ? (int) $payout->getKey() : null,
            ],
        );
    }
}
