<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\Draw\DrawCertificationData;
use App\Enums\AuditAction;
use App\Enums\DrawCertificationStatus;
use App\Enums\RiskLevel;
use App\Events\DrawResultCertified;
use App\Exceptions\DrawCertificationException;
use App\Models\AuditLog;
use App\Models\Draw;
use App\Models\DrawCertification;
use Illuminate\Support\Facades\DB;

/**
 * The certification court: atomic official-result certification.
 *
 * THE INVARIANTS, IN REFUSAL ORDER
 *   1. EXISTENCE  — the draw exists and carries winning numbers at all
 *      (never certify air: a draw with no ingested numbers has nothing
 *      to sign).
 *   2. TRUTH     — the presented fingerprint must equal the one the court
 *      re-derives from the draw's own winning_numbers rows; the payload
 *      is never trusted on speech.
 *   3. SINGULARITY — exactly one LIVE certification per draw; a divergent
 *      paper needs supersession, a pronouncedly different act.
 *   4. REPLAY    — the same act (deterministic key over draw+certifier+
 *      fingerprint) re-serves its row; nothing is written twice.
 *
 * Every move is a locked transaction; the audit scribe runs off the
 * afterCommit event exactly once.
 */
final class DrawCertificationService
{
    /* --------------------------------------------------- certify --- */

    /**
     * @return array{certification: DrawCertification, replayed: bool}
     *
     * @throws DrawCertificationException
     */
    public function certify(DrawCertificationData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->certifyWithin($data);
        }

