<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\DTOs\Draw\DrawPublicationData;
use App\Enums\AuditAction;
use App\Enums\DrawCertificationStatus;
use App\Enums\DrawPublicationStatus;
use App\Enums\RiskLevel;
use App\Exceptions\DrawPublicationException;
use App\Models\AuditLog;
use App\Models\DrawCertification;
use App\Models\DrawPublication;
use Illuminate\Support\Facades\DB;

/**
 * The board: publishes/retracts/re-publishes certified results with
 * immutable version + fingerprint controls.
 *
 * THE INVARIANTS, IN REFUSAL ORDER
 *   1. ANCHOR   — the named certification exists ON the ledger.
 *   2. BOARD SANITY — Certified-only publication; the board never eats
 *      Draft / PendingReview / Superseded paper.
 *   3. FINGERPRINT AGREEMENT — the presented fingerprint must equal the
 *      certification's own (the board publishes exactly what the court
 *      certified, no substitutes).
 *   4. VERSION MONOTONIC — one board line per version, new versions at
 *      exactly max+1; stale asks refuse with the expected number
 *      pronounced.
 *   5. ONE LIVE ROW — a draw has at most one status-live publication
 *      (Published/RePublished) at a time; a new live row requires the
 *      previous one retracted first.
 *   6. REPLAY — the same publication (deterministic key over draw+version+
 *      fingerprint) re-serves; nothing else written.
 */
final class DrawPublicationService
{
    /* --------------------------------------------------- publish --- */

    /**
     * @return array{publication: DrawPublication, replayed: bool}
     *
     * @throws DrawPublicationException
     */
    public function publish(DrawPublicationData $data): array
    {
        if (DB::transactionLevel() > 0) {
            return $this->publishWithin($data);
        }

        return DB::transaction(fn (): array => $this->publishWithin($data));
    }

    /**
     * @return array{publication: DrawPublication, replayed: bool}
     *
     * @throws DrawPublicationException
     */
    private function publishWithin(DrawPublicationData $data): array
    {
        // REPLAY first: the same board act re-serves.
        $existing = DrawPublication::query()
            ->lockForUpdate()
            ->where('publication_key', $data->publicationKey())
            ->first();

        if ($existing instanceof DrawPublication) {
            return ['publication' => $existing, 'replayed' => true];
        }

        /** @var DrawCertification|null $certification */
        $certification = DrawCertification::query()
            ->lockForUpdate()
            ->where('certification_key', $data->certificationKey)
            ->first();

        if (! $certification instanceof DrawCertification) {
            throw DrawPublicationException::notFound($data->certificationKey);
        }

        if (! $certification->status->isPublishable()) {
            throw DrawPublicationException::uncertified(
                $data->drawId,
                $certification->status->value,
            );
        }

        if ((int) $certification->draw_id !== $data->drawId) {
            throw DrawPublicationException::malformed(
                'the certification belongs to a different draw',
            );
        }

        if (! hash_equals((string) $certification->result_fingerprint, $data->resultFingerprint)) {
            throw DrawPublicationException::malformed(
                'the presented fingerprint agrees with no certified paper for this draw',
            );
        }

        // VERSION MONOTONIC.
        $next = ((int) DrawPublication::query()
            ->where('draw_id', $data->drawId)
            ->max('version')) + 1;

        if ($data->version !== $next) {
            throw DrawPublicationException::staleVersion($data->drawId, $data->version, $next);
        }

        // ONE LIVE ROW.
        $live = DrawPublication::query()
            ->lockForUpdate()
            ->where('draw_id', $data->drawId)
            ->whereIn('status', [
                DrawPublicationStatus::Published->value,
                DrawPublicationStatus::RePublished->value,
            ])
            ->first();

        if ($live instanceof DrawPublication) {
            throw DrawPublicationException::alreadyPublished($data->drawId, (int) $live->version);
        }

        $publication = new DrawPublication();
        $publication->fill([
            'publication_key' => $data->publicationKey(),
            'draw_id' => $data->drawId,
            'draw_certification_id' => (int) $certification->getKey(),
            'version' => $data->version,
            'result_fingerprint' => $data->resultFingerprint,
            'published_at' => $data->publishedAt,
            'metadata' => [],
        ]);

        // First publication is 'Published'; a publication that comes AFTER
        // any retracted row on this board is 'RePublished' by definition —
        // history shapes the vocabulary, the board never lies by today.
        $hadretracted = DrawPublication::query()
            ->where('draw_id', $data->drawId)
            ->where('status', DrawPublicationStatus::Retracted->value)
            ->exists();

        $publication->status = $hadretracted
            ? DrawPublicationStatus::RePublished
            : DrawPublicationStatus::Published;
        $publication->save();

        // The certification row itself answers the rotation: Certified —
        // and only Certified — becomes Published (never Superseded).
        $certification->status = $certification->status === DrawCertificationStatus::Certified
            ? DrawCertificationStatus::Published
            : $certification->status;
        $certification->save();

        $this->recordAudit($publication, sprintf(
            '%s version %d (fingerprint %s...)',
            $hadretracted ? 'Re-published' : 'Published',
            $data->version,
            substr($data->resultFingerprint, 0, 12),
        ), RiskLevel::High);

        return ['publication' => $publication, 'replayed' => false];
    }

