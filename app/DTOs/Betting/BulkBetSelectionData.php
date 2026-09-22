<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

/**
 * One sanitised selection inside a bulk purchase request.
 *
 * IDENTICAL FIDELITY RULES AS THE SINGLE PURCHASE
 * The number stays a digit string (leading zeros significant), the stake stays a
 * decimal string (never a JSON float) and no server-owned field exists to carry a
 * multiplier, payout, wallet or status. The shape was validated by BulkBetRequest;
 * legality belongs to the existing BetPurchaseService pipeline each selection is
 * executed through.
 */
final readonly class BulkBetSelectionData
{
    public function __construct(
        public string $marketKey,
        public string $number,
        public string $stake,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromRequestArray(array $payload): self
    {
        return new self(
            marketKey: trim((string) ($payload['market'] ?? '')),
            number: trim((string) ($payload['number'] ?? '')),
            stake: trim((string) ($payload['stake'] ?? '')),
        );
    }

    /**
     * The payload handed to BetPurchaseService::purchaseFromRequest for one item.
     *
     * @return array{draw_id: int, market: string, number: string, stake: string, idempotency_key: string}
     */
    public function toPurchasePayload(int $drawId, string $derivedClientKey): array
    {
        return [
            'draw_id' => $drawId,
            'market' => $this->marketKey,
            'number' => $this->number,
            'stake' => $this->stake,
            'idempotency_key' => $derivedClientKey,
        ];
    }

    /**
     * A stable identity string for logging and duplicate detection inside one
     * bulk request. Two identical selections in one request are a client bug,
     * not two bets (two bets on the same number are legitimate, but they must
     * arrive as two distinct client keys, which the identity includes upstream).
     *
     * @return array<string, string>
     */
    public function identity(): array
    {
        return [
            'market' => $this->marketKey,
            'number' => $this->number,
            'stake' => $this->stake,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->identity();
    }
}
