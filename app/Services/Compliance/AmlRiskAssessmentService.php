<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\DTOs\Compliance\AmlRiskAssessmentData;
use App\Enums\AmlRiskLevel;
use App\Enums\AuditAction;
use App\Enums\ComplianceCaseStatus;
use App\Enums\KycStatus;
use App\Enums\RiskLevel;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Exceptions\AmlRiskAssessmentException;
use App\Models\AmlRiskAssessment;
use App\Models\AuditLog;
use App\Models\ComplianceCase;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic AML risk assessments, versioned and replay-safe.
 *
 * THE LAW OF THIS LANE
 *   1. PURE FUNCTION — the score is computed from an evidence map of
 *      wallet/transaction/account facts ALONE, under one formula
 *      (VERSION, below). Same facts in → same SCORE, LEVEL and
 *      evidence fingerprint out (replay). Never a client claim.
 *   2. FACTS AND PRONOUNCEMENT MUST AGREE — the DTO refuses any
 *      (score, level) pair the band-map wouldn't compute.
 *   3. NEW FACTS → NEW VERSION — the changed evidence fingerprint
 *      yields a fresh row that SUPERSEDES the previous current one
 *      (the old row is never edited: evidence, not correction fluid).
 *   4. THE REASONS ARE THE PAPER — every score carries UPPER_SNAKE
 *      reason codes naming WHICH fact drove what share.
 */
final class AmlRiskAssessmentService
{
    public const ASSESSMENT_VERSION = 'v1';

    /* -------------------------------------------------- evidence --- */

