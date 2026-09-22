<?php

declare(strict_types=1);

namespace App\Services\Prize;

use App\DTOs\Prize\PrizeMatchData;
use App\Enums\AuditAction;
use App\Enums\PrizeMatchStatus;
use App\Enums\RiskLevel;
use App\Events\PrizeMatched;
use App\Exceptions\PrizeMatchException;
use App\Models\AuditLog;
use App\Models\Bet;
use App\Models\Draw;
use App\Models\PrizeMatch;
use App\Services\Draw\DrawCertificationService;
use Illuminate\Support\Facades\DB;

/**
 * The matching court: deterministically matches CONFIRMED draw results
 * against winning tickets, and only that.
 *
 * THE INVARIANTS, IN REFUSAL ORDER
 *   1. EXISTENCE  — draw + bet + (named) ticket all live on the ledger.
 *   2. BELONGING — bet sits ON that draw; a named ticket owns that bet;
 *   3. RESULT    — the presented result fingerprint equals the court-
 *      derived one over the draw's winning_numbers (batch-9 canonical
 *      formula). You match against the certified paper, not against
 *      speech.
 *   4. WINNER   — the bet is in Won status; nothing else gets a match.
 *   5. TIER     — the named tier is present in the draw's own result lane.
 *   6. AMOUNT   — the claimed matched amount equals the ledger's own
 *      actual payout on the bet, exact bcmath, zero tolerance.
 *   7. IDENTITY — deterministic key; same ask re-serves (replay), a
 *      divergent ask against the same (bet, tier) is a fork.
 *
 * The event fires exactly once per NEW conversation, after commit.
 */
final class PrizeMatchingService
{
    /* ----------------------------------------------------- match ---- */

    /**
     * @return array{match: PrizeMatch, replayed: bool}
     *
     * @throws PrizeMatchException
     */
    public function match(PrizeMatchData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->matchWithin($data);
        }

