<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * SecurityEventType — the security ledger's event vocabulary.
 * Every row the SecurityEventService persists carries one of these;
 * the vocabulary is closed and pronounced here, never invented by
 * callers.
 */
enum SecurityEventType: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case MfaChallengeIssued = 'mfa_challenge_issued';
    case MfaVerified = 'mfa_verified';
    case MfaFailed = 'mfa_failed';
    case MfaLocked = 'mfa_locked';
    case PasswordChanged = 'password_changed';
    case DeviceRegistered = 'device_registered';
    case DeviceTrusted = 'device_trusted';
    case DeviceRevoked = 'device_revoked';
    case SessionIssued = 'session_issued';
    case SessionRevoked = 'session_revoked';
    case SessionRotated = 'session_rotated';
    case SessionExpired = 'session_expired';
    case SuspiciousAuthentication = 'suspicious_authentication';
    case SecurityChange = 'security_change';

    /**
     * Riskiness floor of the event for the review sweep's filter:
     * suspicious acts review at High by default.
     */
    public function defaultFloor(): SecurityRiskLevel
    {
        return match ($this) {
            self::SuspiciousAuthentication => SecurityRiskLevel::High,
            self::MfaLocked => SecurityRiskLevel::High,
            self::LoginFailed, self::MfaFailed => SecurityRiskLevel::Medium,
            default => SecurityRiskLevel::Low,
        };
    }
}
