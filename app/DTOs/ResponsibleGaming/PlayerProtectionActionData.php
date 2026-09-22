<?php

declare(strict_types=1);

namespace App\DTOs\ResponsibleGaming;

use App\Enums\PlayerProtectionAction;
use App\Exceptions\PlayerProtectionActionException;

/**
 * Explicit protection action request: case key, action type, scope,
 * actor, reason and the deterministic ACTION KEY over those facts —
 * the same act re-pronounced replays free of side effects.
 */
final class PlayerProtectionActionData
{
    public readonly PlayerProtectionAction $actionType;

    public function __construct(
        public readonly string $caseKey,
        PlayerProtectionAction|string $actionType,
        public readonly string $scope,
        public readonly int $actorUserId,
        public readonly string $reasonCode,
    ) {
        $this->actionType = is_string($actionType) ? PlayerProtectionAction::from($actionType) : $actionType;
    }

    /**
     * @param  array{case_key:string, action_type:PlayerProtectionAction|string, actor_user_id:int|string, reason_code:string, scope?:string}  $data
     */
    public static function fromInput(array $data): self
    {
        $caseKey = strtolower(trim((string) ($data['case_key'] ?? '')));
        $reason = trim((string) ($data['reason_code'] ?? ''));
        $scope = strtolower(trim((string) ($data['scope'] ?? 'account')));

        if (! preg_match('/^[a-f0-9]{64}$/', $caseKey)) {
            throw PlayerProtectionActionException::invalidAction('case key must be 64 hex chars');
        }

        if (! preg_match('/^[A-Za-z0-9_:\-\. ]{4,96}$/', $reason)) {
            throw PlayerProtectionActionException::invalidAction('A reason must be a readable 4-96 character token');
        }

        if ($scope === '' || strlen($scope) > 32) {
            throw PlayerProtectionActionException::invalidAction('A scope must be a 1-32 character token');
        }

        return new self(
            caseKey: $caseKey,
            actionType: $data['action_type'] ?? throw PlayerProtectionActionException::invalidAction('An action type is required'),
            scope: $scope,
            actorUserId: (int) ($data['actor_user_id'] ?? 0),
            reasonCode: $reason,
        );
    }

    /**
     * Deterministic identity over the act facts — court stamps once.
     */
    public function actionKey(): string
    {
        return hash('sha256', implode('|', [
            'glo-ppa', $this->caseKey, $this->actionType->value,
            $this->scope, (string) $this->actorUserId, $this->reasonCode,
        ]));
    }

    /**
     * Releases are the counter-act of durable restrictions alone.
     */
    public function isReleasePronunciation(): bool
    {
        return $this->reasonCode === 'RELEASE' || str_starts_with($this->reasonCode, 'RELEASE:');
    }
}