    /* ------------------------------------------------- republish --- */

    /**
     * Re-publication is its own informed act: it requires a retracted
     * board row to exist (a "republish" with nothing that ever came down
     * is a vocabulary lie and is refused by name), and then rides the
     * board's monotonic-version + one-live-row + fingerprint rules.
     *
     * @return array{publication: DrawPublication, replayed: bool}
     *
     * @throws DrawPublicationException
     */
    public function republish(DrawPublicationData $data): array
    {
        $retreated = DrawPublication::query()
            ->where('draw_id', $data->drawId)
            ->where('status', DrawPublicationStatus::Retracted->value)
            ->exists();

        if (!$retreated) {
            throw DrawPublicationException::stateForbids(
                $data->publicationKey(),
                'no-retracted-row',
                'republish (re-publication requires a retracted board row)',
            );
        }

        $outcome = $this->publish($data);

        // Vocabulary guard of last resort: a re-publication must never
        // answer 'plain Published' to the board.
        if (!$outcome['replayed'] && $outcome['publication']->status !== DrawPublicationStatus::RePublished) {
            throw DrawPublicationException::stateForbids(
                $data->publicationKey(),
                $outcome['publication']->status->value,
                'republish (the board refused to mark it RePublished)',
            );
        }

        return $outcome;
    }

    /* --------------------------------------------------- retract --- */

    /**
     * Pull a live row off the board.
     *
     * @throws DrawPublicationException
     */
    public function retract(DrawPublication $publication, string $reason): DrawPublication
    {
        return DB::transaction(function () use ($publication, $reason): DrawPublication {
            /** @var DrawPublication|null $locked */
            $locked = DrawPublication::query()->lockForUpdate()->find((int) $publication->getKey());

            if (! $locked instanceof DrawPublication) {
                throw DrawPublicationException::notFound((string) $publication->publication_key);
            }

            if ($locked->status === DrawPublicationStatus::Retracted) {
                return $locked; // replay: already down, pronouncedly fine
            }

            if (! $locked->status->mayRetract()) {
                throw DrawPublicationException::stateForbids(
                    (string) $locked->publication_key,
                    $locked->status->value,
                    'retract',
                );
            }

            $locked->status = DrawPublicationStatus::Retracted;
            $locked->retracted_at = now();
            $locked->save();

            $this->recordAudit($locked, sprintf('Retracted (%s)', $reason), RiskLevel::High);

            return $locked;
        });
    }

    /* --------------------------------------------------- reads ----- */

    /**
     * The row the public surface MUST answer from, when one exists.
     */
    public function liveFor(int $drawId): ?DrawPublication
    {
        return DrawPublication::query()
            ->where('draw_id', $drawId)
            ->whereIn('status', [
                DrawPublicationStatus::Published->value,
                DrawPublicationStatus::RePublished->value,
            ])
            ->latest('version')
            ->first();
    }

    /**
     * The most recent row by version, whatever its state (Stale context).
     */
    public function latestFor(int $drawId): ?DrawPublication
    {
        return DrawPublication::query()
            ->where('draw_id', $drawId)
            ->latest('version')
            ->first();
    }

    /**
     * The version the board would accept next (diagnostics + the job).
     */
    public function nextVersion(int $drawId): int
    {
        return ((int) DrawPublication::query()->where('draw_id', $drawId)->max('version')) + 1;
    }

    /* ------------------------------------------------ internals ---- */

    private function recordAudit(DrawPublication $publication, string $description, RiskLevel $riskLevel): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => null,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => DrawPublication::class,
            'auditable_id' => (int) $publication->getKey(),
            'description' => sprintf('%s (draw #%d version %d)', $description, (int) $publication->draw_id, (int) $publication->version),
            'metadata' => [
                'publication_key' => (string) $publication->publication_key,
                'draw_id' => (int) $publication->draw_id,
                'version' => (int) $publication->version,
                'status' => $publication->status?->value,
                'lane' => 'draw-publication',
            ],
        ]);

        $log->save();
    }
}
