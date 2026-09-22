<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

/**
 * The immutable input of a permutation request: "take my digits and cover every
 * arrangement of them" (the กลับเลข flow).
 *
 * WHAT THE CLIENT DECIDES
 * Which draw, which market, the base digits, the stake PER ARRANGEMENT, and the
 * client key. Everything else — how many arrangements exist, what they cost in
 * total, what each could pay — is derived server-side by the permutation engine
 * and the market payout catalogue. The total debited is arrangements × stake,
 * computed with bcmath; the client never sends a total.
 */
final readonly class PermutationRequestData
{
    public function __construct(
        public int $userId,
        public int $drawId,
        public string $marketKey,
        public string $digits,
        public string $stakePerArrangement,
        public string $clientKey,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromRequestArray(int $userId, array $payload): self
    {
        return new self(
            userId: $userId,
            drawId: (int) ($payload['draw_id'] ?? 0),
            marketKey: trim((string) ($payload['market'] ?? '')),
            digits: trim((string) ($payload['number'] ?? '')),
            stakePerArrangement: trim((string) ($payload['stake'] ?? '')),
            clientKey: trim((string) ($payload['client_key'] ?? '')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'draw_id' => $this->drawId,
            'market' => $this->marketKey,
            'number' => $this->digits,
            'stake' => $this->stakePerArrangement,
            'client_key' => $this->clientKey,
        ];
    }
}
