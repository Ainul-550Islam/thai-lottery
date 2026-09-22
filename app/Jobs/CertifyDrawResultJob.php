<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Draw\DrawCertificationData;
use App\Enums\DrawConfirmationStatus;
use App\Enums\QueueName;
use App\Enums\ResultSourceType;
use App\Exceptions\DrawCertificationException;
use App\Services\Draw\DrawCertificationService;
use App\Services\Draw\DrawResultConfirmationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Queue-safe certification workflow after result confirmation.
 *
 * DISCIPLINE
 * - Ran AFTER confirmation: the check is re-pronounced here — a queued
 *   job that arrived from an early world (pre-confirmation, pre-reject)
 *   skips in writing, never quietly upgrades a draw the court hadn't
 *   confirmed.
 * - Prevents DUPLICATE certification: deterministic certification key
 *   on the service side + hourly job uniqueness on the driver side.
 *   Same draw, same certifier, same hour = one queue entry.
 * - Pronounced in the log on every refusal; errors stay visible.
 */
final class CertifyDrawResultJob implements ShouldBeUnique, ShouldQueue
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
        public readonly string $certifierReference,
        public readonly ResultSourceType $source,
        public readonly ?string $certifiedAt = null,
    ) {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return 'certify_draw_'.$this->drawId.'_'.substr(hash('sha256', $this->certifierReference.$this->source->value), 0, 8).'_'.now()->format('Y-m-d-H');
    }

    /**
     * @return array{certified: bool, replayed: bool, skipped: ?string}
     */
    public function handle(
        DrawCertificationService $certifications,
        DrawResultConfirmationService $confirmations,
    ): array {
        $confirmationStatus = $confirmations->statusOf($this->drawId);

        if ($confirmationStatus !== DrawConfirmationStatus::Confirmed) {
            Log::info('CertifyDrawResultJob: result not confirmed yet — skipped, not retired', [
                'draw_id' => $this->drawId,
                'confirmation_status' => $confirmationStatus?->value ?? 'none',
            ]);

            return ['certified' => false, 'replayed' => false, 'skipped' => 'not-confirmed'];
        }

        $fingerprint = DrawCertificationService::fingerprintFor($this->drawId);

        if ($fingerprint === null) {
            Log::warning('CertifyDrawResultJob: draw carries no winning numbers', [
                'draw_id' => $this->drawId,
            ]);

            return ['certified' => false, 'replayed' => false, 'skipped' => 'no-paper'];
        }

        try {
            $result = $certifications->certify(DrawCertificationData::fromInput(
                drawId: $this->drawId,
                resultFingerprint: $fingerprint,
                certifierReference: $this->certifierReference,
                certifiedAt: $this->certifiedAt ?? now()->toIso8601String(),
                source: $this->source,
            ));

            Log::info('CertifyDrawResultJob: certified', [
                'draw_id' => $this->drawId,
                'replayed' => $result['replayed'],
            ]);

            return [
                'certified' => true,
                'replayed' => (bool) $result['replayed'],
                'skipped' => null,
            ];
        } catch (DrawCertificationException $e) {
            Log::info('CertifyDrawResultJob: refused by the court', [
                'draw_id' => $this->drawId,
                'reason' => $e->errorCode(),
            ]);

            return ['certified' => false, 'replayed' => false, 'skipped' => $e->errorCode()];
        }
    }
}
