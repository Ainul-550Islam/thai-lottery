<?php

declare(strict_types=1);

namespace App\DTOs\Compliance;

use App\Enums\ComplianceActionType;
use App\Exceptions\ComplianceActionException;

/**
 * Immutable action request: WHICH case, WHAT act, WHY (reason code),
 * BY WHOM (actor, never a client-claimed name) and the source evidence
 * the act rests on. The deterministic action key makes a re-delivered
 * act a replay and a differently-shaped act under the same key a fork.
 */
final readonly class ComplianceActionData
{
    public function __construct(
        public string $caseKey,
        public ComplianceActionType $actionType,
        public string $reasonCode,
        public int $actorUserId,
        public string $evidenceReference,
    ) {}

    public function actionKey(): string
    {
        return hash('sha256', sprintf(
            'comp-act:%s:%s:%s:%d:%s',
            $this->caseKey,
            $this->actionType->value,
            $this->reasonCode,
            $this->actorUserId,
            $this->evidenceReference,
        ));
    }

    /**
     * @throws ComplianceActionException
     */
    public static function fromInput(
        string $caseKey,
        string|ComplianceActionType $actionType,
        string $reasonCode,
        int $actorUserId,
        string $evidenceReference,
    ): ComplianceActionData {
        $key = strtolower(trim($caseKey));
        $reason = strtoupper(trim($reasonCode));
        $evidence = strtoupper(trim($evidenceReference));

        $type = $actionType instanceof ComplianceActionType
            ? $actionType
            : ComplianceActionType::tryFrom(strtolower(trim($actionType)));

        if (! preg_match('/^[0-9a-f]{64}$/', $key)) {
            throw ComplianceActionException::missingCase('the case key must be exactly 64 lowercase hex characters');
        }

        if (! $type instanceof ComplianceActionType) {
            throw ComplianceActionException::invalidAction('unknown compliance action word');
        }

        if (! preg_match('/^[A-Z0-9_]{3,32}$/', $reason)) {
            throw ComplianceActionException::invalidAction('a reason code is an UPPER_SNAKE 3-32 character token');
        }

        if ($actorUserId < 1) {
            throw ComplianceActionException::invalidAction('an actor handle must be a positive integer');
        }

        if (strlen($evidence) < 6 || strlen($evidence) > 96 || ! preg_match('/^[A-Z0-9:\-._]+$/', $evidence)) {
            throw ComplianceActionException::invalidAction('the source evidence must be a canonical 6-96 character token');
        }

        return new self(
            caseKey: $key,
            actionType: $type,
            reasonCode: $reason,
            actorUserId: $actorUserId,
            evidenceReference: $evidence,
        );
    }
}
