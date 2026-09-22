<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Controlled compliance actions, applied atomically through
 * ComplianceActionService which composes with the wallet/payment
 * lanes — it never re-implements their arithmetic.
 *
 *   Review   — desk reading/note act (audited, no side-effect on money)
 *   Restrict — restrict the subject's wallet (money-out blocked)
 *   Hold     — a compliance hold on the subject's wallet AND a hold
 *              marker on the case (a case under hold may not close)
 *   Release  — lift the hold/restriction previously applied BY THE SAME
 *              DETERMINISTIC ACT FACT (courts only; conflicts refuse)
 *   Escalate — move the case to Escalated with evidence
 *   Clear    — desk pronounces the subject cleared for this case
 */
enum ComplianceActionType: string
{
    case Review = 'review';
    case Restrict = 'restrict';
    case Hold = 'hold';
    case Release = 'release';
    case Escalate = 'escalate';
    case Clear = 'clear';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Review',
            self::Restrict => 'Restrict',
            self::Hold => 'Hold',
            self::Release => 'Release',
            self::Escalate => 'Escalate',
            self::Clear => 'Clear',
        };
    }

    /**
     * Whether the act mechanically touches the subject's wallet lane.
     */
    public function touchesWallet(): bool
    {
        return in_array($this, [self::Restrict, self::Hold, self::Release], true);
    }

    /**
     * Whether the act must be RELEASED before the case may close.
     */
    public function requiresReleaseBeforeClosure(): bool
    {
        return in_array($this, [self::Restrict, self::Hold], true);
    }
}
