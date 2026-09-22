<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\SelfExclusion;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * SelfExclusionActivated — the immutable envelope that seals the
 * moment the exclusion went live: exclusion identity, effective
 * time, scope, and the request fingerprint. Dispatched inside the
 * activating transaction.
 */
final class SelfExclusionActivated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly SelfExclusion $exclusion,
        public readonly string $activatedAt,
    ) {}

    /**
     * THE ANCHOR: one activation = one audit, forever.
     */
    public function activationFingerprint(): string
    {
        return hash('sha256', 'glo-se-act|'.(string) $this->exclusion->request_fingerprint.'|'.$this->activatedAt);
    }
}
