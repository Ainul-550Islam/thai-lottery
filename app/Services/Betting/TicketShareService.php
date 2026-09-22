<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\DTOs\Betting\TicketShareData;
use App\DTOs\Betting\TicketShareResult;
use App\Enums\TicketShareStatus;
use App\Enums\TicketStatus;
use App\Exceptions\TicketShareException;
use App\Models\Ticket;
use App\Models\TicketShare;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Revocable bearer share links for tickets.
 *
 * TOKEN HYGIENE (the entire security story)
 * - The raw token is 32 random bytes (256 bits), base64url-encoded (43 chars).
 *   It is returned exactly once, inside TicketShareResult, at creation.
 * - Only SHA-256(raw) is persisted (token_hash, unique). A full-table read
 *   leaks nothing usable and the unique constraint makes a duplicate token
 *   impossible to store.
 * - Malformed, unknown, revoked and lapsed tokens all fail identically via
 *   shareUnavailable(): the resolution endpoint confirms nothing about which
 *   links exist.
 *
 * EXPIRY IS COMPUTE-ON-READ
 * effectiveStatus() compares expires_at to now() on every read, so a lapsed
 * link is dead even if the sweeper never runs; the sweeper only normalises the
 * stored status for reporting.
 *
 * RE-CREATING ROTATES
 * Creating a share for a ticket that already has an active link atomically
 * replaces the stored hash and resets views+expiry. Only the newest token
 * works afterwards, which is what a player expects from "generate a new link".
 */
class TicketShareService
{
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /**
     * Create (or rotate) the share link for a ticket the caller owns.
     *
     * @throws TicketShareException
     */
    public function create(TicketShareData $data): TicketShareResult
    {
        $this->assertEnabled();

        $ticket = $this->resolveTicket($data->ticketIdentifier, $data->userId);

        if (in_array($ticket->status, [TicketStatus::Cancelled, TicketStatus::Expired], true)) {
            throw TicketShareException::ticketUnavailable($data->ticketIdentifier, [
                'user_id' => $data->userId,
                'reason' => 'ticket_' . $ticket->status->value,
            ]);
        }

        $ttlHours = $this->clampTtl($data->ttlHours);
        $token = $this->generateToken();
        $hash = $this->hashToken($token);

        /** @var TicketShare $share */
        $share = $this->database->transaction(function () use ($data, $ticket, $hash, $ttlHours): TicketShare {
            /** @var TicketShare|null $existing */
            $existing = TicketShare::query()
                ->where('ticket_id', $ticket->getKey())
                ->where('user_id', $data->userId)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'token_hash' => $hash,
                    'views' => 0,
                    'expires_at' => now()->addHours($ttlHours),
                    'revoked_at' => null,
                ]);
                $existing->save();

                return $existing;
            }

            return TicketShare::query()->create([
                'uuid' => (string) Str::uuid(),
                'ticket_id' => (int) $ticket->getKey(),
                'user_id' => $data->userId,
                'token_hash' => $hash,
                'status' => TicketShareStatus::Active,
                'views' => 0,
                'expires_at' => now()->addHours($ttlHours),
            ]);
        }, 3);

        return new TicketShareResult(
            share: $share,
            rawToken: $token,
            shareUrl: $this->shareUrl($token),
        );
    }

    /**
     * Resolve a bearer token to its share (with the ticket relation loaded).
     * Callers receive the model so they can decide how much of the ticket to
     * expose; the transport layer is responsible for coarse output.
     *
     * @throws TicketShareException for malformed, unknown, revoked or lapsed tokens
     */
    public function resolve(string $token): TicketShare
    {
        $this->assertEnabled();

        if (! $this->isWellFormedToken($token)) {
            throw TicketShareException::shareUnavailable();
        }

        /** @var TicketShare|null $share */
        $share = TicketShare::query()
            ->where('token_hash', $this->hashToken($token))
            ->with('ticket')
            ->first();

        if ($share === null || ! $share->isUsable()) {
            throw TicketShareException::shareUnavailable();
        }

        $share->increment('views');

        return $share;
    }

    /**
     * Revoke a share the caller owns. Revoking an already-revoked share is a
     * quiet no-op so retries are safe; revoking a share that is not the
     * caller's own is the same refusal as an unknown share.
     *
     * @throws TicketShareException
     */
    public function revoke(int $shareId, int $userId): TicketShare
    {
        $this->assertEnabled();

        /** @var TicketShare|null $share */
        $share = TicketShare::query()->where('user_id', $userId)->find($shareId);

        if ($share === null) {
            throw TicketShareException::shareUnavailable();
        }

        if ($share->status !== TicketShareStatus::Revoked) {
            $share->forceFill([
                'status' => TicketShareStatus::Revoked,
                'revoked_at' => now(),
            ]);
            $share->save();
        }

        return $share;
    }

    /**
     * The caller's share links for a ticket, newest first.
     *
     * @return list<TicketShare>
     *
     * @throws TicketShareException
     */
    public function listFor(string $ticketIdentifier, int $userId): array
    {
        $ticket = $this->resolveTicket($ticketIdentifier, $userId);

        return TicketShare::query()
            ->where('ticket_id', $ticket->getKey())
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    /**
     * Backfill stored Expired on stored-active rows past their expiry moment.
     * Read-time expiry already makes such links dead; this only normalises
     * reporting.
     *
     * @return int rows updated
     */
    public function sweepExpired(): int
    {
        return TicketShare::query()
            ->expiredActive()
            ->update(['status' => TicketShareStatus::Expired->value]);
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * @throws TicketShareException
     */
    private function resolveTicket(string $identifier, int $userId): Ticket
    {
        $query = Ticket::query()->where('user_id', $userId);
        $query->where(static function ($q) use ($identifier): void {
            if (ctype_digit($identifier)) {
                $q->where('id', (int) $identifier);
            } else {
                $q->where('ticket_number', $identifier)->orWhere('uuid', $identifier);
            }
        });

        /** @var Ticket|null $ticket */
        $ticket = $query->first();

        // Enumeration-safe: not-yours and does-not-exist are one refusal.
        if ($ticket === null) {
            throw TicketShareException::ticketUnavailable($identifier, ['user_id' => $userId]);
        }

        return $ticket;
    }

    private function clampTtl(?int $requested): int
    {
        $default = max(1, (int) config('lottery.sharing.ttl_hours', 72));
        $min = max(1, (int) config('lottery.sharing.min_ttl_hours', 1));
        $max = max($min, (int) config('lottery.sharing.max_ttl_hours', 720));

        $ttl = $requested ?? $default;

        return max($min, min($max, $ttl));
    }

    private function assertEnabled(): void
    {
        if (! (bool) config('lottery.sharing.enabled', true)) {
            throw TicketShareException::disabled();
        }
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function isWellFormedToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    private function shareUrl(string $token): string
    {
        $base = rtrim((string) config('app.url', ''), '/');

        return $base . '/api/v1/tickets/shared/' . $token;
    }
}
