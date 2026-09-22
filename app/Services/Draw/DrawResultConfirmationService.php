<?php

declare(strict_types=1);

namespace App\Services\Draw;

use App\Enums\AuditAction;
use App\Enums\DrawConfirmationStatus;
use App\Enums\RiskLevel;
use App\Exceptions\DrawLifecycleException;
use App\Exceptions\DrawResultException;
use App\Models\AuditLog;
use App\Models\Draw;
use Illuminate\Support\Facades\DB;

/**
 * The SECOND pair of eyes over an ingested draw result.
 *
 * FLOW
 * ----
 * DrawResultIngestionService has stored a Pending record (the official result
 * as one hand entered it). This service is the second hand:
 *
 *   confirm($drawId, $operatorId, $claimed):
 *     1. Re-read the Pending ingestion under the draw row lock.
 *     2. Compare every claimed number with what ingestion stored. A claimed
 *        value that disagrees with the stored one is reported as a mismatch
 *        and the confirmation is refused — confirming either version would
 *        attest a falsehood.
 *     3. Publish through DrawResultPublicationService::publish() — the same
 *        validated, transactional path any other publication takes, which
 *        writes draw_results + winning_numbers and moves the lifecycle to
 *        result_published atomically.
 *     4. Write the confirmation stamp (status Confirmed, confirmed_by/at,
 *        linked draw_result id) onto the same draw metadata record, and
 *        supersede any previously Confirmed sibling in history.
 *
 *   reject($drawId, $operatorId, $reason):
 *     Marks the Pending record Rejected with the operator's reason; the draw
 *     remains ingestable so a corrected result can come in through the
 *     normal intake, and the rejection is kept in history.
 *
 * INVARIANTS
 * - Only a Confirmed record is ever published — this class is the ONLY door
 *   from the four-eyes pipeline into publication, and it publishes nothing
 *   above the Pending it confirmed.
 * - Confirmation and rejection are both one-shot: a Confirmed record can only
 *   be superseded, never un-confirmed, and a Rejected record is terminal.
 * - All writes live inside one transaction; a publication that throws leaves
 *   the ingestion record Pending and the draw untouched.
 */
class DrawResultConfirmationService
{
    public function __construct(
        private readonly DrawResultIngestionService $ingestion,
        private readonly DrawResultPublicationService $publication,
    ) {
    }

    /**
     * Confirm the pending ingestion by comparing claimed numbers against it
     * and publishing the stored result.
     *
     * @param  array{first_prize: string, bottom_two?: string|null}  $claimed
     *
     * @return array{
     *     draw_id: int,
     *     status: string,
     *     draw_result_id: int,
     *     confirmed_by: int,
     *     confirmed_at: string,
     *     published: bool
     * }
     *
     * @throws DrawResultException
     * @throws DrawLifecycleException
     */
    public function confirm(int $drawId, int $operatorId, array $claimed): array
    {
        return DB::transaction(function () use ($drawId, $operatorId, $claimed): array {
            $draw = $this->lockedDraw($drawId);
            $record = $this->pendingRecord($draw, throwNotPending: true);

            $stored = $record['payload'] ?? [];
            $claimedNormalized = $this->normalizeClaim($drawId, $claimed);

            $this->assertClaimsAgree($draw->getKey(), $stored, $claimedNormalized);

            // Publish via the one true publication path. It validates again,
            // moves the lifecycle, and writes draw_results + winning_numbers —
            // all in this same transaction, so a failure anywhere here leaves
            // the draw exactly as found.
            $published = $this->publication->publish(
                $drawId,
                [
                    'first_prize' => (string) $stored['first_prize'],
                    'bottom_two' => (string) $stored['bottom_two'],
                ],
            );

            $drawResultId = isset($published['result']) && $published['result'] instanceof \App\Models\DrawResult
                ? (int) $published['result']->getKey()
                : 0;

            $stamp = $record;
            $stamp['status'] = DrawConfirmationStatus::Confirmed->value;
            $stamp['confirmed_by'] = $operatorId;
            $stamp['confirmed_at'] = now()->toIso8601String();
            $stamp['draw_result_id'] = $drawResultId;

            $this->writeRecord($draw, $stamp, supersedeConfirmedSiblings: true);

            $this->recordAudit($draw, $stamp, $operatorId, sprintf(
                'Confirmed and published official result (first prize %s, bottom two %s).',
                (string) $stored['first_prize'],
                (string) $stored['bottom_two'],
            ));

            return [
                'draw_id' => $drawId,
                'status' => DrawConfirmationStatus::Confirmed->value,
                'draw_result_id' => $drawResultId,
                'confirmed_by' => $operatorId,
                'confirmed_at' => $stamp['confirmed_at'],
                'published' => true,
            ];
        });
    }

