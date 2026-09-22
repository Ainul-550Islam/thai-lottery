<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * AuthenticationMethod — the methods the gate recognizes and their
 * standing facts. The current state of an attempt lives on the
 * attempt row itself; here the method pronounces what second
 * factor it DEMANDS and how it is recorded.
 */
enum AuthenticationMethod: string
{
    case Password = 'password';
    case Otp = 'otp';
    case Passkey = 'passkey';
    case Mfa = 'mfa';

    /**
     * Methods that require a verified second challenge before the
     * session may issue. Password + Mfa stand apart from OTP/passkey
     * which complete the attempt themselves.
     */
    public function demandsSecondFactor(): bool
    {
        return match ($this) {
            self::Password => true,
            self::Otp, self::Passkey, self::Mfa => false,
        };
    }
}
