<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\Enums\AuditAction;
use App\Enums\BetStatus;
use App\Enums\PrizeClaimStatus;
use App\Events\PrizeClaimApproved;
use App\Enums\RiskLevel;
use App\Exceptions\PrizeClaimException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Payout;
use Illuminate\Support\Facades\DB;

/**
 * Player-side prize claims.
 *
 * WHAT A CLAIM IS HERE
 * --------------------
 * In a digital wallet system a prize is auto-awaited: settlement has already
 * created the payout obligation. A CLAIM is the player's assertion of
 * ownership over that obligation — "this win is mine, review it if needed,
 * then pay me" — and the operations track of that assertion. Claims ride on
 * the payout row itself (metadata.claim), because the payout IS the claim
 * ledger in this architecture: adding a parallel claims table would split one
 * obligation across two sources of truth.
 *
 * CLAIM WINDOWS (GLO-parity)
 * Government Lottery Office-style rules give a winner up to two years from
 * the draw to assert their prize; after that it lapses. config
 * ('lottery.claims.window_days', default 730) carries the window here. A
 * claim attempted past its window is refused with windowClosed(); the
 * sweeper (UnclaimedPrizeService + ExpireUnclaimedPrizesJob) is what actually
 * marks lapsed money Expired.
 *
 * THE STATE MACHINE
 * Submitted ─▶ UnderReview ─▶ Approved ─(payout completed)▶ Paid
 *                    │
 *                    └▶ Rejected                          └▶ Expired (sweeper)
 *
 * Below the manual-review threshold (config lottery.claims.review_threshold)
 * claims auto-approve on submission: paying ฿50 through a human queue is
 * theatre. At or above it, a claim waits in UnderReview until approve().
 *
 * IDEMPOTENCY: the claim record lives on the payout row under the same row
 * lock the payout itself uses; claiming twice over one payout returns the
 * recorded claim. Two concurrent first claims lose the lock; the loser
 * re-reads and replays.
 */
class PrizeClaimService
{
    public function __construct(
        private readonly ClaimWindowService $windows,
    ) {
    }

    /**
     * Claim a prize for a winning bet on behalf of a user.
     *
     * @return array{
     *     payout_id: int,
     *     bet_id: int,
     *     claimant_user_id: int,
     *     status: string,
     *     amount: string,
     *     currency: string,
     *     window_closes_at: string,
     *     claimed_at: string,
     *     no_op: bool
     * }
     *
     * @throws PrizeClaimException
     */
    public function claim(int $betId, int $claimantUserId): array
    {
        if (DB::transactionLevel() > 0) {
            throw PrizeClaimException::alreadyRunning(DB::transactionLevel());
        }

        return DB::transaction(function () use ($betId, $claimantUserId): array {
            $bet = Bet::query()->lockForUpdate()->find($betId);

            if (! $bet instanceof Bet) {
                throw PrizeClaimException::noWinningBet($betId, 'does not exist');
            }

            if ((int) $bet->user_id !== $claimantUserId) {
                throw PrizeClaimException::notOwner($betId, $claimantUserId, (int) $bet->user_id);
            }

            if ($bet->status !== BetStatus::Won) {
                throw PrizeClaimException::noWinningBet($betId, sprintf('is %s, not won', $bet->status->value));
            }

            if (bccomp((string) $bet->actual_payout, '0.00', 2) <= 0) {
                throw PrizeClaimException::nothingToClaim($betId);
            }

            $payout = $this->payoutForBet($bet);

            if (! $payout instanceof Payout) {
                throw PrizeClaimException::noWinningBet($betId, 'has no payout obligation recorded');
            }

            $payout = Payout::query()->lockForUpdate()->find((int) $payout->getKey());

            if (! $payout instanceof Payout) {
                throw PrizeClaimException::noWinningBet($betId, 'has no payout obligation recorded');
            }

            // THE CLAIM WINDOW IS OWNED BY ClaimWindowService. The window
            // lane registers itself on the payout at first contact (one
            // idempotent stamp, anchored at the draw's completion day +
            // lottery.claims.window_days) and is the single gate for "may
            // anyone still assert this prize" — computed against the wall
            // clock, never against a stamped Closed flag that could go stale.
            $this->windows->register($payout, $bet);

            if (! $this->windows->allowsClaim($payout)) {
                throw PrizeClaimException::windowClosed(
                    $betId,
                    $this->windows->closesAtFor($payout)?->toIso8601String() ?? 'unknown',
                );
            }

            $windowClosesAt = $this->windows->closesAtFor($payout);

            $existing = $this->claimRecord($payout);

            if (is_array($existing)) {
                $existingStatus = is_string($existing['status'] ?? null)
                    ? $existing['status']
                    : '';

                // A previous claim already occupies this prize: replay if it's
                // by the SAME claimant (the only claimant this flow allows,
                // since bets are single-owner), hard-refuse any foreign one.
                if ((int) ($existing['claimant_user_id'] ?? 0) !== $claimantUserId) {
                    throw PrizeClaimException::alreadyClaimed($betId, (int) $payout->getKey());
                }

                if ($existingStatus !== '' && ! in_array($existingStatus, [PrizeClaimStatus::Rejected->value], true)) {
                    return $this->resultRow($payout, $existing, noOp: true);
                }
            }

            $status = $this->requiresReview((string) $bet->actual_payout)
                ? PrizeClaimStatus::UnderReview
                : PrizeClaimStatus::Approved;

            $claim = [
                'status' => $status->value,
                'claimant_user_id' => $claimantUserId,
                'claimed_at' => now()->toIso8601String(),
                'window_closes_at' => $windowClosesAt?->toIso8601String(),
                'submitted_status' => $status->value,
            ];

            $metadata = is_array($payout->metadata) ? $payout->metadata : [];
            $metadata['claim'] = $claim;

            $payout->metadata = $metadata;
            $payout->save();

            $this->recordAudit($payout, $claim, $claimantUserId, sprintf(
                'Prize claim of %s %s for bet #%d submitted; status %s.',
                (string) $payout->amount,
                $payout->currency->value,
                $betId,
                $status->value,
            ));

            // Auto-approved under the review threshold: release the event
            // here. The human-lane release happens in approve(); both births
            // of an Approved claim must pass through the same event seam, so
            // listeners see every approval exactly once, whichever gate made
            // it.
            if ($status === PrizeClaimStatus::Approved) {
                event(new PrizeClaimApproved(
                    payout: $payout,
                    betId: $betId,
                    claimantUserId: $claimantUserId,
                    approvedByUserId: null,
                    autoApproved: true,
                ));
            }

            return $this->resultRow($payout, $claim, noOp: false);
        });
    }

