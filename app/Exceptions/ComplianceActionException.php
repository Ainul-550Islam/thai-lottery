<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Controlled-action refusals.
 *
 * - COMPLIANCEACT_INVALID_ACTION      unknown/refused action request.
 * - COMPLIANCEACT_MISSING_CASE        the action names no live case.
 * - COMPLIANCEACT_ALREADY_APPLIED     the deterministic act exists —
 *                                       replay serves the row; a different
 *                                       fact under the key is a fork.
 * - COMPLIANCEACT_RELEASE_HOLD_CONFLICT release attempted against a hold
 *                                       the SAME act never placed (courts
 *                                       lift only their own seals).
 * - COMPLIANCEACT_UNAUTHORIZED        non-admin hand on a controlled act.
 */
final class ComplianceActionException extends Exception
{
    public const CODE_INVALID_ACTION = 'COMPLIANCEACT_INVALID_ACTION';

    public const CODE_MISSING_CASE = 'COMPLIANCEACT_MISSING_CASE';

    public const CODE_ALREADY_APPLIED = 'COMPLIANCEACT_ALREADY_APPLIED';

    public const CODE_RELEASE_HOLD_CONFLICT = 'COMPLIANCEACT_RELEASE_HOLD_CONFLICT';

    public const CODE_UNAUTHORIZED = 'COMPLIANCEACT_UNAUTHORIZED';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $errorContext = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidAction(string $reason, array $context = []): self
    {
        return new self(sprintf('Compliance action refused: %s', $reason), self::CODE_INVALID_ACTION, $context + ['reason' => $reason]);
    }

    public static function missingCase(string $caseKey, array $context = []): self
    {
        return new self(
            sprintf('Compliance action refused: case [%s] is not a live file on this desk', substr($caseKey, 0, 16).'…'),
            self::CODE_MISSING_CASE,
            $context + ['case_key_prefix' => substr($caseKey, 0, 16)],
        );
    }

    public static function alreadyApplied(string $actionKey, array $context = []): self
    {
        return new self(
            sprintf('Compliance action refused: a different act already carries key [%s] — a fork', substr($actionKey, 0, 16).'…'),
            self::CODE_ALREADY_APPLIED,
            $context + ['action_key_prefix' => substr($actionKey, 0, 16)],
        );
    }

    public static function releaseHoldConflict(string $caseKey, string $why, array $context = []): self
    {
        return new self(
            sprintf('Compliance action refused: release conflicts — %s', $why),
            self::CODE_RELEASE_HOLD_CONFLICT,
            $context + ['case_key_prefix' => substr($caseKey, 0, 16), 'why' => $why],
        );
    }

    public static function unauthorized(int $actorUserId, array $context = []): self
    {
        return new self(
            sprintf('Compliance action refused: operator #%d lacks authority for controlled actions', $actorUserId),
            self::CODE_UNAUTHORIZED,
            $context + ['actor_user_id' => $actorUserId],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->errorContext;
    }
}
