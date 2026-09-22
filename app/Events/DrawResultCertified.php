<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\ResultSourceType;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A draw result reached Certified — exactly once per certification act.
 *
 * WHY AN EVENT
 * The certification court and everything downstream of it (audit-scribe,
 * ops boards, compliance exports) stay decoupled: the court commits the
 * row, then AFTER COMMIT it informs. One well-shaped facts pack moves
 * through this seam — references, fingerprint, provenance, never personal
 * data about the certifier.
 */
final class DrawResultCertified
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $certificationKey,
        public readonly int $drawId,
        public readonly string $drawReference,
        public readonly string $resultFingerprint,
        public readonly ResultSourceType $source,
        public readonly string $certifierReference,
        public readonly string $certifiedAt,
    ) {}

    /**
     * The listener's dedupe anchor: one audit row per (certification, act).
     */
    public static function anchorFor(string $certificationKey, string $action): string
    {
        return hash('sha256', sprintf('draw-cert-certified:%s:%s', $certificationKey, $action));
    }

    /**
     * @return array<string, mixed>
     */
    public function logPayload(): array
    {
        return [
            'certification_key' => substr($this->certificationKey, 0, 12),
            'draw' => $this->drawReference,
            'fingerprint' => substr($this->resultFingerprint, 0, 12),
            'source' => $this->source->value,
            'certified_at' => $this->certifiedAt,
        ];
    }
}
