<?php

declare(strict_types=1);

namespace App\DTOs\ResponsibleGaming;

use App\Exceptions\PlayerProtectionCaseException;

/**
 * Protection-case evidence pack: user, trigger, the desk's action
 * summary, risk INDICATORS (normalized list of codes — the immutable
 * evidence the file was opened on), evidence fingerprint, case key.
 */
final class PlayerProtectionCaseData
{
    /**
     * @param  array<int, string>  $riskIndicators
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $triggerReason,
        public readonly ?string $actionSummary,
        public readonly array $riskIndicators,
        public readonly string $evidenceFingerprint,
    ) {}

    /**
     * @param  array{user_id:int, trigger_reason:string, action_summary?:string|null, risk_indicators:array<int, string>, evidence_fingerprint:string}  $data
     */
    public static function fromInput(array $data): self
    {
        $trigger = strtoupper(trim((string) ($data['trigger_reason'] ?? '')));
        $fingerprint = strtolower(trim((string) ($data['evidence_fingerprint'] ?? '')));
        $indicators = array_values(array_map(
            static fn (mixed $i): string => strtoupper(trim((string) $i)),
            (array) ($data['risk_indicators'] ?? []),
        ));
        $indicators = array_values(array_unique(array_filter($indicators)));
        sort($indicators);

        if (! preg_match('/^[A-Z0-9_:\-\.]{4,96}$/', $trigger)) {
            throw PlayerProtectionCaseException::malformed('A trigger reason must be a canonical 4-96 character TOKEN');
        }

        if ($indicators === []) {
            throw PlayerProtectionCaseException::missingEvidence($trigger);
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint)) {
            throw PlayerProtectionCaseException::missingEvidence('evidence fingerprint must be 64 hex chars');
        }

        $summary = isset($data['action_summary']) ? strtolower(trim((string) $data['action_summary'])) : null;

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            triggerReason: $trigger,
            actionSummary: $summary !== '' && $summary !== null ? substr($summary, 0, 32) : null,
            riskIndicators: $indicators,
            evidenceFingerprint: $fingerprint,
        );
    }

    /**
     * One fact = one live file: (user, trigger, evidence, indicators).
     */
    public function caseKey(): string
    {
        return hash('sha256', implode('|', [
            'glo-ppc', (string) $this->userId, $this->triggerReason,
            $this->evidenceFingerprint, json_encode($this->riskIndicators),
        ]));
    }
}