    /**
     * Approve a claim that is waiting in UnderReview (or was auto-submitted as
     * UnderReview by the threshold rule). Only the operator holding the
     * payout-review authority may call this, enforced at the controller.
     *
     * @throws PrizeClaimException
     */
    public function approve(int $payoutId, int $operatorUserId): array
    {
        return DB::transaction(function () use ($payoutId, $operatorUserId): array {
            $payout = Payout::query()->lockForUpdate()->find($payoutId);

            if (! $payout instanceof Payout) {
                throw PrizeClaimException::stateForbids($payoutId, 'missing', 'approved');
            }

            $claim = $this->claimRecord($payout);

            if (! is_array($claim)) {
                throw PrizeClaimException::stateForbids($payoutId, 'no-claim', 'approved');
            }

            $status = is_string($claim['status'] ?? null) ? $claim['status'] : '';

            if ($status !== PrizeClaimStatus::UnderReview->value) {
                throw PrizeClaimException::stateForbids($payoutId, $status === '' ? 'unknown' : $status, 'approved');
            }

            $claim['status'] = PrizeClaimStatus::Approved->value;
            $claim['approved_by'] = $operatorUserId;
            $claim['approved_at'] = now()->toIso8601String();

            $this->writeClaim($payout, $claim);

            $this->recordAudit($payout, $claim, $operatorUserId, sprintf(
                'Prize claim of %s %s for payout #%d APPROVED.',
                (string) $payout->amount,
                $payout->currency->value,
                $payoutId,
            ));

            // The human birth of an Approved claim. Same seam as the
            // auto-approve path in claim(): one event, at most once per
            // claim.
            event(new PrizeClaimApproved(
                payout: $payout,
                betId: (int) ($payout->bet_id ?? 0),
                claimantUserId: (int) ($claim['claimant_user_id'] ?? 0),
                approvedByUserId: $operatorUserId,
                autoApproved: false,
            ));

            return $this->resultRow($payout, $claim, noOp: false);
        });
    }

    /**
     * Reject a claim in review. No payout money moves; the win remains
     * recorded against the bet for audit but its claim closes never-paid.
     *
     * @throws PrizeClaimException
     */
    public function reject(int $payoutId, int $operatorUserId, string $reason): array
    {
        return DB::transaction(function () use ($payoutId, $operatorUserId, $reason): array {
            $payout = Payout::query()->lockForUpdate()->find($payoutId);

            if (! $payout instanceof Payout) {
                throw PrizeClaimException::stateForbids($payoutId, 'missing', 'rejected');
            }

            $claim = $this->claimRecord($payout);

            if (! is_array($claim)) {
                throw PrizeClaimException::stateForbids($payoutId, 'no-claim', 'rejected');
            }

            $status = is_string($claim['status'] ?? null) ? $claim['status'] : '';

            if (! in_array($status, [PrizeClaimStatus::UnderReview->value, PrizeClaimStatus::Submitted->value], true)) {
                throw PrizeClaimException::stateForbids($payoutId, $status === '' ? 'unknown' : $status, 'rejected');
            }

            $claim['status'] = PrizeClaimStatus::Rejected->value;
            $claim['rejected_by'] = $operatorUserId;
            $claim['rejected_at'] = now()->toIso8601String();
            $claim['rejection_reason'] = $reason;

            $this->writeClaim($payout, $claim);

            $this->recordAudit($payout, $claim, $operatorUserId, sprintf(
                'Prize claim of %s %s for payout #%d REJECTED: %s.',
                (string) $payout->amount,
                $payout->currency->value,
                $payoutId,
                $reason,
            ));

            return $this->resultRow($payout, $claim, noOp: false);
        });
    }

