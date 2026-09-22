<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * PlayerProtectionCaseException — protection-case lifecycle refusals:
 * invalid transition, missing evidence, unresolved restriction, fork.
 */
final class PlayerProtectionCaseException extends RuntimeException
{
    public const MALFORMED = 'PPC_MALFORMED';

    public const INVALID_TRANSITION = 'PPC_INVALID_TRANSITION';

    public const MISSING_EVIDENCE = 'PPC_MISSING_EVIDENCE';

    public const UNRESOLVED_RESTRICTION = 'PPC_UNRESOLVED_RESTRICTION';

    public const UNAUTHORIZED_CLOSURE = 'PPC_UNAUTHORIZED_CLOSURE';

    public const DUPLICATE = 'PPC_DUPLICATE';

    public const NOT_FOUND = 'PPC_NOT_FOUND';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Player protection case refused: '.$message);
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

    public static function malformed(string $reason): self
    {
        return new self(self::MALFORMED, $reason, ['reason' => $reason]);
    }

    public static function invalidTransition(string $caseKey, string $from, string $to): self
    {
        return new self(self::INVALID_TRANSITION, sprintf(
            'case [%s] may not move %s → %s', substr($caseKey, 0, 12), $from, $to,
        ), ['case_key' => $caseKey, 'from' => $from, 'to' => $to]);
    }

    public static function missingEvidence(string $trigger): self
    {
        return new self(self::MISSING_EVIDENCE, sprintf(
            'trigger [%s] carries no risk-indicator evidence', $trigger,
        ), ['trigger_reason' => $trigger]);
    }

    public static function unresolvedRestriction(string $caseKey): self
    {
        return new self(self::UNRESOLVED_RESTRICTION, sprintf(
            'case [%s] seals only after its durable restrictions are released', substr($caseKey, 0, 12),
        ), ['case_key' => $caseKey]);
    }

    public static function unauthorizedClosure(string $caseKey, int $actorId): self
    {
        return new self(self::UNAUTHORIZED_CLOSURE, sprintf(
            'actor [%d] may not seal case [%s]', $actorId, substr($caseKey, 0, 12),
        ), ['case_key' => $caseKey, 'actor_user_id' => $actorId]);
    }

    public static function duplicate(string $caseKey): self
    {
        return new self(self::DUPLICATE, sprintf(
            'case [%s] exists under different facts', substr($caseKey, 0, 12),
        ), ['case_key' => $caseKey]);
    }

    public static function notFound(string $caseKey): self
    {
        return new self(self::NOT_FOUND, sprintf(
            'case [%s] is unknown to this desk', substr($caseKey, 0, 12),
        ), ['case_key' => $caseKey]);
    }
}