    /**
     * The evidence map + derived score/levels for a user, computed
     * from LIVE rows under no caller influence.
     *
     * @return array{evidence: array, score: int, level: AmlRiskLevel, reason_codes: array}
     *
     * @throws AmlRiskAssessmentException
     */
    public function measure(int $userId): array
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);

        if (! $user instanceof User) {
            throw AmlRiskAssessmentException::notFound('user:'.$userId);
        }

        $since = now()->subDays(90);

        // -- transaction evidence -----------------------------------
        $volume90d = '0.00';
        $failed90d = 0;
        $txCount90d = 0;

        /** @var Wallet|null $wallet */
        $wallet = Wallet::query()->where('user_id', $userId)->first();

        if ($wallet instanceof Wallet) {
            FinancialTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('created_at', '>=', $since)
                ->get(['type', 'amount', 'status'])
                ->each(function (FinancialTransaction $tx) use (&$volume90d, &$failed90d, &$txCount90d): void {
                    $txCount90d++;
                    $status = $tx->status instanceof TransactionStatus ? $tx->status : TransactionStatus::tryFrom((string) $tx->status);
                    $type = $tx->type instanceof TransactionType ? $tx->type : TransactionType::tryFrom((string) $tx->type);

                    if ($type !== null && in_array($type, [TransactionType::Deposit, TransactionType::Withdrawal], true)) {
                        $volume90d = bcadd($volume90d, bcadd((string) $tx->amount, '0', 2), 2);
                    }

                    if ($status === TransactionStatus::Failed) {
                        $failed90d++;
                    }
                });
        }

        // -- account evidence ---------------------------------------
        $kyc = $user->kycStatus();
        $kycVerified = $kyc === KycStatus::Verified;

        $openCases = ComplianceCase::query()
            ->where('subject_user_id', $userId)
            ->whereIn('status', [
                ComplianceCaseStatus::Open->value,
                ComplianceCaseStatus::Investigating->value,
                ComplianceCaseStatus::Escalated->value,
            ])
            ->count();

        $walletAgeDays = $wallet instanceof Wallet ? max(0, (int) now()->diffInDays($wallet->created_at)) : -1;

        $evidence = [
            'failed_tx_90d' => $failed90d,
            'kyc_status' => $kyc->value,
            'open_cases' => $openCases,
            'tx_count_90d' => $txCount90d,
            'volume_90d' => $volume90d,
            'wallet_age_days' => $walletAgeDays,
        ];

        // -- scoring formula (VERSION = v1) -------------------------
        $score = 0;
        $reasons = [];

        if (bccomp($volume90d, '500000.00', 2) > 0) {
            $score += 30;
            $reasons[] = 'VOLUME_EXCEEDS_500K';
        }

        if (bccomp($volume90d, '2000000.00', 2) > 0) {
            $score += 25;
            $reasons[] = 'VOLUME_EXCEEDS_2M';
        }

        if ($failed90d > 3) {
            $score += 20;
            $reasons[] = 'FAILED_TX_PATTERN';
        }

        if (! $kycVerified) {
            $score += 25;
            $reasons[] = 'KYC_NOT_VERIFIED';
        }

        if ($openCases > 0) {
            $score += 15;
            $reasons[] = 'OPEN_COMPLIANCE_CASE';
        }

        if ($walletAgeDays >= 0 && $walletAgeDays < 30 && bccomp($volume90d, '100000.00', 2) > 0) {
            $score += 10;
            $reasons[] = 'NEW_WALLET_HIGH_VOLUME';
        }

        $score = min(100, $score);

        return [
            'evidence' => $evidence,
            'score' => $score,
            'level' => AmlRiskLevel::fromScore($score),
            'reason_codes' => $reasons === [] ? ['NO_RISK_FACTORS'] : $reasons,
        ];
    }

    /* ----------------------------------------------- pronounce ---- */

    /**
     * Pronounce the deterministic assessment anchored to the current
     * evidence. Same evidence in → existing row served (replay); new
     * evidence → new CURRENT version superseding the previous row.
     *
     * @return array{assessment: AmlRiskAssessment, replayed: bool, superseded: bool}
     *
     * @throws AmlRiskAssessmentException
     */
    public function pronounce(int $userId): array
    {
        return DB::transaction(function () use ($userId): array {
            $measured = $this->measure($userId);

            $fingerprint = AmlRiskAssessmentData::fingerprintOf($userId, $measured['evidence']);

            /** @var AmlRiskAssessment|null $existing */
            $existing = AmlRiskAssessment::query()
                ->lockForUpdate()
                ->where('user_id', $userId)
                ->where('evidence_fingerprint', $fingerprint)
                ->first();

            if ($existing instanceof AmlRiskAssessment) {
                $factsMatch = $existing->risk_level === $measured['level']
                    && (int) $existing->score === $measured['score'];

                if (! $factsMatch) {
                    throw AmlRiskAssessmentException::duplicate((string) $existing->assessment_key);
                }

                return ['assessment' => $existing, 'replayed' => true, 'superseded' => false];
            }

            // Supersede WITHOUT editing history.
            $superseded = false;

            AmlRiskAssessment::query()
                ->lockForUpdate()
                ->where('user_id', $userId)
                ->where('status', AmlRiskAssessment::STATUS_CURRENT)
                ->get()
                ->each(function (AmlRiskAssessment $stale) use (&$superseded): void {
                    $stale->status = AmlRiskAssessment::STATUS_SUPERSEDED;
                    $stale->superseded_at = now();
                    $stale->save();
                    $superseded = true;
                });

            $data = AmlRiskAssessmentData::fromInput(
                userId: $userId,
                riskLevel: $measured['level'],
                score: $measured['score'],
                reasonCodes: $measured['reason_codes'],
                assessmentVersion: self::ASSESSMENT_VERSION,
                evidenceFingerprint: $fingerprint,
            );

            $row = new AmlRiskAssessment;
            $row->fill([
                'assessment_key' => $data->assessmentKey(),
                'user_id' => $userId,
                'score' => $data->score,
                'reason_codes' => $data->reasonCodes,
                'assessment_version' => $data->assessmentVersion,
                'evidence_fingerprint' => $data->evidenceFingerprint,
                'status' => AmlRiskAssessment::STATUS_CURRENT,
                'assessed_at' => now(),
                'metadata' => ['evidence' => $measured['evidence']],
            ]);
            $row->risk_level = $data->riskLevel;
            $row->save();

            $this->recordAudit($row, sprintf(
                'Assessed %s (score %d) from [%s]%s',
                $data->riskLevel->value,
                $data->score,
                implode(', ', $data->reasonCodes),
                $superseded ? ' — superseding the previous version' : '',
            ));

            return ['assessment' => $row, 'replayed' => false, 'superseded' => $superseded];
        });
    }

    /* ----------------------------------------------- assertion ---- */

    /**
     * The "is this user's paper current" guard: pronounce fresh; if the
     * freshly-pronounced evidence fingerprint differs from the caller's
     * claimed one, the claim is stale by name.
     *
     * @throws AmlRiskAssessmentException
     */
    public function assertCurrent(int $userId, string $claimedEvidenceFingerprint): AmlRiskAssessment
    {
        ['assessment' => $row] = $this->pronounce($userId);

        if (strtolower(trim($claimedEvidenceFingerprint)) !== (string) $row->evidence_fingerprint) {
            throw AmlRiskAssessmentException::stale($claimedEvidenceFingerprint);
        }

        return $row;
    }

    /* ------------------------------------------------ internals ---- */

    private function recordAudit(AmlRiskAssessment $assessment, string $description): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => (int) $assessment->user_id,
            'action' => AuditAction::Update,
            'risk_level' => $assessment->risk_level instanceof AmlRiskLevel && $assessment->risk_level->severity() >= AmlRiskLevel::High->severity()
                ? RiskLevel::High
                : RiskLevel::Medium,
            'auditable_type' => AmlRiskAssessment::class,
            'auditable_id' => (int) $assessment->getKey(),
            'description' => $description,
            'metadata' => [
                'assessment_key_prefix' => substr((string) $assessment->assessment_key, 0, 16),
                'risk_level' => $assessment->risk_level instanceof AmlRiskLevel ? $assessment->risk_level->value : (string) $assessment->risk_level,
                'score' => (int) $assessment->score,
                'evidence_fingerprint_prefix' => substr((string) $assessment->evidence_fingerprint, 0, 16),
                'lane' => 'aml-risk-assessment',
            ],
        ]);

        $log->save();
    }
}