    /**
     * The claim a payout carries, or null if none was ever submitted.
     *
     * @return array<string, mixed>|null
     */
    public function claimFor(int $payoutId): ?array
    {
        $payout = Payout::query()->find($payoutId);

        return $payout instanceof Payout ? $this->claimRecord($payout) : null;
    }

    /**
     * Where a payout's claim currently sits in the state machine.
     */
    public function statusOf(int $payoutId): ?PrizeClaimStatus
    {
        $claim = $this->claimFor($payoutId);

        if ($claim === null || ! isset($claim['status']) || ! is_string($claim['status'])) {
            return null;
        }

        return PrizeClaimStatus::tryFrom($claim['status']);
    }

    /**
     * Whether this claim window is still open.
     */
    public function windowIsOpen(?\Illuminate\Support\Carbon $windowClosesAt): bool
    {
        return $windowClosesAt === null || $windowClosesAt->isFuture();
    }

    /**
     * The payout row a won bet's settlement created for its prize.
     */
    private function payoutForBet(Bet $bet): ?Payout
    {
        if ($bet->payout_id !== null) {
            $payout = Payout::query()->find((int) $bet->payout_id);

            if ($payout instanceof Payout) {
                return $payout;
            }
        }

        // Settlement always links via bets.payout_id, but a payout row whose
        // bet_id points back at this bet is the same obligation — the link
        // was written one-way somewhere. Report-by-serving rather than
        // refusing: the row exists, so the claim can ride on it.
        return Payout::query()
            ->where('bet_id', (int) $bet->getKey())
            ->orderBy('id')
            ->first();
    }

    /**
     * Claims at or above the threshold go to the manual review queue; below
     * it they auto-approve. Threshold default zero (every claim reviewed) is
     * the conservative default; config raises it when operations is ready.
     */
    private function requiresReview(string $amount): bool
    {
        $threshold = config('lottery.claims.review_threshold');

        if (! is_string($threshold) && ! is_numeric($threshold)) {
            return true;
        }

        return bccomp($amount, (string) $threshold, 2) >= 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function claimRecord(Payout $payout): ?array
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $claim = $metadata['claim'] ?? null;

        return is_array($claim) ? $claim : null;
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function writeClaim(Payout $payout, array $claim): void
    {
        $metadata = is_array($payout->metadata) ? $payout->metadata : [];
        $metadata['claim'] = $claim;

        $payout->metadata = $metadata;
        $payout->save();
    }

    /**
     * @param  array<string, mixed>  $claim
     *
     * @return array{payout_id: int, bet_id: int, claimant_user_id: int, status: string, amount: string, currency: string, window_closes_at: string|null, claimed_at: string, no_op: bool}
     */
    private function resultRow(Payout $payout, array $claim, bool $noOp): array
    {
        return [
            'payout_id' => (int) $payout->getKey(),
            'bet_id' => $payout->bet_id !== null ? (int) $payout->bet_id : 0,
            'claimant_user_id' => (int) ($claim['claimant_user_id'] ?? 0),
            'status' => (string) ($claim['status'] ?? ''),
            'amount' => (string) $payout->amount,
            'currency' => $payout->currency->value,
            'window_closes_at' => isset($claim['window_closes_at']) ? (string) $claim['window_closes_at'] : null,
            'claimed_at' => (string) ($claim['claimed_at'] ?? ''),
            'no_op' => $noOp,
        ];
    }

    /**
     * @param  array<string, mixed>  $claim
     */
    private function recordAudit(Payout $payout, array $claim, ?int $actorUserId, string $description): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $actorUserId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Medium,
            'auditable_type' => Payout::class,
            'auditable_id' => $payout->getKey(),
            'description' => $description,
            'metadata' => [
                'payout_id' => (int) $payout->getKey(),
                'payout_reference' => (string) $payout->reference_number,
                'claim_status' => (string) ($claim['status'] ?? ''),
                'amount' => (string) $payout->amount,
                'currency' => $payout->currency->value,
            ],
        ]);

        $log->save();
    }
}
