<?php

declare(strict_types=1);

namespace App\DTOs\Compliance;

use App\Enums\AmlRiskLevel;
use App\Exceptions\ComplianceCaseException;

/**
 * Compliance case identity: the SUBJECT, the case TYPE, the risk it
 * carries, the trigger reference that opened it (an assessment, a
 * webhook, a report), the trigger's evidence fingerprint and the desk
 * the file belongs to.
 *
 * ONE LIVE FILE per (subject, type, trigger): the deterministic case
 * key means re-opening the same fact replays; re-using the key for
 * different facts is a fork.
 */
final readonly class ComplianceCaseData
{
    public function __construct(
        public int $subjectUserId,
        public string $caseType,
        public AmlRiskLevel $riskLevel,
        public string $triggerReference,
        public string $evidenceFingerprint,
        public string $assignedDesk,
    ) {}

    public function caseKey(): string
    {
        return hash('sha256', sprintf(
            'comp-case:%d:%s:%s:%s',
            $this->subjectUserId,
            $this->caseType,
            $this->triggerReference,
            $this->evidenceFingerprint,
        ));
    }

    /**
     * @throws ComplianceCaseException
     */
    public static function fromInput(
        int $subjectUserId,
        string $caseType,
        string|AmlRiskLevel $riskLevel,
        string $triggerReference,
        string $evidenceFingerprint,
        string $assignedDesk = 'compliance',
    ): ComplianceCaseData {
        $type = strtolower(trim($caseType));
        $trigger = strtoupper(trim($triggerReference));
        $fp = strtolower(trim($evidenceFingerprint));
        $desk = strtolower(trim($assignedDesk));

        $level = $riskLevel instanceof AmlRiskLevel
            ? $riskLevel
            : AmlRiskLevel::tryFrom(strtolower(trim($riskLevel)));

        if ($subjectUserId < 1) {
            throw ComplianceCaseException::malformed('the case must name a real subject');
        }

        if (! preg_match('/^[a-z0-9_]{3,32}$/', $type)) {
            throw ComplianceCaseException::malformed('a case type is a lowercase snake 3-32 character token');
        }

        if (! $level instanceof AmlRiskLevel) {
            throw ComplianceCaseException::malformed('unknown AML risk level');
        }

        if (strlen($trigger) < 6 || strlen($trigger) > 96 || ! preg_match('/^[A-Z0-9:\-._]+$/', $trigger)) {
            throw ComplianceCaseException::malformed('the trigger reference must be a canonical 6-96 character token');
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw ComplianceCaseException::missingEvidence('a case without an evidence fingerprint is smoke, not paper');
        }

        if (! preg_match('/^[a-z0-9_\-]{3,32}$/', $desk)) {
            throw ComplianceCaseException::malformed('an assigned desk is a 3-32 character slug');
        }

        return new self(
            subjectUserId: $subjectUserId,
            caseType: $type,
            riskLevel: $level,
            triggerReference: $trigger,
            evidenceFingerprint: $fp,
            assignedDesk: $desk,
        );
    }
}
