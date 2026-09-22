<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\AuditAction;
use App\Enums\DrawConfirmationStatus;
use App\Enums\DrawLifecycleState;
use App\Enums\RiskLevel;
use App\Exceptions\DrawResultException;
use App\Models\AuditLog;
use App\Models\Draw;
use Illuminate\Support\Facades\DB;

/**
 * Input gate for official draw results.
 *
 * THE INGESTION HALF OF THE FOUR-EYES RESULT PIPELINE
 * Results arrive from the official GLO announcement channels: an operator
 * paste, a feed line, a correction. This service validates, canonicalizes,
 * fingerprints and STORES them — and deliberately never publishes. The row it
 * writes lives on the draw itself (metadata.result_ingestion) in
 * DrawConfirmationStatus::Pending, invisible to settlement. Only the
 * confirmation service's second pair of hands can turn the same payload into
 * the published DrawResult that bet settlement later pays against.
 *
 * CANONICALIZATION
 * first_prize: exactly 6 numeric characters (leading zeros preserved as a
 * string). bottom_two: the last two digits of first_prize, DERIVED — GLO's
 * own rule — unless explicitly supplied, after which the two MUST agree. An
 * ingested bottom_two that contradicts first_prize is rejected as malformed:
 * settlement would pay against numbers the announcement never made.
 *
 * IDEMPOTENCY BY FINGERPRINT
 * fingerprint = sha256 of the canonical payload. Re-ingesting the exact same
 * result is a silent no-op returning the recorded state; ingesting a
 * DIFFERENT result while one is still Pending is a correction: the pending
 * record is replaced, with the replaced record preserved in history so the
 * correction is auditable. A Confirmed record may never be replaced from
 * this side — corrections to a published result go through the confirmation
 * service's supersede path (never through a silent re-ingest).
 */
class DrawResultIngestionService
{
    /**
     * The draw metadata key under which the current ingestion record lives.
     */
    public const METADATA_KEY = 'result_ingestion';

    /**
     * Ingest an official result payload for a draw.
     *
     * @param  array{first_prize: string, bottom_two?: string|null}  $payload
     * @param  string  $source  'glo_feed', 'operator', 'correction', ...
     *
     * @return array{
     *     draw_id: int,
     *     status: string,
     *     fingerprint: string,
     *     first_prize: string,
     *     bottom_two: string,
     *     source: string,
     *     ingested_at: string,
     *     no_op: bool
     * }
     *
     * @throws DrawResultException
     */
    public function ingest(int $drawId, array $payload, string $source, ?int $actorUserId = null): array
    {
        return DB::transaction(function () use ($drawId, $payload, $source, $actorUserId): array {
            $draw = Draw::query()->lockForUpdate()->find($drawId);

            if (! $draw instanceof Draw) {
                throw DrawResultException::drawNotFound($drawId);
            }

            $state = DrawLifecycleState::fromDrawStatus($draw->status);

            if (! $this->mayIngestIn($state)) {
                throw DrawResultException::confirmationForbidden(
                    0,
                    $state->value,
                    'ingested a result',
                    ['draw_id' => $drawId],
                );
            }

            $canonical = $this->canonicalize($drawId, $payload, $source);
            $fingerprint = $this->fingerprintFor($canonical);

            $existing = $this->currentRecord($draw);

            if (is_array($existing) && ($existing['fingerprint'] ?? null) === $fingerprint) {
                // Exact replay: file nothing, answer the recorded outcome.
                return $this->resultRow($drawId, $existing, noOp: true);
            }

            if (is_array($existing)
                && ($existing['status'] ?? null) === DrawConfirmationStatus::Confirmed->value
            ) {
                // A confirmed record is immutable from the input side; a real
                // correction supersedes through the confirmation service.
                throw DrawResultException::confirmationForbidden(
                    (int) $drawId,
                    DrawConfirmationStatus::Confirmed->value,
                    're-ingested with different numbers',
                    ['draw_id' => $drawId],
                );
            }

            $history = is_array($metadata = $draw->metadata ?? [])
                ? (is_array($metadata['result_ingestion_history'] ?? null) ? $metadata['result_ingestion_history'] : [])
                : [];

            if (is_array($existing) && ($existing['status'] ?? null) !== DrawConfirmationStatus::Rejected->value) {
                // Superseded-by-replacement: pending correction replaced.
                $existing['status'] = DrawConfirmationStatus::Superseded->value;
                $history[] = $existing;
            }

            $record = [
                'status' => DrawConfirmationStatus::Pending->value,
                'fingerprint' => $fingerprint,
                'source' => $source,
                'payload' => $canonical,
                'ingested_at' => now()->toIso8601String(),
                'ingested_by' => $actorUserId,
            ];

            $metadata = is_array($draw->metadata) ? $draw->metadata : [];
            $metadata[self::METADATA_KEY] = $record;
            $metadata['result_ingestion_history'] = $history;

            $draw->metadata = $metadata;
            $draw->save();

            $this->recordAudit($draw, 'IngestedDrawResult', $record, $actorUserId, 'Ingested new official result into Pending confirmation state');

            return $this->resultRow($drawId, $record, noOp: false);
        });
    }

    /**
     * The current ingestion record for a draw, or null if none has ever been
     * ingested. The stamp lives on the draw row: ingest and confirm read the
     * same source, held under the same row lock.
     *
     * @return array<string, mixed>|null
     */
    public function pendingIngestion(int $drawId): ?array
    {
        $draw = Draw::query()->find($drawId);

        if (! $draw instanceof Draw) {
            return null;
        }

        $record = $this->currentRecord($draw);

        return is_array($record) && ($record['status'] ?? null) === DrawConfirmationStatus::Pending->value
            ? $record
            : null;
    }

