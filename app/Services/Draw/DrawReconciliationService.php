<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\Draw\DrawCertificationData;
use App\DTOs\Draw\DrawReconciliationData;
use App\Enums\AuditAction;
use App\Enums\DrawReconciliationStatus;
use App\Enums\RiskLevel;
use App\Exceptions\DrawReconciliationException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawReconciliation;
use Illuminate\Support\Facades\DB;

/**
 * The draw-level reconciler: cross-checks the result lane against every
 * lane that inherits from it — winning tickets, prize settlements, and
 * payout records — and NEVER touches any of them.
 *
 * READ MIRROR PRINCIPLE
 * The lanes this service reads (winning_numbers, bets, payouts) are
 * other courts' ledgers. Writes here go exclusively to the reconciliation
 * lane's OWN row (its judgment is its ledger). The service is, by
 * design, incapable of repairing anyone else's money.
 *
 * DRIFT, NOT EXCEPTIONS
 * When two lanes disagree, the answer is a pronounced DriftDetected row
 * with named drift lines. Exceptions here are reserved for refusals of
 * the ACT itself (malformed packs, missing draws, unresolved-drift gates
 * for consumers).
 */
final class DrawReconciliationService
{
    /* ------------------------------------------------ reconcile ---- */

    /**
     * @return array{reconciliation: DrawReconciliation, drift_lines: int, matched: bool}
     *
     * @throws DrawReconciliationException
     */
    public function reconcile(DrawReconciliationData $data): array
    {
        /** @var Draw|null $draw */
        $draw = Draw::query()->find($data->drawId);

        if (! $draw instanceof Draw) {
            throw DrawReconciliationException::notFound((string) $data->drawId);
        }

        return DB::transaction(function () use ($data): array {
            // ---------------- read every lane, note what each says ----
            $assertedFingerprint = DrawCertificationData::canonicalFingerprint(
                $data->drawId,
                $data->winningNumbers,
            );

            $ledgerFingerprint = DrawCertificationService::fingerprintFor($data->drawId);

            $actual = $this->actualTotals($data->drawId);

            $drift = [];

            // RESULT LANE: the published numbers vs what the pack asserts.
            if ($ledgerFingerprint === null) {
                $drift[] = ['lane' => 'result', 'line' => 'no-ledger-result', 'expected' => $assertedFingerprint, 'actual' => null];
            } elseif (! hash_equals($ledgerFingerprint, $assertedFingerprint)) {
                $drift[] = ['lane' => 'result', 'line' => 'numbers-disagree', 'expected' => $ledgerFingerprint, 'actual' => $assertedFingerprint];
            }

            // WINNERS LANE: asserted winners vs. winning-ticket scoring
            // (bets marked Won carry the lane's actuals).
            if (!$this->within($data->expectedWinners, $actual['winner_bets'])) {
                $drift[] = ['lane' => 'tickets', 'line' => 'winner-count', 'expected' => $data->expectedWinners, 'actual' => $actual['winner_bets']];
            }

            // PRIZE LANE: asserted prize-settlement total vs. what the won
            // bets actually owe (the entitlement reading).
            if (!$this->within($data->expectedPrizeSettled, $actual['prize_owed'])) {
                $drift[] = ['lane' => 'prizes', 'line' => 'owed-disagree', 'expected' => $data->expectedPrizeSettled, 'actual' => $actual['prize_owed']];
            }

            // CLAIMS LANE: asserted prize-settlement total vs. the claims
            // the house has actually ADJUDICATED approved on this draw's
            // payout records (the settlement reading).
            if (!$this->within($data->expectedPrizeSettled, $actual['claims_approved'])) {
                $drift[] = ['lane' => 'claims', 'line' => 'adjudicated-disagree', 'expected' => $data->expectedPrizeSettled, 'actual' => $actual['claims_approved']];
            }

            // PAYOUT LANE: asserted payouts-paid vs. what completed payout
            // records actually moved.
            if (!$this->within($data->expectedPayoutsPaid, $actual['payouts_paid'])) {
                $drift[] = ['lane' => 'payouts', 'line' => 'paid-disagree', 'expected' => $data->expectedPayoutsPaid, 'actual' => $actual['payouts_paid']];
            }

            $matched = $drift === [];

            // ONE OPEN ROW PER DRAW: rotation in place when a previous
            // open conversation exists; a fresh row otherwise.
            $row = DrawReconciliation::query()
                ->lockForUpdate()
                ->where('draw_id', $data->drawId)
                ->whereIn('status', [
                    DrawReconciliationStatus::Pending->value,
                    DrawReconciliationStatus::DriftDetected->value,
                ])
                ->first()
                ?? new DrawReconciliation();

            $asserted = [
                'result_fingerprint' => $assertedFingerprint,
                'winners' => $data->expectedWinners,
                'payout' => $data->expectedPayout,
                'prize_settled' => $data->expectedPrizeSettled,
                'payouts_paid' => $data->expectedPayoutsPaid,
            ];

            $row->fill([
                'reconciliation_key' => $row->exists
                    ? (string) $row->reconciliation_key
                    : $data->reconciliationKey(),
                'draw_id' => $data->drawId,
                'asserted_totals' => $asserted,
                'actual_totals' => $actual + ['result_fingerprint' => $ledgerFingerprint],
                'drift_lines' => $matched ? null : $drift,
            ]);

            if ($matched) {
                $row->status = DrawReconciliationStatus::Matched;
            } else {
                $row->status = DrawReconciliationStatus::DriftDetected;
            }
            $row->save();

            if (!$matched) {
                $this->recordAudit($row, sprintf(
                    'Drift pronounced on %d lane(s) of draw #%d',
                    count($drift),
                    $data->drawId,
                ), RiskLevel::High);
            }

            return [
                'reconciliation' => $row,
                'drift_lines' => count($drift),
                'matched' => $matched,
            ];
        });
    }

