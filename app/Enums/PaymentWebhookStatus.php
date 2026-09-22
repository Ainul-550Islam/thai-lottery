<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of ONE inbound provider webhook as evidence:
 *
 *   Received  — the envelope persisted; nothing trusted yet
 *   Verified  — signature + replay window + event identity all checked
 *   Applied   — its facts were applied to internal state, exactly once
 *   Rejected  — refused (signature, window, identity, payload) with cause
 *   Duplicate — already-known evidence; a no-op on purpose
 *
 * Rejected and Duplicate are terminal. Verified is terminal for state
 * the envelope carries but nothing needs applying for; Applied is the
 * terminal of a webhook that MOVED facts.
 */
enum PaymentWebhookStatus: string
{
    case Received = 'received';
    case Verified = 'verified';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::Verified => 'Verified',
            self::Applied => 'Applied',
            self::Rejected => 'Rejected',
            self::Duplicate => 'Duplicate',
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return match ($this) {
            self::Received => in_array($next, [self::Verified, self::Rejected, self::Duplicate], true),
            self::Verified => in_array($next, [self::Applied, self::Rejected, self::Duplicate], true),
            self::Applied, self::Rejected, self::Duplicate => false,
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Applied, self::Rejected, self::Duplicate], true);
    }
}
