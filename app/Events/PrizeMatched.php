<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A confirmed draw's ticket was deterministically matched to a prize
 * tier — exactly once per NEW match conversation.
 *
 * THE PACKET RIDES THIS SEAM
 * References and the result fingerprint only. Never personal address
 * lines; the match row is the anchor everything else derives from.
 */
final class PrizeMatched
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $matchKey,
        public readonly int $drawId,
        public readonly int $betId,
        public readonly ?int $ticketId,
        public readonly string $prizeTier,
        public readonly string $matchedAmount,
        public readonly string $resultFingerprint,
        public readonly string $matchedAt,
    ) {}

    /**
     * The audit-scribe's dedupe anchor per (match, act).
     */
    public static function anchorFor(string $matchKey, string $action): string
    {
        return hash('sha256', sprintf('prize-match-audit:%s:%s', $matchKey, $action));
    }

    /**
     * @return array<string, mixed>
     */
    public function logPayload(): array
    {
        return [
            'match_key' => substr($this->matchKey, 0, 12),
            'bet' => $this->betId,
            'tier' => $this->prizeTier,
            'amount' => $this->matchedAmount,
            'fingerprint' => substr($this->resultFingerprint, 0, 12),
        ];
    }
}
