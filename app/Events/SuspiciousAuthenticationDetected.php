<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SecurityEvent;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * SuspiciousAuthenticationDetected — published EXACTLY ONCE for a
 * newly persisted suspicious-authentication row (the service guards
 * re-dispatch by checking the row landed fresh).
 */
final class SuspiciousAuthenticationDetected
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SecurityEvent $securityEvent,
        public readonly ?string $ipAddress,
        public readonly string $ownerReference,
    ) {}

    /**
     * THE ANCHOR: this detection pronouncement may audit once.
     */
    public function detectionFingerprint(): string
    {
        return hash('sha256', 'glo-susp|'.(string) $this->securityEvent->event_fingerprint);
    }
}