        return DB::transaction(fn (): array => $this->certifyWithin($data));
    }

    /**
     * @return array{certification: DrawCertification, replayed: bool}
     *
     * @throws DrawCertificationException
     */
    private function certifyWithin(DrawCertificationData $data): array
    {
        /** @var Draw|null $draw */
        $draw = Draw::query()->lockForUpdate()->find($data->drawId);

        if (! $draw instanceof Draw) {
            throw DrawCertificationException::notFound((string) $data->drawId);
        }

        $certifiedFingerprint = self::fingerprintFor($data->drawId);

        if ($certifiedFingerprint === null) {
            throw DrawCertificationException::stateForbids(
                $data->drawId,
                'no WinningNumbers',
                'certify (there is no paper to sign)',
            );
        }

        if (! hash_equals($certifiedFingerprint, $data->resultFingerprint)) {
            throw DrawCertificationException::fingerprintMismatch($data->drawId);
        }

        // REPLAY: the same act re-serves.
        $existing = DrawCertification::query()
            ->lockForUpdate()
            ->where('certification_key', $data->certificationKey())
            ->first();

        if ($existing instanceof DrawCertification) {
            return ['certification' => $existing, 'replayed' => true];
        }

        // SINGULARITY: a live certification of different make requires
        // the supersession lane, never a quiet second act.
        $live = DrawCertification::query()
            ->lockForUpdate()
            ->where('draw_id', $data->drawId)
            ->whereIn('status', [
                DrawCertificationStatus::PendingReview->value,
                DrawCertificationStatus::Certified->value,
                DrawCertificationStatus::Published->value,
            ])
            ->first();

        if ($live instanceof DrawCertification) {
            throw DrawCertificationException::duplicate(
                $data->certificationKey(),
                $data->drawId,
            );
        }

        $certification = new DrawCertification();
        $certification->fill([
            'certification_key' => $data->certificationKey(),
            'draw_id' => $draw->getKey(),
            'source_type' => $data->source,
            'result_fingerprint' => $data->resultFingerprint,
            'winning_numbers' => self::winningNumbersSnapshot($data->drawId),
            'certifier_reference' => $data->certifierReference,
            'certified_at' => $data->certifiedAt,
            'metadata' => [],
        ]);
        $certification->status = DrawCertificationStatus::Certified;
        $certification->save();

        $this->recordAudit(
            $certification,
            sprintf('Certified by [%s] from %s', $data->certifierReference, $data->source->value),
            RiskLevel::Critical,
        );

        $drawReference = (string) $draw->draw_number;

        DB::afterCommit(function () use ($certification, $data, $draw, $drawReference): void {
            event(new DrawResultCertified(
                certificationKey: (string) $certification->certification_key,
                drawId: $data->drawId,
                drawReference: $drawReference,
                resultFingerprint: $data->resultFingerprint,
                source: $data->source,
                certifierReference: $data->certifierReference,
                certifiedAt: $data->certifiedAt,
            ));
        });

        return ['certification' => $certification, 'replayed' => false];
    }

    /* ------------------------------------------------- supersede --- */

    /**
     * Rotating a certified paper out from under the board: the OLD live
     * certification is marked Superseded and a NEW one is certified in
     * the same transaction — history kept, board atomically turned.
     *
     * @return array{old: DrawCertification, new: DrawCertification}
     *
     * @throws DrawCertificationException
     */
    public function supersede(DrawCertification $certification, DrawCertificationData $replacement): array
    {
        return DB::transaction(function () use ($certification, $replacement): array {
            /** @var DrawCertification|null $old */
            $old = DrawCertification::query()->lockForUpdate()->find((int) $certification->getKey());

            if (! $old instanceof DrawCertification) {
                throw DrawCertificationException::notFound((string) $certification->certification_key);
            }

            if (!$old->status->maySupersede()) {
                throw DrawCertificationException::stateForbids(
                    (int) $old->draw_id,
                    $old->status->value,
                    'supersede',
                );
            }

            // A LIVE BOARD must never be superseded out from under the
            // public's feet: retract the live publication first, THEN
            // rotate the certification. The rule is cross-lane and
            // structural.
            $boardLive = \App\Models\DrawPublication::query()
                ->where('draw_id', (int) $old->draw_id)
                ->whereIn('status', [
                    \App\Enums\DrawPublicationStatus::Published->value,
                    \App\Enums\DrawPublicationStatus::RePublished->value,
                ])
                ->exists();

            if ($boardLive) {
                throw DrawCertificationException::stateForbids(
                    (int) $old->draw_id,
                    'board-live',
                    'supersede (retract the live publication row first)',
                );
            }

            // The replacement must certify against the SAME draw and
            // the draw's CURRENT paper — and its fingerprint must match
            // the live numbers (the point of supersession is that the
            // paper was corrected first).
            if ($replacement->drawId !== (int) $old->draw_id) {
                throw DrawCertificationException::stateForbids(
                    (int) $old->draw_id,
                    $old->status->value,
                    'supersede across draws',
                );
            }

            $recomputed = self::fingerprintFor((int) $old->draw_id);

            if ($recomputed === null || ! hash_equals($recomputed, $replacement->resultFingerprint)) {
                throw DrawCertificationException::fingerprintMismatch((int) $old->draw_id);
            }

            $newKey = $replacement->certificationKey();

            $exists = DrawCertification::query()->where('certification_key', $newKey)->exists();

            if (!$exists) {
                $new = new DrawCertification();
                $new->fill([
                    'certification_key' => $newKey,
                    'draw_id' => (int) $old->draw_id,
                    'source_type' => $replacement->source,
                    'result_fingerprint' => $replacement->resultFingerprint,
                    'winning_numbers' => self::winningNumbersSnapshot((int) $old->draw_id),
                    'certifier_reference' => $replacement->certifierReference,
                    'certified_at' => $replacement->certifiedAt,
                    'metadata' => [],
                ]);
                $new->status = DrawCertificationStatus::Certified;
                $new->save();
            } else {
                /** @var DrawCertification $new */
                $new = DrawCertification::query()->where('certification_key', $newKey)->firstOrFail();
            }

            $old->status = DrawCertificationStatus::Superseded;
            $old->superseded_by_key = $newKey;
            $old->save();

            $this->recordAudit(
                $new,
                sprintf('Superseded %s... from %s', substr((string) $old->certification_key, 0, 8), $replacement->source->value),
                RiskLevel::Critical,
            );

            return ['old' => $old, 'new' => $new];
        });
    }

    /* --------------------------------------------------- reads ----- */

    /**
     * The live certification for a draw, if any.
     */
    public function liveFor(int $drawId): ?DrawCertification
    {
        return DrawCertification::query()
            ->where('draw_id', $drawId)
            ->whereIn('status', [
                DrawCertificationStatus::Certified->value,
                DrawCertificationStatus::Published->value,
            ])
            ->latest('id')
            ->first();
    }

    /**
     * The fingerprint the court would derive RIGHT NOW for this draw —
     * null when the draw has no ingested numbers at all.
     */
    public static function fingerprintFor(int $drawId): ?string
    {
        $rows = self::winningNumbersSnapshot($drawId);

        if ($rows === []) {
            return null;
        }

        return DrawCertificationData::canonicalFingerprint($drawId, $rows);
    }

    /**
     * The court's own read of the numbers: raw rows ready for
     * canonicalization. Read-only by contract.
     *
     * @return array<int, array{bet_type: string, prize_tier: ?string, position: ?string, number: string}>
     */
    public static function winningNumbersSnapshot(int $drawId): array
    {
        return \App\Models\WinningNumber::query()
            ->where('draw_id', $drawId)
            ->orderBy('id')
            ->get(['bet_type', 'prize_tier', 'position', 'number'])
            ->map(fn (\App\Models\WinningNumber $row): array => [
                // bet_type is enum-cast on the model; the canonical lane
                // speaks plain strings.
                'bet_type' => $row->bet_type instanceof \BackedEnum ? (string) $row->bet_type->value : (string) $row->bet_type,
                'prize_tier' => $row->prize_tier !== null ? (string) $row->prize_tier : null,
                'position' => $row->position !== null ? (string) $row->position : null,
                'number' => (string) $row->number,
            ])
            ->all();
    }

    /* ------------------------------------------------ internals ---- */

    private function recordAudit(DrawCertification $certification, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Create,
            'risk_level' => $riskLevel,
            'auditable_type' => DrawCertification::class,
            'auditable_id' => (int) $certification->getKey(),
            'description' => sprintf('%s (certification %s...)', $description, substr((string) $certification->certification_key, 0, 12)),
            'metadata' => [
                'certification_key' => (string) $certification->certification_key,
                'draw_id' => (int) $certification->draw_id,
                'artifact' => 'draw_result',
                'lane' => 'draw-certification',
            ],
        ]);

        $log->save();
    }
}