    /**
     * Reject the pending ingestion with an operator reason. The draw stays
     * ingestable: a correction can be pasted through the normal intake.
     *
     * @return array{draw_id: int, status: string, rejected_by: int, rejected_at: string, reason: string}
     *
     * @throws DrawResultException
     */
    public function reject(int $drawId, int $operatorId, string $reason): array
    {
        return DB::transaction(function () use ($drawId, $operatorId, $reason): array {
            $draw = $this->lockedDraw($drawId);
            $record = $this->pendingRecord($draw, throwNotPending: true);

            $stamp = $record;
            $stamp['status'] = DrawConfirmationStatus::Rejected->value;
            $stamp['rejected_by'] = $operatorId;
            $stamp['rejected_at'] = now()->toIso8601String();
            $stamp['rejection_reason'] = $reason;

            // A rejected record moves to history and clears the current slot,
            // so the next ingestion starts with a clean Pending plaque.
            $this->relegiteRecord($draw, $stamp);

            $this->recordAudit($draw, $stamp, $operatorId, sprintf(
                'REJECTED ingested official result: %s.',
                $reason,
            ));

            return [
                'draw_id' => $drawId,
                'status' => DrawConfirmationStatus::Rejected->value,
                'rejected_by' => $operatorId,
                'rejected_at' => $stamp['rejected_at'],
                'reason' => $reason,
            ];
        });
    }

    /**
     * The current confirmation state of a draw's ingestion, or null when no
     * ingestion record exists.
     */
    public function statusOf(int $drawId): ?DrawConfirmationStatus
    {
        $record = $this->ingestion->currentIngestion($drawId);

        if ($record === null || ! isset($record['status']) || ! is_string($record['status'])) {
            return null;
        }

        return DrawConfirmationStatus::tryFrom($record['status']);
    }

    /**
     * What the operator would be confirming, for the review screen:
     * first prize, bottom two, source and the fingerprint that binds them.
     *
     * @return array{draw_id: int, first_prize: string, bottom_two: string, source: string, fingerprint: string, ingested_at: string}|null
     */
    public function previewFor(int $drawId): ?array
    {
        $pending = $this->ingestion->pendingIngestion($drawId);

        if ($pending === null) {
            return null;
        }

        $payload = $pending['payload'] ?? [];

        return [
            'draw_id' => $drawId,
            'first_prize' => (string) ($payload['first_prize'] ?? ''),
            'bottom_two' => (string) ($payload['bottom_two'] ?? ''),
            'source' => (string) ($pending['source'] ?? ''),
            'fingerprint' => (string) ($pending['fingerprint'] ?? ''),
            'ingested_at' => (string) ($pending['ingested_at'] ?? ''),
        ];
    }

    /**
     * @throws DrawResultException
     */
    private function lockedDraw(int $drawId): Draw
    {
        $draw = Draw::query()->lockForUpdate()->find($drawId);

        if (! $draw instanceof Draw) {
            throw DrawResultException::drawNotFound($drawId);
        }

        return $draw;
    }

    /**
     * The Pending record of a locked draw, hard-failing when there is none (a
     * missing Pending means there was nothing to review).
     *
     * @return array<string, mixed>
     *
     * @throws DrawResultException
     */
    private function pendingRecord(Draw $draw, bool $throwNotPending): array
    {
        $drawId = (int) $draw->getKey();
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $record = $metadata[DrawResultIngestionService::METADATA_KEY] ?? null;

        if (! is_array($record)) {
            throw DrawResultException::confirmationForbidden(
                $drawId,
                'nothing-ingested',
                'reviewed',
                ['draw_id' => $drawId],
            );
        }

        $status = is_string($record['status'] ?? null) ? $record['status'] : '';

        if ($status !== DrawConfirmationStatus::Pending->value && $throwNotPending) {
            throw DrawResultException::confirmationForbidden(
                $drawId,
                $status === '' ? 'unknown' : $status,
                'reviewed again',
                ['draw_id' => $drawId],
            );
        }

        return $record;
    }

