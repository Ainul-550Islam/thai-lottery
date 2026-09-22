<?php

declare(strict_types=1);

namespace App\Jobs;

use App\DTOs\Draw\DrawPublicationData;
use App\Enums\QueueName;
use App\Exceptions\DrawPublicationException;
use App\Services\Draw\DrawCertificationService;
use App\Services\Draw\DrawPublicationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Publishes ONLY certified results.
 *
 * DISCIPLINE
 * - Re-derives the answer at run time: a job shaped in the past never
 *   quietly publishes into a present the court has since revoked/
 *   superseded — uncertified paper skips in writing.
 * - Stale-version protection rides on the board's monotonic rule; this
 *   driver asks for exactly `nextVersion` and the board refuses anything
 *   else by name.
 * - Hourly per-draw queue uniqueness; the publication key itself is the
 *   durable replay guard.
 */
final class PublishCertifiedDrawJob implements ShouldBeUnique, ShouldQueue
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
        return 'publish_certified_draw_'.$this->drawId.'_'.now()->format('Y-m-d-H');
    }

    /**
     * @return array{published: bool, replayed: bool, version: ?int, skipped: ?string}
     */
    public function handle(
        DrawCertificationService $certifications,
        DrawPublicationService $publications,
    ): array {
        $live = $certifications->liveFor($this->drawId);

        if ($live === null || ! $live->status->isPublishable()) {
            Log::info('PublishCertifiedDrawJob: nothing publishable lives — skipped', [
                'draw_id' => $this->drawId,
                'certification_status' => $live?->status?->value ?? 'none',
            ]);

            return ['published' => false, 'replayed' => false, 'version' => null, 'skipped' => 'uncertified'];
        }

        try {
            $result = $publications->publish(DrawPublicationData::fromInput(
                drawId: $this->drawId,
                certificationKey: (string) $live->certification_key,
                resultFingerprint: (string) $live->result_fingerprint,
                version: $publications->nextVersion($this->drawId),
                publishedAt: now()->toIso8601String(),
            ));

            Log::info('PublishCertifiedDrawJob: board rotated', [
                'draw_id' => $this->drawId,
                'version' => (int) $result['publication']->version,
                'replayed' => $result['replayed'],
            ]);

            return [
                'published' => true,
                'replayed' => (bool) $result['replayed'],
                'version' => (int) $result['publication']->version,
                'skipped' => null,
            ];
        } catch (DrawPublicationException $e) {
            Log::info('PublishCertifiedDrawJob: refused by the board', [
                'draw_id' => $this->drawId,
                'reason' => $e->errorCode(),
            ]);

            return ['published' => false, 'replayed' => false, 'version' => null, 'skipped' => $e->errorCode()];
        }
    }
}