    /**
     * The current (last-written) ingestion record regardless of state.
     *
     * @return array<string, mixed>|null
     */
    public function currentIngestion(int $drawId): ?array
    {
        $draw = Draw::query()->find($drawId);

        return $draw instanceof Draw ? $this->currentRecord($draw) : null;
    }

    /**
     * Whether an ingestion record for this draw exists at all.
     */
    public function hasIngestion(int $drawId): bool
    {
        return $this->currentIngestion($drawId) !== null;
    }

    /**
     * Validate + canonicalize the operator/feed payload into the ONLY two
     * numbers the result pipeline trusts: first_prize (6 digits) and
     * bottom_two (2 digits, GLO-derived from first_prize unless claimed, in
     * which case the claim must agree with the derivation).
     *
     * @param  array<string, mixed>  $payload
     *
     * @return array{first_prize: string, bottom_two: string}
     *
     * @throws DrawResultException
     */
    private function canonicalize(int $drawId, array $payload, string $source): array
    {
        $firstPrize = isset($payload['first_prize']) && is_scalar($payload['first_prize'])
            ? trim((string) $payload['first_prize'])
            : '';

        if ($firstPrize === '') {
            throw DrawResultException::emptyResult($drawId, $source);
        }

        if (! preg_match('/^\d{6}$/', $firstPrize)) {
            throw DrawResultException::malformed($source, sprintf(
                'first_prize [%s] must be exactly six digits',
                $firstPrize,
            ), ['draw_id' => $drawId]);
        }

        $derivedBottomTwo = substr($firstPrize, -2);
        $claimedBottomTwo = isset($payload['bottom_two']) && is_scalar($payload['bottom_two'])
            ? trim((string) $payload['bottom_two'])
            : '';

        if ($claimedBottomTwo !== '' && ! preg_match('/^\d{2}$/', $claimedBottomTwo)) {
            throw DrawResultException::malformed($source, sprintf(
                'bottom_two [%s] must be exactly two digits',
                $claimedBottomTwo,
            ), ['draw_id' => $drawId]);
        }

        if ($claimedBottomTwo !== '' && $claimedBottomTwo !== $derivedBottomTwo) {
            throw DrawResultException::malformed($source, sprintf(
                'bottom_two [%s] disagrees with the last two digits of first_prize [%s=%s]: the announcement cannot say both',
                $claimedBottomTwo,
                $firstPrize,
                $derivedBottomTwo,
            ), ['draw_id' => $drawId]);
        }

        return [
            'first_prize' => $firstPrize,
            'bottom_two' => $derivedBottomTwo,
        ];
    }

    /**
     * sha256 over the canonical pair: stable across identical re-ingests,
     * different the moment any digit moves.
     *
     * @param  array{first_prize: string, bottom_two: string}  $canonical
     */
    private function fingerprintFor(array $canonical): string
    {
        return hash('sha256', $canonical['first_prize'].':'.$canonical['bottom_two']);
    }

    /**
     * The draw lifecycle states in which a new result may be INGESTED: the
     * draw is closed (or result-pending) but has not published or settled
     * anything yet. Draft/Open draws have nothing to settle against; a
     * result_published/settled draw is already beyond input; a cancelled draw
     * consumes no results at all.
     */
    private function mayIngestIn(DrawLifecycleState $state): bool
    {
        return in_array($state, [
            DrawLifecycleState::Closed,
            DrawLifecycleState::ResultPending,
        ], true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentRecord(Draw $draw): ?array
    {
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $record = $metadata[self::METADATA_KEY] ?? null;

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array<string, mixed>  $record
     *
     * @return array{draw_id: int, status: string, fingerprint: string, first_prize: string, bottom_two: string, source: string, ingested_at: string, no_op: bool}
     */
    private function resultRow(int $drawId, array $record, bool $noOp): array
    {
        $payload = $record['payload'] ?? [];

        return [
            'draw_id' => $drawId,
            'status' => (string) $record['status'],
            'fingerprint' => (string) $record['fingerprint'],
            'first_prize' => (string) ($payload['first_prize'] ?? ''),
            'bottom_two' => (string) ($payload['bottom_two'] ?? ''),
            'source' => (string) $record['source'],
            'ingested_at' => (string) $record['ingested_at'],
            'no_op' => $noOp,
        ];
    }

    /**
     * Every state-changing intake is auditable: who fed what, when, from
     * which channel.
     *
     * @param  array<string, mixed>  $record
     */
    private function recordAudit(Draw $draw, string $action, array $record, ?int $actorUserId, string $description): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $actorUserId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => Draw::class,
            'auditable_id' => $draw->getKey(),
            'description' => sprintf(
                'Draw %s: %s — first prize %s, bottom two %s (source %s).',
                (string) $draw->draw_number,
                $description,
                (string) ($record['payload']['first_prize'] ?? ''),
                (string) ($record['payload']['bottom_two'] ?? ''),
                (string) $record['source'],
            ),
            'metadata' => [
                'draw_id' => (int) $draw->getKey(),
                'draw_number' => (string) $draw->draw_number,
                'ingestion_status' => (string) $record['status'],
                'fingerprint' => (string) $record['fingerprint'],
                'source' => (string) $record['source'],
                'action' => $action,
            ],
        ]);

        $log->save();
    }
}
