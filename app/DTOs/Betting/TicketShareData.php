<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

/**
 * The immutable input of a "share my ticket" request.
 *
 * WHAT THE CLIENT DECIDES
 * Only which ticket, and optionally how long the link lives (bounded server-side
 * by config). The token, the expiry timestamp and every status decision are
 * server-owned: the client receives the finished link and can revoke it later,
 * nothing more.
 */
final readonly class TicketShareData
{
    public function __construct(
        public int $userId,
        public string $ticketIdentifier,
        public ?int $ttlHours,
    ) {
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromRequestArray(int $userId, string $ticketIdentifier, array $payload): self
    {
        $ttl = $payload['ttl_hours'] ?? null;

        return new self(
            userId: $userId,
            ticketIdentifier: trim($ticketIdentifier),
            ttlHours: is_int($ttl) ? $ttl : (is_string($ttl) && ctype_digit($ttl) ? (int) $ttl : null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'ticket' => $this->ticketIdentifier,
            'ttl_hours' => $this->ttlHours,
        ];
    }
}
