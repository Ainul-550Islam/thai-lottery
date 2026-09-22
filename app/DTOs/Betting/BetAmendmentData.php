<?php

declare(strict_types=1);

namespace App\DTOs\Betting;

/**
 * The immutable input of one bet-amendment request.
 *
 * WHAT AN AMENDMENT MAY CHANGE
 * The number and/or the stake of an existing bet, inside the same draw, in the
 * same market. The market itself is captured from the request when present so the
 * service can refuse market changes explicitly rather than implying they work —
 * replacing '2d_top' with '3d_direct' is a different product, not an amendment
 * (it changes digit width, multiplier architecture and risk counters, and a
 * player wanting it can cancel and re-bet).
 *
 * MONEY FIELDS ARRIVE AS STRINGS
 * The stake follows the same rule as every other money value in this codebase:
 * decimal string, at most two fraction digits, never a JSON float. The number
 * follows the lottery rule: digit string, leading zeros significant.
 *
 * SERVER-OWNED FIELDS ARE UNREPRESENTABLE
 * No wallet id, no refund amount, no potential payout, no status. The replacement
 * purchase derives every one of them server-side through the existing
 * BetPurchaseService pipeline — the amendment layer never re-implements risk,
 * wallet or ledger rules.
 */
final readonly class BetAmendmentData
{
    public const FORBIDDEN_CLIENT_KEYS = [
        'user_id',
        'wallet_id',
        'refund_amount',
        'potential_payout',
        'actual_payout',
        'multiplier',
        'payout_multiplier',
        'status',
        'risk',
        'risk_decision',
        'force',
    ];

    /**
     * @param  array<string, scalar|null>  $metadata
     * @param  list<string>  $ignoredClientFields
     */
    public function __construct(
        public int $userId,
        public string $betIdentifier,
        public ?string $newNumber,
        public ?string $newStake,
        public string $clientKey,
        public array $metadata = [],
        public array $ignoredClientFields = [],
    ) {
    }

    /**
     * Build from an untrusted associative payload.
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
            newNumber: self::readNullableString($payload, 'number'),
            newStake: self::readNullableString($payload, 'stake'),
            clientKey: (string) self::readNullableString($payload, 'client_key'),
            metadata: [],
            ignoredClientFields: $ignored,
        );
    }

    /**
     * Whether the request would change anything at all.
     */
    public function changesSomething(): bool
    {
        return $this->newNumber !== null || $this->newStake !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'bet' => $this->betIdentifier,
            'number' => $this->newNumber,
            'stake' => $this->newStake,
            'client_key' => $this->clientKey,
            'ignored_client_fields' => $this->ignoredClientFields,
        ];
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
