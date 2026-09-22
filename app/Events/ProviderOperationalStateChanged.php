<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ProviderOperation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Exactly-once envelope after a provider operational seat changes.
 * SANITIZED: it carries the seat evidence digest; never
 * credentials, provider tokens, or raw lane payloads.
 */
final class ProviderOperationalStateChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly ProviderOperation $change,
        public readonly ?string $fromStatus,
    ) {
    }

    public function changeFingerprint(): string
    {
        return hash('sha256', 'glo-provop-evt|'.$this->change->change_fingerprint.'|'.(string) $this->fromStatus);
    }
}
