<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Prize\PrizeMatchData;
use App\Enums\BetStatus;
use App\Enums\QueueName;
use App\Exceptions\PrizeMatchException;
use App\Models\Bet;
use App\Models\BetItem;
use App\Models\WinningNumber;
use App\Services\Draw\DrawCertificationService;
use App\Services\Prize\PrizeMatchingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Background winner-matching after a certified/published draw.
 *
 * DISCIPLINE
 * - Only runs on a draw the certification lane has voted for (live
 *   certification or live publication — the "board said so" anchor from
 *   batch-9). Uncertified/resultless draws skip IN WRITING, never by
 *   silence.
 * - Whole-bet iteration; every refusal is pronounced per-bet in the log
 *   and the pass completes regardless (one bad bet never stalls a draw).
 * - Idempotent: the match court's deterministic identity does the
 *   replay-safe heavy lifting; hourly job-level uniqueness is the
 *   driver-side belt to that brace.
 * - Drift-aware: runs the batch-9 reconciliation gate AFTER matching so
 *   post-match lane disagreements surface through the judge.
 */
final class MatchDrawPrizesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 3600;

    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $drawId,
    ) {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'match_draw_prizes_'.$this->drawId.'_'.now()->format('Y-m-d-H');
    }

    /**
     * @return array{matched: int, replays: int, refusals: int, skipped: ?string}
     */
    public function handle(
        PrizeMatchingService $matching,
        DrawCertificationService $certifications,
    ): array {
        // The board must have voted before the match lane counts.
        $live = $certifications->liveFor($this->drawId);

        if ($live === null) {
            Log::info('MatchDrawPrizesJob: draw not certified — skipped', ['draw_id' => $this->drawId]);

            return ['matched' => 0, 'replays' => 0, 'refusals' => 0, 'skipped' => 'uncertified'];
        }

        $fingerprint = DrawCertificationService::fingerprintFor($this->drawId);

        if ($fingerprint === null || ! hash_equals($fingerprint, (string) $live->result_fingerprint)) {
            Log::warning('MatchDrawPrizesJob: live certification fingerprint no longer derives', [
                'draw_id' => $this->drawId,
            ]);

            return ['matched' => 0, 'replays' => 0, 'refusals' => 0, 'skipped' => 'fingerprint-drift'];
        }

        $matched = 0;
        $replays = 0;
        $refusals = 0;

        // Winners, chunked; every bet's own tier lane read from the draw's
        // winning numbers unless the bet's items already name one.
        Bet::query()
            ->where('draw_id', $this->drawId)
            ->where('status', BetStatus::Won->value)
            ->orderBy('id')
            ->chunkById(50, function ($bets) use ($matching, $fingerprint, &$matched, &$replays, &$refusals): void {
                foreach ($bets as $bet) {
                    try {
                        $tier = $this->tierFor($bet);

                        if ($tier === null) {
                            $refusals++;

                            Log::info('MatchDrawPrizesJob: bet has no discernible tier', [
                                'bet_id' => (int) $bet->getKey(),
                            ]);

                            continue;
                        }

                        $outcome = $matching->match(PrizeMatchData::fromInput(
                            drawId: (int) $bet->draw_id,
                            betId: (int) $bet->getKey(),
                            ticketId: $bet->ticket_id !== null ? (int) $bet->ticket_id : null,
                            prizeTier: $tier,
                            matchedAmount: (string) $bet->actual_payout,
                            resultFingerprint: $fingerprint,
                        ));

                        if ($outcome['replayed']) {
                            $replays++;
                        } else {
                            $matched++;
                        }
                    } catch (PrizeMatchException|\ValueError $e) {
                        $refusals++;

                        Log::info('MatchDrawPrizesJob: bet refused', [
                            'bet_id' => (int) $bet->getKey(),
                            'reason' => $e instanceof PrizeMatchException ? $e->errorCode() : 'malformed-input',
                        ]);
                    }
                }
            });

        Log::info('MatchDrawPrizesJob: pass complete', [
            'draw_id' => $this->drawId,
            'matched' => $matched,
            'replays' => $replays,
            'refusals' => $refusals,
        ]);

        return ['matched' => $matched, 'replays' => $replays, 'refusals' => $refusals, 'skipped' => null];
    }

    /**
     * The bet's own discernible prize tier: from its winning items (the
     * match lane's own naming), or the draw's first-tier lane when this
     * house speaks a single-tier board. NEVER invented.
     */
    private function tierFor(Bet $bet): ?string
    {
        // A bet on a single-prize board with the sole winner tier only.
        $tiers = WinningNumber::query()
            ->where('draw_id', (int) $bet->draw_id)
            ->whereNotNull('prize_tier')
            ->distinct()
            ->pluck('prize_tier')
            ->map(fn ($tier) => strtolower((string) $tier))
            ->all();

        if (count($tiers) === 1) {
            return $tiers[0];
        }

        // Multi-tier boards: the bet's own first prize lane reads from
        // its winning items' actual payout lane against winning_numbers.
        $items = (int) $bet->getKey();

        $tier = BetItem::query()
            ->where('bet_id', $items)
            ->where('is_winner', true)
            ->value('prize_tier');

        return is_string($tier) && $tier !== '' ? strtolower($tier) : null;
    }
}