        return DB::transaction(fn (): array => $this->matchWithin($data));
    }

    /**
     * @return array{match: PrizeMatch, replayed: bool}
     *
     * @throws PrizeMatchException
     */
    private function matchWithin(PrizeMatchData $data): array
    {
        // REPLAY anchor first: same conversation = same row.
        $existing = PrizeMatch::query()
            ->lockForUpdate()
            ->where('match_key', $data->matchKey())
            ->first();

        if ($existing instanceof PrizeMatch) {
            if ($existing->status === PrizeMatchStatus::Rejected) {
                // A rejected match conversation can't quietly re-mint.
                throw PrizeMatchException::duplicate($data->matchKey());
            }

            return ['match' => $existing, 'replayed' => true];
        }

        /** @var Draw|null $draw */
        $draw = Draw::query()->lockForUpdate()->find($data->drawId);

        if (! $draw instanceof Draw) {
            throw PrizeMatchException::notFound('draw:'.$data->drawId);
        }

        /** @var Bet|null $bet */
        $bet = Bet::query()->lockForUpdate()->find($data->betId);

        if (! $bet instanceof Bet) {
            throw PrizeMatchException::notFound('bet:'.$data->betId);
        }

        if ((int) $bet->draw_id !== $data->drawId) {
            throw PrizeMatchException::wrongDraw($data->betId, (int) $bet->draw_id, $data->drawId);
        }

        if ($data->ticketId !== null && (int) $bet->ticket_id !== $data->ticketId) {
            throw PrizeMatchException::wrongDraw($data->betId, (int) ($bet->ticket_id ?? 0), $data->ticketId);
        }

        // RESULT admission: the fingerprint must corroborate.
        $courtFingerprint = DrawCertificationService::fingerprintFor($data->drawId);

        if ($courtFingerprint === null || ! hash_equals($courtFingerprint, $data->resultFingerprint)) {
            throw PrizeMatchException::malformed(
                'the presented result fingerprint corroborates no paper on the draw',
                ['draw_id' => $data->drawId],
            );
        }

        if (!$bet->isWinning()) {
            throw PrizeMatchException::notWinner($data->betId, (string) ($bet->status?->value ?? (string) $bet->status));
        }

        // TIER lives in the draw's own result lane, or not at all.
        $tierPresent = \App\Models\WinningNumber::query()
            ->where('draw_id', $data->drawId)
            ->where('prize_tier', $data->prizeTier)
            ->exists();

        if (!$tierPresent) {
            throw PrizeMatchException::invalidTier($data->prizeTier, $data->drawId);
        }

        // AMOUNT is arithmetic between two lanes; exact or refused.
        $ledgerAmount = (string) $bet->actual_payout;

        if (bccomp($data->matchedAmount, $this->moneyOf($ledgerAmount), 2) !== 0) {
            throw PrizeMatchException::amountMismatch($data->betId, $ledgerAmount, $data->matchedAmount);
        }

        // Same (bet, tier) in a DIFFERENT conversation = fork.
        $sameBetTier = PrizeMatch::query()
            ->lockForUpdate()
            ->where('bet_id', $data->betId)
            ->where('prize_tier', $data->prizeTier)
            ->where('status', '!=', PrizeMatchStatus::Rejected->value)
            ->first();

        if ($sameBetTier instanceof PrizeMatch) {
            throw PrizeMatchException::duplicate($data->matchKey());
        }

        $match = new PrizeMatch();
        $match->fill([
            'match_key' => $data->matchKey(),
            'draw_id' => $data->drawId,
            'bet_id' => (int) $bet->getKey(),
            'ticket_id' => $data->ticketId,
            'prize_tier' => $data->prizeTier,
            'matched_amount' => $this->moneyOf($data->matchedAmount),
            'result_fingerprint' => $data->resultFingerprint,
            'metadata' => [],
        ]);
        $match->status = PrizeMatchStatus::Matched;
        $match->save();

        $this->recordAudit($match, sprintf(
            'Matched bet #%d → tier [%s] for %s (draw #%d)',
            $data->betId,
            $data->prizeTier,
            $data->matchedAmount,
            $data->drawId,
        ), RiskLevel::High);

        $matchedAt = now()->toIso8601String();
        $matchKey = $data->matchKey();

        DB::afterCommit(function () use ($data, $bet, $matchKey, $matchedAt): void {
            event(new PrizeMatched(
                matchKey: $matchKey,
                drawId: $data->drawId,
                betId: (int) $bet->getKey(),
                ticketId: $data->ticketId,
                prizeTier: $data->prizeTier,
                matchedAmount: $data->matchedAmount,
                resultFingerprint: $data->resultFingerprint,
                matchedAt: $matchedAt,
            ));
        });

        return ['match' => $match, 'replayed' => false];
    }

    /* --------------------------------------------------- verify ----- */

    /**
     * Confirmatory act: a matched record becomes Verified.
     *
     * @throws PrizeMatchException
     */
    public function verify(PrizeMatch $match): PrizeMatch
    {
        return DB::transaction(function () use ($match): PrizeMatch {
            /** @var PrizeMatch|null $locked */
            $locked = PrizeMatch::query()->lockForUpdate()->find((int) $match->getKey());

            if (! $locked instanceof PrizeMatch) {
                throw PrizeMatchException::notFound((string) $match->match_key);
            }

            if ($locked->status === PrizeMatchStatus::Verified) {
                return $locked; // replay
            }

            if (!$locked->status->canTransitionTo(PrizeMatchStatus::Verified)) {
                throw PrizeMatchException::notFound((string) $locked->match_key.' (state '.$locked->status->value.')');
            }

            $locked->status = PrizeMatchStatus::Verified;
            $locked->verified_at = now();
            $locked->save();

            $this->recordAudit($locked, 'Verified by a second pair of eyes', RiskLevel::Medium);

            return $locked;
        });
    }

    /**
     * Pronounce a match bad, forever.
     *
     * @throws PrizeMatchException
     */
    public function reject(PrizeMatch $match, string $reason): PrizeMatch
    {
        return DB::transaction(function () use ($match, $reason): PrizeMatch {
            /** @var PrizeMatch|null $locked */
            $locked = PrizeMatch::query()->lockForUpdate()->find((int) $match->getKey());

            if (! $locked instanceof PrizeMatch) {
                throw PrizeMatchException::notFound((string) $match->match_key);
            }

            if ($locked->status === PrizeMatchStatus::Rejected) {
                return $locked;
            }

            if (!$locked->status->canTransitionTo(PrizeMatchStatus::Rejected)) {
                throw PrizeMatchException::notFound((string) $locked->match_key.' (state '.$locked->status->value.')');
            }

            $locked->status = PrizeMatchStatus::Rejected;
            $locked->rejected_at = now();
            $locked->rejected_reason = \Illuminate\Support\Str::limit(trim($reason), 255, '');
            $locked->save();

            $this->recordAudit($locked, sprintf('Rejected (%s)', $locked->rejected_reason), RiskLevel::High);

            return $locked;
        });
    }

    /* --------------------------------------------------- internals -- */

    private function moneyOf(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    private function recordAudit(PrizeMatch $match, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Create,
            'risk_level' => $riskLevel,
            'auditable_type' => PrizeMatch::class,
            'auditable_id' => (int) $match->getKey(),
            'description' => sprintf('%s (match %s...)', $description, substr((string) $match->match_key, 0, 12)),
            'metadata' => [
                'match_key' => (string) $match->match_key,
                'draw_id' => (int) $match->draw_id,
                'bet_id' => (int) $match->bet_id,
                'tier' => (string) $match->prize_tier,
                'lane' => 'prize-matching',
            ],
        ]);

        $log->save();
    }
}
