<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

use App\Enums\BetCancellationReason;

/**
 * The immutable, already-sanitised input of one bet-cancellation request.
 *
 * WHAT THIS DTO IS ALLOWED TO CARRY
 * Only what a client is permitted to decide: which bet (an identifier the client
 * already knows), why (a reason code), and a client key for idempotency. The player
 * identity arrives as a separate constructor argument from the authenticated
 * session, exactly as BetPurchaseData does, so a payload naming another user
 * cannot cancel on their behalf.
 *
 * WHAT IT CANNOT CARRY
 * No wallet id, no refund amount, no bet status. The refund amount is ALWAYS the
 * bet's stored stake, read from the locked row inside the cancellation
 * transaction. A client-supplied refund amount is unrepresentable here, which is
 * the control: a value that cannot be represented cannot be trusted.
 */
final readonly class BetCancellationData
{
    public const FORBIDDEN_CLIENT_KEYS = [
        'user_id',
        'wallet_id',
        'refund_amount',
        'refund',
        'amount',
        'status',
        'cancelled_at',
        'cancelled_reason',
        'bet_status',
        'force',
        'skip_window',
    ];

    /**
     * @param  int  $userId  the authenticated player, resolved by the caller
     * @param  string  $betIdentifier  bet id, uuid or bet_number, resolved and
     *                               ownership-checked inside a user-scoped query
     * @param  BetCancellationReason  $reason  why the cancellation is happening
     * @param  string|null  $clientKey  the client's request key for idempotency,
     *                                  when one was supplied
     * @param  list<string>  $ignoredClientFields  forbidden keys present and dropped
     */
    public function __construct(
        public int $userId,
        public string $betIdentifier,
        public BetCancellationReason $reason,
        public ?string $clientKey = null,
        public array $ignoredClientFields = [],
    ) {
    }

    /**
     * Build from an untrusted associative payload.
     *
     * The user id is a separate argument: it comes from the auth context, never
     * from the payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromRequestArray(int $userId, string $betIdentifier, array $payload): self
    {
        $ignored = [];

        foreach (self::FORBIDDEN_CLIENT_KEYS as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                $ignored[] = $forbidden;
            }
        }

        return new self(
            userId: $userId,
            betIdentifier: trim($betIdentifier),
            reason: self::readReason($payload),
            clientKey: self::readNullableString($payload, 'client_key'),
            ignoredClientFields: $ignored,
        );
    }

    /**
     * The material identity of this cancellation, for idempotency comparison.
     *
     * @return array<string, string>
     */
    public function identity(): array
    {
        return [
            'user_id' => (string) $this->userId,
            'bet' => $this->betIdentifier,
            'reason' => $this->reason->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'bet' => $this->betIdentifier,
            'reason' => $this->reason->value,
            'client_key' => $this->clientKey,
            'ignored_client_fields' => $this->ignoredClientFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function readReason(array $payload): BetCancellationReason
    {
        $raw = $payload['reason'] ?? BetCancellationReason::PlayerRequest->value;

        if (is_string($raw)) {
            $reason = BetCancellationReason::tryFrom(strtolower(trim($raw)));

            if ($reason instanceof BetCancellationReason) {
                return $reason;
            }
        }

        return BetCancellationReason::PlayerRequest;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function readNullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
