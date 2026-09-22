<?php

declare(strict_types=1);

namespace App\DTOs\Compliance;

use App\Enums\AmlRiskLevel;
use App\Exceptions\AmlRiskAssessmentException;

/**
 * One AML evidence pack: WHO was measured, the derived risk level,
 * the numeric score with its reason codes, the assessment VERSION
 * and the evidence fingerprint that produced it.
 *
 * The assessment is a pure function of the evidence fingerprint:
 * same facts in → same row out (replay); different facts → a NEW
 * version which supersedes without editing the old.
 */
final readonly class AmlRiskAssessmentData
{
    /** @param array<int, string> $reasonCodes */
    public function __construct(
        public int $userId,
        public AmlRiskLevel $riskLevel,
        public int $score,
        public array $reasonCodes,
        public string $assessmentVersion,
        public string $evidenceFingerprint,
    ) {}

    /**
     * The evidence fingerprint over the raw inputs the scoring lane
     * read (wallet facts, transaction volume facts, account facts).
     * Callers pass the canonical evidence map; order is irrelevant.
     */
    public static function fingerprintOf(int $userId, array $evidence): string
    {
        ksort($evidence);

        return hash('sha256', sprintf('aml-ev:%d:%s', $userId, (string) json_encode($evidence, JSON_UNESCAPED_UNICODE)));
    }

    public function assessmentKey(): string
    {
        return hash('sha256', sprintf('aml:%d:%s:%s', $this->userId, $this->evidenceFingerprint, $this->assessmentVersion));
    }

    /**
     * @throws AmlRiskAssessmentException
     */
    public static function fromInput(
        int $userId,
        string|AmlRiskLevel $riskLevel,
        int $score,
        array $reasonCodes,
        string $assessmentVersion,
        string $evidenceFingerprint,
    ): AmlRiskAssessmentData {
        $fp = strtolower(trim($evidenceFingerprint));
        $version = strtolower(trim($assessmentVersion));

        $level = $riskLevel instanceof AmlRiskLevel
            ? $riskLevel
            : AmlRiskLevel::tryFrom(strtolower(trim($riskLevel)));

        if ($userId < 1) {
            throw AmlRiskAssessmentException::malformed('the assessment must name a real user');
        }

        if (! $level instanceof AmlRiskLevel) {
            throw AmlRiskAssessmentException::malformed('unknown AML risk level');
        }

        if ($score < 0 || $score > 100) {
            throw AmlRiskAssessmentException::malformed('the score must sit in [0, 100]');
        }

        if ($level !== AmlRiskLevel::fromScore($score)) {
            throw AmlRiskAssessmentException::riskConflict(
                sprintf('the score %d belongs to %s, never to %s — facts and pronouncement must agree', $score, AmlRiskLevel::fromScore($score)->value, $level->value),
            );
        }

        if ($reasonCodes === []) {
            throw AmlRiskAssessmentException::invalidEvidence('an assessment without reason codes explains nothing');
        }

        foreach ($reasonCodes as $code) {
            if (! preg_match('/^[A-Z0-9_]{3,32}$/', (string) $code)) {
                throw AmlRiskAssessmentException::malformed('reason codes are UPPER_SNAKE 3-32 character tokens');
            }
        }

        if (! preg_match('/^v\d+(\.\d+)*$/', $version)) {
            throw AmlRiskAssessmentException::malformed('the assessment version must look like v1, v2.1, …');
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw AmlRiskAssessmentException::invalidEvidence('the evidence fingerprint must be exactly 64 lowercase hex characters');
        }

        return new self(
            userId: $userId,
            riskLevel: $level,
            score: $score,
            reasonCodes: array_values($reasonCodes),
            assessmentVersion: $version,
            evidenceFingerprint: $fp,
        );
    }
}
