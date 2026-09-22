<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PlayerProtectionCase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PlayerProtectionCaseEscalated — the immutable envelope flying when
 * the desk's clock (or an admin) escalates a protection file:
 * case identity, the escalating actor reference, and the moment.
 */
final class PlayerProtectionCaseEscalated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PlayerProtectionCase $protectionCase,
        public readonly string $reason,
        public readonly string $escalatedBy,
        public readonly string $escalatedAt,
    ) {}

    /**
     * THE ANCHOR: one escalation pronouncement = one audit, forever.
     */
    public function escalationFingerprint(): string
    {
        return hash('sha256', 'glo-pp-esc|'.(string) $this->protectionCase->case_key.'|'.$this->escalatedAt);
    }
}
