<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * NotificationFailureReason — why a notification did not reach its
 * player. The vocabulary is closed so the receipt lane can always
 * resolve a provider's native code into a desk pronouncement.
 */
enum NotificationFailureReason: string
{
    case ProviderFailure = 'provider_failure';
    case InvalidDestination = 'invalid_destination';
    case Throttled = 'throttled';
    case Expired = 'expired';
    case SuppressedQuietWindow = 'suppressed_quiet_window';
    case SuppressedPreference = 'suppressed_preference';
    case SuppressedDuplicate = 'suppressed_duplicate';
    case TemplateDisabled = 'template_disabled';
    case PayloadRejected = 'payload_rejected';

    /**
     * Whether the failure admits a retry at all (invalid destination /
     * thrown suppression / disabled template do not — they are
     * facts of the world, not of timing).
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::ProviderFailure, self::Throttled => true,
            self::InvalidDestination, self::Expired,
            self::SuppressedQuietWindow, self::SuppressedPreference,
            self::SuppressedDuplicate, self::TemplateDisabled,
            self::PayloadRejected => false,
        };
    }

    /**
     * Whether this refusal seals the row forever from retries.
     */
    public function isTerminal(): bool
    {
        return ! $this->isRetryable();
    }
}
