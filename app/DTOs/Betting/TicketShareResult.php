<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

use App\Models\TicketShare;

/**
 * The committed outcome of creating a ticket share link.
 *
 * THE RAW TOKEN LIVES HERE, NOWHERE ELSE
 * The database stores only token_hash (SHA-256). The raw token is returned to the
 * owner exactly once — inside this object, at creation time — and is never logged,
 * never storeable afterwards and never recoverable. Losing it means creating a
 * new share, which is the correct security property for a bearer link.
 */
final readonly class TicketShareResult
{
    /**
     * @param  string  $rawToken  the plaintext bearer token — returned once
     * @param  string  $shareUrl  the public URL the token is embedded in
     */
    public function __construct(
        public TicketShare $share,
        public string $rawToken,
        public string $shareUrl,
    ) {
    }

    public function expiresAt(): ?string
    {
        return $this->share->expires_at?->toIso8601String();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'share_id' => (int) $this->share->getKey(),
            'ticket_id' => (int) $this->share->ticket_id,
            'status' => $this->share->status->value,
            'token' => $this->rawToken,
            'url' => $this->shareUrl,
            'expires_at' => $this->expiresAt(),
            'created_at' => $this->share->created_at?->toIso8601String(),
        ];
    }
}
