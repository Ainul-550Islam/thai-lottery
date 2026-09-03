<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * The immutable, already-sanitised input of one bet purchase request.
 *
 * WHAT THIS DTO IS ALLOWED TO CARRY
 * Only what a client is permitted to decide: who is buying, which draw, which
 * market, which number, how much, and the idempotency key that identifies the
 * request. Every other value a purchase needs - the wallet, the balance, the payout
 * multiplier, the potential payout and the risk verdict - is derived server-side and
 * therefore has no field here. The absence of those fields is the security control:
 * a value that cannot be represented cannot be trusted, and no later stage of the
 * pipeline has anywhere to read a client-supplied multiplier or payout from.
 *
 * WHY THE NUMBER AND THE STAKE ARE STRINGS
 * '007' is not 7 and '099' is not 99. A lottery number is a fixed-width string of
 * digits and is never converted to an integer anywhere in this pipeline. The stake
 * is an exact decimal string for the same reason in reverse: 10.55 as a float is
 * not 10.55, and a purchase that debits 10.549999999 is a defect. Both are kept as
 * the client sent them and are parsed by the Phase 4.2 value objects, which is the
 * only place a conversion happens.
 *
 * WHY THE CONSTRUCTOR IS NOT THE ENTRY POINT FOR REQUEST DATA
 * fromRequestArray() is, because it is the choke point that DROPS forbidden keys and
 * records that it did so. Constructing this object directly from a request array
 * would let a future caller pass a payload straight through.
 */
final readonly class BetPurchaseData
{
    /**
     * Keys a client is never allowed to influence.
     *
     * These are not merely ignored in silence: fromRequestArray() records which of
     * them were present so the purchase can be logged as having received - and
     * refused - a privileged value. Silent stripping would hide a client attempting
     * to set its own payout.
     *
     * @var list<string>
     */
    public const FORBIDDEN_CLIENT_KEYS = [
        'wallet_id',
        'wallet',
        'balance',
        'available_balance',
        'multiplier',
        'payout_multiplier',
        'payout',
        'potential_payout',
        'actual_payout',
        'risk',
        'risk_decision',
        'risk_verdict',
        'skip_risk',
        'skipRisk',
        'ignore_risk',
        'ignoreRisk',
        'force',
        'force_reserve',
        'forceReserve',
        'admin_override',
        'admin_override_risk',
        'adminOverrideRisk',
        'bypass_risk',
        'bypassRisk',
        'bet_number',
        'ticket_number',
        'ticket_id',
        'status',
        'stake_amount',
        'number_limit_id',
        'ledger_account_id',
        'financial_transaction_id',
    ];

    /**
     * @param  int  $userId  the authenticated player, resolved by the caller and
     *                       never read from a request body field
     * @param  int  $drawId  the draw being bought into
     * @param  string  $marketKey  a configured market key such as '3d_direct'
     * @param  string  $rawNumber  the number exactly as the client sent it, digits only
     * @param  string  $rawStake  the stake exactly as the client sent it, as a decimal string
     * @param  string  $idempotencyKey  the client's request identity
     * @param  array<string, scalar|null>  $metadata  safe diagnostic context only
     * @param  list<string>  $ignoredClientFields  forbidden keys that were present and dropped
     */
    public function __construct(
        public int $userId,
        public int $drawId,
        public string $marketKey,
        public string $rawNumber,
        public string $rawStake,
        public string $idempotencyKey,
        public array $metadata = [],
        public array $ignoredClientFields = [],
    ) {
    }

    /**
     * Build from an untrusted associative payload.
     *
     * The user id is a separate argument on purpose: it comes from the authenticated
     * session, never from the payload, so a payload claiming user_id 1 cannot buy on
     * behalf of user 1.
     *
     * Values are read as strings without casting. A payload sending the number as
     * the JSON number 7 rather than the string '007' is deliberately NOT repaired
     * into '007' here; it is passed through as '7' so that Phase 4.2's strict parse
     * reports the client bug instead of this class silently inventing two leading
     * zeros.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromRequestArray(int $userId, array $payload): self
    {
        $ignored = [];

        foreach (self::FORBIDDEN_CLIENT_KEYS as $forbidden) {
            if (array_key_exists($forbidden, $payload)) {
                $ignored[] = $forbidden;
            }
        }

        return new self(
            userId: $userId,
            drawId: self::readInt($payload, 'draw_id'),
            marketKey: self::readString($payload, 'market'),
            rawNumber: self::readString($payload, 'number'),
            rawStake: self::readString($payload, 'stake'),
            idempotencyKey: self::readString($payload, 'idempotency_key'),
            metadata: self::readMetadata($payload),
            ignoredClientFields: $ignored,
        );
    }

    /**
     * A copy carrying additional safe diagnostic metadata.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            userId: $this->userId,
            drawId: $this->drawId,
            marketKey: $this->marketKey,
            rawNumber: $this->rawNumber,
            rawStake: $this->rawStake,
            idempotencyKey: $this->idempotencyKey,
            metadata: array_merge($this->metadata, $metadata),
            ignoredClientFields: $this->ignoredClientFields,
        );
    }

    /**
     * True when the client tried to supply a value it is not allowed to decide.
     */
    public function receivedForbiddenFields(): bool
    {
        return $this->ignoredClientFields !== [];
    }

    /**
     * The material identity of this purchase, excluding the idempotency key.
     *
     * Used to decide whether a request presenting an already-used key is the SAME
     * purchase - a legitimate replay - or a DIFFERENT purchase reusing a key, which
     * is refused. The stake is included verbatim rather than normalised, because
     * '10.00' and '10.000' are the same money but a client that alternates between
     * them across retries is behaving inconsistently and that is worth surfacing at
     * the comparison site rather than hiding here.
     *
     * @return array<string, string>
     */
    public function identity(): array
    {
        return [
            'user_id' => (string) $this->userId,
            'draw_id' => (string) $this->drawId,
            'market' => $this->marketKey,
            'number' => $this->rawNumber,
            'stake' => $this->rawStake,
        ];
    }

    /**
     * A stable single-line fingerprint of identity(), for logging and comparison.
     */
    public function fingerprint(): string
    {
        $parts = [];

        foreach ($this->identity() as $key => $value) {
            $parts[] = $key.'='.$value;
        }

        return implode('|', $parts);
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
            'number' => $this->rawNumber,
            'stake' => $this->rawStake,
            'idempotency_key' => $this->idempotencyKey,
            'metadata' => $this->metadata,
            'ignored_client_fields' => $this->ignoredClientFields,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function readString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        if (is_string($value)) {
            return trim($value);
        }

        // An int arriving where a string was expected is stringified WITHOUT
        // padding or reformatting, so the strict parser downstream can refuse it.
        if (is_int($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function readInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        // A numeric string identifier is accepted, because a route parameter is
        // always a string. Anything non-numeric becomes 0, which no draw can have,
        // so validation refuses it with a clear "draw not found".
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    private static function readMetadata(array $payload): array
    {
        $metadata = $payload['metadata'] ?? [];

        if (! is_array($metadata)) {
            return [];
        }

        $safe = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            // Only scalars survive. Nested structures and objects are dropped so
            // that nothing unbounded or unserialisable reaches a JSON column, and so
            // that a nested 'payout' key cannot smuggle a privileged value in.
            if ($value === null || is_scalar($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
