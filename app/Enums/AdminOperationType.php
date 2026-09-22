<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AdminOperationType — an operational-action VOCABULARY. It names
 * what lane an operation touches; it never restates the business
 * rules the owning desk already carries. Execution routes back to
 * the owning service; this enum is identity, not behaviour.
 */
enum AdminOperationType: string
{
    case UserSuspend = 'user_suspend';
    case UserReactivate = 'user_reactivate';
    case WalletFreeze = 'wallet_freeze';
    case WalletUnfreeze = 'wallet_unfreeze';
    case DrawPause = 'draw_pause';
    case TicketBlock = 'ticket_block';
    case PayoutHold = 'payout_hold';
    case PayoutRelease = 'payout_release';
    case ComplianceFlag = 'compliance_flag';
    case SystemFlagChange = 'system_flag_change';

    /**
     * Whether the lane touches a concrete, existing subject row
     * (target reference then becomes mandatory evidence).
     */
    public function requiresTargetReference(): bool
    {
        return $this !== self::SystemFlagChange;
    }

    /**
     * Human-readable lane for safe audit rendering.
     */
    public function lane(): string
    {
        return match ($this) {
            self::UserSuspend, self::UserReactivate => 'user',
            self::WalletFreeze, self::WalletUnfreeze => 'wallet',
            self::DrawPause => 'draw',
            self::TicketBlock => 'ticket',
            self::PayoutHold, self::PayoutRelease => 'payout',
            self::ComplianceFlag => 'compliance',
            self::SystemFlagChange => 'system',
        };
    }
}
