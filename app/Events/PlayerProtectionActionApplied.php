<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\PlayerProtectionAct;
use App\Models\PlayerProtectionCase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * PlayerProtectionActionApplied — the immutable envelope sealing the
 * applied act: case identity, act identity, and the act's resulting
 * restriction state at apply time. Dispatched inside the apply
 * transaction.
 */
final class PlayerProtectionActionApplied
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly PlayerProtectionAct $action,
        public readonly PlayerProtectionCase $protectionCase,
        public readonly string $appliedAt,
    ) {}

    /**
     * THE ANCHOR: one applied act = one audit, forever.
     */
    public function actionFingerprint(): string
    {
        return hash('sha256', 'glo-ppa-evt|'.(string) $this->action->action_key.'|'.$this->appliedAt);
    }
}