    /* ----------------------------------------------------- resolve -- */

    /**
     * Resolve a DriftDetected row BY HAND. Resolutions outside that state
     * refuse loudly — "already resolved" replays serve quietly, as always.
     *
     * @throws DrawReconciliationException
     */
    public function resolve(DrawReconciliation $reconciliation, string $note): DrawReconciliation
    {
        return DB::transaction(function () use ($reconciliation, $note): DrawReconciliation {
            /** @var DrawReconciliation|null $locked */
            $locked = DrawReconciliation::query()->lockForUpdate()->find((int) $reconciliation->getKey());

            if (! $locked instanceof DrawReconciliation) {
                throw DrawReconciliationException::notFound((string) $reconciliation->reconciliation_key);
            }

            if ($locked->status === DrawReconciliationStatus::Resolved) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(DrawReconciliationStatus::Resolved)) {
                throw DrawReconciliationException::unresolvedDrift((int) $locked->draw_id);
            }

            $locked->status = DrawReconciliationStatus::Resolved;
            $locked->resolved_note = \Illuminate\Support\Str::limit(trim($note), 255, '');
            $locked->resolved_at = now();
            $locked->save();

            $this->recordAudit($locked, sprintf('Resolved by the judge (%s)', $locked->resolved_note), RiskLevel::High);

            return $locked;
        });
    }

    /* ------------------------------------------------- gate -------- */

    /**
     * Consumer gate: refuse the money path whenever this draw has any
     * UNRESOLVED reconciliation pending — the judge must speak first.
     *
     * @throws DrawReconciliationException
     */
    public function assertNoUnresolvedDrift(int $drawId): void
    {
        $open = DrawReconciliation::query()
            ->where('draw_id', $drawId)
            ->where('status', DrawReconciliationStatus::DriftDetected->value)
            ->exists();

        if ($open) {
            throw DrawReconciliationException::unresolvedDrift($drawId);
        }
    }

    /**
     * The THROWING lane for packs: while reconcile() pronounces lane-vs-
     * lane disagreement as drift lines, this gate refuses the act with the
     * per-lane exception vocabulary when the pack itself disagrees with
     * incontrovertible fact — the throwing sibling of the drift lanes,
     * for consumers that must fail closed rather than diagnose.
     *
     * @throws DrawReconciliationException
     */
    public function assertTotalsAgree(DrawReconciliationData $data): void
    {
        $draw = Draw::query()->find($data->drawId);

        if (! $draw instanceof Draw) {
            throw DrawReconciliationException::notFound((string) $data->drawId);
        }

        $ledgerFingerprint = DrawCertificationService::fingerprintFor($data->drawId);
        $assertedFingerprint = DrawCertificationData::canonicalFingerprint($data->drawId, $data->winningNumbers);

        if ($ledgerFingerprint === null || ! hash_equals($ledgerFingerprint, $assertedFingerprint)) {
            throw DrawReconciliationException::resultMismatch($data->drawId);
        }

        $actual = $this->actualTotals($data->drawId);

        if (!$this->within($data->expectedWinners, $actual['winner_bets'])) {
            throw DrawReconciliationException::ticketMismatch($data->drawId, $data->expectedWinners, $actual['winner_bets']);
        }

        if (!$this->within($data->expectedPrizeSettled, $actual['prize_owed'])
            || !$this->within($data->expectedPrizeSettled, $actual['claims_approved'])) {
            throw DrawReconciliationException::prizeMismatch(
                $data->drawId,
                $data->expectedPrizeSettled,
                sprintf('owed:%s claims:%s', $actual['prize_owed'], $actual['claims_approved']),
            );
        }
    }

    /* ------------------------------------------------- internals --- */

    /**
     * Every lane's CURRENT numbers, gathered read-only.
     *
     * @return array{winner_bets: string, prize_owed: string, claims_approved: string, payouts_paid: string}
     */
    private function actualTotals(int $drawId): array
    {
        $winnerBets = (int) \App\Models\Bet::query()
            ->where('draw_id', $drawId)
            ->where('status', \App\Enums\BetStatus::Won->value)
            ->count();

        $prizeOwed = (string) \App\Models\Bet::query()
            ->where('draw_id', $drawId)
            ->where('status', \App\Enums\BetStatus::Won->value)
            ->sum('actual_payout');

        // Prize settlements the claim court has adjudicated: payouts whose
        // claim lane carries the Approved stamp.
        $claimsApproved = (string) \App\Models\Payout::query()
            ->where('draw_id', $drawId)
            ->where('metadata->claim->status', \App\Enums\PrizeClaimStatus::Approved->value)
            ->sum('amount');

        $payoutsPaid = (string) \App\Models\Payout::query()
            ->where('draw_id', $drawId)
            ->where('status', \App\Enums\PayoutStatus::Completed->value)
            ->sum('amount');

        return [
            'winner_bets' => (string) $winnerBets,
            'prize_owed' => $prizeOwed,
            'claims_approved' => $claimsApproved,
            'payouts_paid' => $payoutsPaid,
        ];
    }

    /**
     * Compare two decimal strings within the pack's tolerance (exact math,
     * bcmath when available).
     */
    private function within(string $expected, string $actual): bool
    {
        if (extension_loaded('bcmath')) {
            return bccomp($expected, $actual, 2) === 0;
        }

        return number_format((float) $expected, 2, '.', '') === number_format((float) $actual, 2, '.', '');
    }

    private function recordAudit(DrawReconciliation $reconciliation, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => DrawReconciliation::class,
            'auditable_id' => (int) $reconciliation->getKey(),
            'description' => sprintf('%s (reconciliation %s)', $description, $reconciliation->reconciliation_key ?? '(pending)'),
            'metadata' => [
                'reconciliation_key' => $reconciliation->reconciliation_key,
                'draw_id' => (int) $reconciliation->draw_id,
                'drift_lines' => is_array($reconciliation->drift_lines) ? count($reconciliation->drift_lines) : 0,
                'lane' => 'draw-reconciliation',
            ],
        ]);

        $log->save();
    }
}
