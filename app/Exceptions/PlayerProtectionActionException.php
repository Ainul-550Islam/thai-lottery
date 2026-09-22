<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * PlayerProtectionActionException — protection-act refusals: invalid
 * act, missing live file, replayed/forked act, release conflicts,
 * actors outside the desk's authority.
 */
final class PlayerProtectionActionException extends RuntimeException
{
    public const INVALID_ACTION = 'PPA_INVALID_ACTION';

    public const MISSING_CASE = 'PPA_MISSING_CASE';

    public const ALREADY_APPLIED = 'PPA_ALREADY_APPLIED';

    public const RELEASE_CONFLICT = 'PPA_RELEASE_CONFLICT';

    public const FOREIGN_LOCK_CONFLICT = 'PPA_FOREIGN_LOCK_CONFLICT';

    public const UNAUTHORIZED = 'PPA_UNAUTHORIZED';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Player protection action refused: '.$message);
    }

    public function errorCode(): string
    {
        return $this->deskCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function invalidAction(string $reason): self
    {
        return new self(self::INVALID_ACTION, $reason, ['reason' => $reason]);
    }

    public static function missingCase(string $caseKey): self
    {
        return new self(self::MISSING_CASE, sprintf(
            'case [%s] is not a live file on this desk', substr($caseKey, 0, 12),
        ), ['case_key' => $caseKey]);
    }

    public static function alreadyApplied(string $actionKey): self
    {
        return new self(self::ALREADY_APPLIED, sprintf(
            'act [%s] exists under different facts — a fork of the act', substr($actionKey, 0, 12),
        ), ['action_key' => $actionKey]);
    }

    public static function releaseConflict(string $caseKey, string $reason): self
    {
        return new self(self::RELEASE_CONFLICT, sprintf(
            'case [%s] holds no matching unreleased act to release: %s', substr($caseKey, 0, 12), $reason,
        ), ['case_key' => $caseKey, 'reason' => $reason]);
    }

    public static function foreignLockConflict(string $caseKey, int $walletId): self
    {
        return new self(self::FOREIGN_LOCK_CONFLICT, sprintf(
            'wallet #%d is already sealed under a different act than [%s]', $walletId, substr($caseKey, 0, 12),
        ), ['case_key' => $caseKey, 'wallet_id' => $walletId]);
    }

    public static function unauthorized(int $actorUserId): self
    {
        return new self(self::UNAUTHORIZED, sprintf(
            'actor [%d] lacks desk authority for controlled protection acts', $actorUserId,
        ), ['actor_user_id' => $actorUserId]);
    }
}
