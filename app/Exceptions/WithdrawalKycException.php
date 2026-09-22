<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A MONEY-OUT KYC GATE refusal.
 *
 * COVERAGE
 * --------
 * Everything the gate may refuse before letting money leave: identity
 * evidence missing entirely, present but unverified, verified once but
 * since expired, verified for a DIFFERENT identity than the requester,
 * or the refusal-by-policy branch (jurisdiction lane not covered — a
 * policy configuration the operator must name, never a guess).
 *
 * WHY THIS EXCEPTION, NOT A GENERIC VALIDATION ERROR
 * --------------------------------------------------
 * KYC gate failures have downstream legal consequence: money paid
 * against a person the operator cannot lawfuly identify is an AML
 * finding. Each refusal therefore carries a STABLE error code — the
 * gate consumers (withdrawal approval lane, VerifyWithdrawalKycJob,
 * operator consoles) branch on the code, not on the sentence, so
 * localization and copy edits never break the law-shaped branching.
 *
 * NEVER in context: document numbers, file paths with PII, the user
 * record payload. Context is ids + status names only.
 */
class WithdrawalKycException extends RuntimeException
{
    public const CODE_EVIDENCE_MISSING = 'WITHDRAWAL_KYC_EVIDENCE_MISSING';

    public const CODE_EVIDENCE_UNVERIFIED = 'WITHDRAWAL_KYC_EVIDENCE_UNVERIFIED';

    public const CODE_EVIDENCE_EXPIRED = 'WITHDRAWAL_KYC_EVIDENCE_EXPIRED';

    public const CODE_IDENTITY_MISMATCH = 'WITHDRAWAL_KYC_IDENTITY_MISMATCH';

    public const CODE_STATE_FORBIDS = 'WITHDRAWAL_KYC_STATE_FORBIDS';

    public const CODE_GATE_DISABLED = 'WITHDRAWAL_KYC_GATE_DISABLED';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function evidenceMissing(int $userId, array $context = []): self
    {
        return new self(
            sprintf('Withdrawal KYC gate: user #%d has NO identity evidence on file; money may not leave.', $userId),
            self::CODE_EVIDENCE_MISSING,
            $context + ['user_id' => $userId],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function evidenceUnverified(int $userId, string $documentStatus, array $context = []): self
    {
        return new self(
            sprintf(
                'Withdrawal KYC gate: user #%d has identity evidence at status [%s] — not verified; money may not leave against unproven identity.',
                $userId,
                $documentStatus,
            ),
            self::CODE_EVIDENCE_UNVERIFIED,
            $context + ['user_id' => $userId, 'document_status' => $documentStatus],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function evidenceExpired(int $userId, string $expiredAt, array $context = []): self
    {
        return new self(
            sprintf(
                'Withdrawal KYC gate: user #%d presented evidence that expired at %s; stale identity proves nothing today.',
                $userId,
                $expiredAt,
            ),
            self::CODE_EVIDENCE_EXPIRED,
            $context + ['user_id' => $userId, 'expired_at' => $expiredAt],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function identityMismatch(int $userId, string $detail, array $context = []): self
    {
        return new self(
            sprintf('Withdrawal KYC gate: identity check for user #%d stopped (%s); the requester is not the verified person.', $userId, $detail),
            self::CODE_IDENTITY_MISMATCH,
            $context + ['user_id' => $userId, 'detail' => $detail],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function stateForbids(string $withdrawalReference, string $currentStatus, array $context = []): self
    {
        return new self(
            sprintf(
                'Withdrawal [%s]: KYC gating cannot run at state [%s]; only pre-approval states may enter the gate.',
                $withdrawalReference,
                $currentStatus,
            ),
            self::CODE_STATE_FORBIDS,
            $context + ['withdrawal_reference' => $withdrawalReference, 'current_status' => $currentStatus],
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function gateDisabled(array $context = []): self
    {
        return new self(
            'Withdrawal KYC gate is disabled by configuration; callers may not lean on a gate that is not there.',
            self::CODE_GATE_DISABLED,
            $context,
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
