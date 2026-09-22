<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AmlRiskLevel;
use App\Models\ComplianceCase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Immutable envelope for ONE escalation pronouncement: the case
 * identity, the risk it carries, the trigger fingerprint and the
 * escalation evidence (reason + source + moment). Emitted inside the
 * same transaction as the status move by the case service itself.
 */
final class ComplianceCaseEscalated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ComplianceCase $case,
        public readonly string $reason,
        public readonly string $source,
        public readonly string $escalatedAtIso,
    ) {}

    /**
     * The anchoring fingerprint of this escalation envelope.
     */
    public function escalationFingerprint(): string
    {
        return hash('sha256', sprintf(
            'comp-esc:%s:%s:%s:%s',
            $this->case->case_key,
            $this->reason,
            $this->source,
            $this->escalatedAtIso,
        ));
    }

    public function riskLevel(): string
    {
        return $this->case->risk_level instanceof AmlRiskLevel
            ? $this->case->risk_level->value
            : (string) $this->case->risk_level;
    }
}
