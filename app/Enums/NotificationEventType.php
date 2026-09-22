<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * NotificationEventType — the domain-event vocabulary for
 * transactional player notifications. The vocabulary is closed and
 * pronounced here; drivers phrase events in these terms alone.
 */
enum NotificationEventType: string
{
    case KycVerified = 'kyc_verified';
    case KycRejected = 'kyc_rejected';
    case DepositConfirmed = 'deposit_confirmed';
    case DepositFailed = 'deposit_failed';
    case WithdrawalApproved = 'withdrawal_approved';
    case WithdrawalPaid = 'withdrawal_paid';
    case WithdrawalRejected = 'withdrawal_rejected';
    case BetPlaced = 'bet_placed';
    case BetWon = 'bet_won';
    case PrizeClaimUpdate = 'prize_claim_update';
    case ComplianceHold = 'compliance_hold';
    case ComplianceReleased = 'compliance_released';
    case SelfExclusionStarted = 'self_exclusion_started';
    case LimitChanged = 'limit_changed';
    case SessionRevoked = 'session_revoked';
    case RealityCheck = 'reality_check';
    case Manual = 'manual';

    /**
     * The default channel lane this phenomenon lands in; preferences
     * may demote from here (except for mandatory notices) but never
     * promote above.
     */
    public function defaultChannel(): NotificationChannel
    {
        return NotificationChannel::InApp;
    }

    /**
     * The mandatory floor: compliance, security and exclusion law
     * are not preferences — the player cannot silence these rows.
     */
    public function isMandatory(): bool
    {
        return match ($this) {
            self::ComplianceHold, self::ComplianceReleased,
            self::SelfExclusionStarted, self::SessionRevoked,
            self::RealityCheck => true,
            self::KycVerified, self::KycRejected,
            self::DepositConfirmed, self::DepositFailed,
            self::WithdrawalApproved, self::WithdrawalPaid, self::WithdrawalRejected,
            self::BetPlaced, self::BetWon, self::PrizeClaimUpdate,
            self::LimitChanged, self::Manual => false,
        };
    }

    /**
     * Priority this phenomenon carries by default: mandatory notices
     * ride the Critical lane (they pierce quiet windows); everything
     * else is Normal.
     */
    public function defaultPriority(): NotificationPriority
    {
        return $this->isMandatory() ? NotificationPriority::Critical : NotificationPriority::Normal;
    }
}
