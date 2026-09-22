<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Events\PrizeMatched;
use App\Models\AuditLog;
use App\Models\Payout;
use App\Models\PrizeDisbursement;
use App\Models\PrizeMatch;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;

/**
 * The immutable audit scribe of the prize-money lane.
 *
 * CONTRACT — TWO ADMITS
 *  1. PrizeMatched event: exactly one audit row per match conversation —
 *     amount, tier, and result fingerprint of the matched paper.
 *  2. The settlement court's own acts (reservation / disbursement /
 *     reversal / failure): driven DIRECTLY from the settlement service,
 *     because money acts must NEVER ride a fire-and-forget dispatch —
 *     a lost evidence row for moved money is unacceptable. Anchor:
 *     sha256('prize-disb-audit:{payout_id}:{disbursement_id|self}:{act}'),
 *     exactly one row per (payout, act), retries never multiply rows.
 *
 * Sensitive facts stay scrubbed: references, acts, amounts, fingerprints;
 * never contact/delivery details.
 */
final class RecordPrizeDisbursementAudit
{
    public function __construct() {}

    /* -------------------------------------------- event subscription --- */

    public function handle(PrizeMatched $event): void
    {
        $match = PrizeMatch::query()
            ->where('match_key', $event->matchKey)
            ->first();

        if (! $match instanceof PrizeMatch) {
            Log::warning('RecordPrizeDisbursementAudit: event for missing match', [
                'match_key' => substr($event->matchKey, 0, 12),
            ]);

            return;
        }

        $anchor = PrizeMatched::anchorFor($event->matchKey, 'matched');

        if (self::rowExistsFor(PrizeMatch::class, (int) $match->getKey(), $anchor)) {
            return; // replay: never mint a duplicate audit row
        }

        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Create,
            'risk_level' => RiskLevel::High,
            'auditable_type' => PrizeMatch::class,
            'auditable_id' => (int) $match->getKey(),
            'description' => sprintf(
                'Prize matched: tier [%s] %s (fingerprint %s...)',
                $event->prizeTier,
                $event->matchedAmount,
                substr($event->resultFingerprint, 0, 12),
            ),
            'metadata' => [
                'anchor' => $anchor,
                'match_key' => substr($event->matchKey, 0, 12),
                'draw_id' => $this->cleanId($event->drawId),
                'bet_id' => $this->cleanId($event->betId),
                'tier' => $event->prizeTier,
                'amount' => $event->matchedAmount,
                'result_fingerprint' => $event->resultFingerprint,
                'actor' => 'match-court',
                'at' => $event->matchedAt,
                'lane' => 'prize-disbursement',
            ],
        ]);

        $log->save();
    }

    /* -------------------------------------------- settlement acts ------ */

    /**
     * Direct settlement wiring — ALWAYS called synchronously by the
     * settlement court inside its own transaction (before it commits).
     * anchor-deduped: retry-safe inside the audit surface anyway.
     */
    public function from(
        ?Payout $payout,
        string $act,
        string $amount,
        string $fingerprint,
        string $actorNote,
        ?int $disbursementId = null,
    ): void {
        $anchor = self::anchorForAct(
            $payout instanceof Payout ? (int) $payout->getKey() : 0,
            $disbursementId ?? 0,
            $act,
        );

        $type = PrizeDisbursement::class;
        $id = $disbursementId ?? 0;

        $exists = $id > 0
            ? AuditLog::query()
                ->where('auditable_type', $type)
                ->where('auditable_id', $id)
                ->where('metadata->anchor', $anchor)
                ->exists()
            : AuditLog::query()
                ->where('metadata->anchor', $anchor)
                ->where('lane', 'prize-disbursement')
                ->exists();

        if ($exists) {
            return;
        }

        $log = new AuditLog;
        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Critical,
            'auditable_type' => $type,
            'auditable_id' => $id > 0 ? $id : ($payout?->getKey() ?? 0),
            'description' => sprintf(
                'Settlement act [%s] on payout #%d: %s (fingerprint %s...)',
                $act,
                $payout?->getKey() ?? 0,
                $amount,
                substr($fingerprint, 0, 12),
            ),
            'metadata' => [
                'anchor' => $anchor,
                'payout_id' => $payout?->getKey(),
                'disbursement_id' => $disbursementId,
                'act' => $act,
                'amount' => $amount,
                'settlement_fingerprint' => $fingerprint,
                'actor' => substr($actorNote, 0, 128),
                'at' => now()->toIso8601String(),
                'lane' => 'prize-disbursement',
            ],
        ]);

        $log->save();
    }

    /**
     * The deterministic anchor for settlement acts.
     */
    public static function anchorForAct(int $payoutId, int $disbursementId, string $act): string
    {
        return hash('sha256', sprintf('prize-disb-audit:%d:%d:%s', $payoutId, $disbursementId, $act));
    }

    private static function rowExistsFor(string $class, int $id, string $anchor): bool
    {
        return AuditLog::query()
            ->where('auditable_type', $class)
            ->where('auditable_id', $id)
            ->where('metadata->anchor', $anchor)
            ->exists();
    }

    private function cleanId(int|string|null|DateTimeInterface|bool $v): int
    {
        return (int) $v;
    }
}
