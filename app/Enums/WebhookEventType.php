<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Normalized webhook event types across all payment providers.
 */
enum WebhookEventType: string
{
    case DepositSuccess = 'deposit.success';
    case DepositFailed = 'deposit.failed';
    case DepositExpired = 'deposit.expired';
    case DepositCancelled = 'deposit.cancelled';
    case WithdrawalSuccess = 'withdrawal.success';
    case WithdrawalFailed = 'withdrawal.failed';
    case ChargeRefunded = 'charge.refunded';
    case DisputeCreated = 'dispute.created';
    case Unknown = 'unknown';

    public function isDeposit(): bool
    {
        return in_array($this, [
            self::DepositSuccess,
            self::DepositFailed,
            self::DepositExpired,
            self::DepositCancelled,
        ], true);
    }

    public function isWithdrawal(): bool
    {
        return in_array($this, [
            self::WithdrawalSuccess,
            self::WithdrawalFailed,
        ], true);
    }

    public function isSuccess(): bool
    {
        return in_array($this, [
            self::DepositSuccess,
            self::WithdrawalSuccess,
        ], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [
            self::DepositFailed,
            self::DepositExpired,
            self::DepositCancelled,
            self::WithdrawalFailed,
        ], true);
    }

    public function isReversal(): bool
    {
        return $this === self::ChargeRefunded;
    }
}