    /**
     * The operator's claimed numbers in the same canonical shape ingestion
     * stored. An absent bottom_two is derived — the operator reviews the same
     * announced numbers, not a different pair.
     *
     * @param  array<string, mixed>  $claimed
     *
     * @return array{first_prize: string, bottom_two: string}
     *
     * @throws DrawResultException
     */
    private function normalizeClaim(int $drawId, array $claimed): array
    {
        $firstPrize = isset($claimed['first_prize']) && is_scalar($claimed['first_prize'])
            ? trim((string) $claimed['first_prize'])
            : '';

        $bottomTwo = isset($claimed['bottom_two']) && is_scalar($claimed['bottom_two'])
            ? trim((string) $claimed['bottom_two'])
            : ($firstPrize !== '' ? substr($firstPrize, -2) : '');

        return [
            'first_prize' => $firstPrize,
            'bottom_two' => $bottomTwo,
        ];
    }

    /**
     * @param  array{first_prize?: string, bottom_two?: string}  $stored
     * @param  array{first_prize: string, bottom_two: string}  $claimed
     *
     * @throws DrawResultException
     */
    private function assertClaimsAgree(int $drawId, array $stored, array $claimed): void
    {
        if (($stored['first_prize'] ?? '') !== $claimed['first_prize']) {
            throw DrawResultException::mismatchConfirmed(
                $drawId,
                'first_prize',
                (string) ($stored['first_prize'] ?? ''),
                $claimed['first_prize'],
                ['draw_id' => $drawId],
            );
        }

        if (($stored['bottom_two'] ?? '') !== $claimed['bottom_two']) {
            throw DrawResultException::mismatchConfirmed(
                $drawId,
                'bottom_two',
                (string) ($stored['bottom_two'] ?? ''),
                $claimed['bottom_two'],
                ['draw_id' => $drawId],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $stamp
     */
    private function writeRecord(Draw $draw, array $stamp, bool $supersedeConfirmedSiblings): void
    {
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $history = is_array($metadata['result_ingestion_history'] ?? null)
            ? $metadata['result_ingestion_history']
            : [];

        if ($supersedeConfirmedSiblings) {
            foreach ($history as $i => $sibling) {
                if (is_array($sibling)
                    && ($sibling['status'] ?? null) === DrawConfirmationStatus::Confirmed->value
                ) {
                    $sibling['status'] = DrawConfirmationStatus::Superseded->value;
                    $history[$i] = $sibling;
                }
            }
        }

        $metadata[DrawResultIngestionService::METADATA_KEY] = $stamp;
        $metadata['result_ingestion_history'] = $history;

        $draw->metadata = $metadata;
        $draw->save();
    }

    /**
     * A rejected record leaves the CURRENT slot and joins history, so the
     * next intake sees a clean slate and the rejection stays auditable.
     *
     * @param  array<string, mixed>  $stamp
     */
    private function relegiteRecord(Draw $draw, array $stamp): void
    {
        $metadata = is_array($draw->metadata) ? $draw->metadata : [];
        $history = is_array($metadata['result_ingestion_history'] ?? null)
            ? $metadata['result_ingestion_history']
            : [];

        $history[] = $stamp;

        unset($metadata[DrawResultIngestionService::METADATA_KEY]);
        $metadata['result_ingestion_history'] = $history;

        $draw->metadata = $metadata;
        $draw->save();
    }

    /**
     * @param  array<string, mixed>  $stamp
     */
    private function recordAudit(Draw $draw, array $stamp, int $operatorId, string $description): void
    {
        $log = new AuditLog();

        $log->fill([
            'user_id' => $operatorId,
            'action' => AuditAction::Update,
            'risk_level' => RiskLevel::High,
            'auditable_type' => Draw::class,
            'auditable_id' => $draw->getKey(),
            'description' => sprintf('Draw %s confirmation: %s', (string) $draw->draw_number, $description),
            'metadata' => [
                'draw_id' => (int) $draw->getKey(),
                'draw_number' => (string) $draw->draw_number,
                'confirmation_status' => (string) $stamp['status'],
                'fingerprint' => (string) ($stamp['fingerprint'] ?? ''),
                'draw_result_id' => $stamp['draw_result_id'] ?? null,
                'rejection_reason' => $stamp['rejection_reason'] ?? null,
            ],
        ]);

        $log->save();
    }
}
