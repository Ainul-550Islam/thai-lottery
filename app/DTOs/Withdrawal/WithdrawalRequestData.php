<?php

declare(strict_types=1);

namespace App\DTOs\Withdrawal;

use App\Enums\Currency;

/**
 * Immutable definition of ONE withdrawal request: the ask "move this much
 * of my wallet to that destination".
 *
 * WHAT ONE OBJECT NAMES
 * ---------------------
 * Money getting out is the highest-risk primitive of the whole system;
 * everything the downstream lanes need must be true at construction: the
 * amount as a 2-decimal string, the destination (method + destination
 * details + beneficiary identity check deferred to the KYC gate), and the
 * deterministic request anchor that makes any replay/retries collapse onto
 * ONE withdrawal row.
 *
 * THE REQUEST ANCHOR
 * ------------------
 * sha256 over (user + amount + currency + method + destination fingerprint
 * + day-of-request). The day component is the "same ask, same day"
 * invariant: a repeated identical request inside one day is a replay and
 * joins the existing row (network retries, double clicks); the next day's
 * push is once again a fresh ask. Withdrawal-service ULIDs never appear
 * in this DTO — the row Ulid is the audit-facing id AFTER creation; the
 * anchor here is the creation-facing idempotency.
 *
 * DESTINATION
 * -----------
 * The destination is opaque structured data here (bank refs, wallet
 * handles, etc. — method-shapes differ). It is SANITIZED at the caller;
 * this DTO guarantees it is carried as an array (when present) and the
 * fingerprint is derivable over its canonical JSON — the audit layer
 * carries masking.
 *
 * Decimals never go through float anywhere below.
 */
class WithdrawalRequestData
{
    /**
     * @param  string  $amount  2-decimal money string the user asked out.
     * @param  string  $method  Withdrawal method key (bank_transfer, bkash…).
     * @param  array<string, mixed>|null  $destination  Method-shaped routing
     *                                                 details of where the
     *                                                 money goes.
     * @param  array<string, mixed>  $context  Channel + operator context
     *                                        (device/session refs); no PII
     *                                        beyond what destination already
     *                                        named.
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $amount,
        public readonly Currency $currency,
        public readonly string $method,
        public readonly ?array $destination,
        public readonly string $requestDate,
        public readonly ?string $note = null,
        public readonly array $context = [],
    ) {
    }

    /**
     * The deterministic anchor for one ask of one day.
     */
    public static function deriveRequestAnchor(
        int $userId,
        string $amount,
        Currency $currency,
        string $method,
        ?array $destination,
        string $requestDate,
    ): string {
        return hash('sha256', sprintf(
            'withdrawal-request:%d:%s:%s:%s:%s:%s',
            $userId,
            bcadd($amount, '0.00', 2),
            $currency->value,
            $method,
            $destination === null ? '-' : self::canonicalDestination($destination),
            $requestDate,
        ));
    }

    public function requestAnchor(): string
    {
        return self::deriveRequestAnchor(
            $this->userId,
            $this->amount,
            $this->currency,
            $this->method,
            $this->destination,
            $this->requestDate,
        );
    }

    public function amountIsWellFormed(): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $this->amount) === 1
            && bccomp(bcadd($this->amount, '0.00', 2), '0.00', 2) > 0;
    }

    /**
     * Projecting the ask WITHOUT destination details: the audit/report
     * surface gets the corridor, never the routing payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'request_anchor' => $this->requestAnchor(),
            'user_id' => $this->userId,
            'amount' => bcadd($this->amount, '0.00', 2),
            'currency' => $this->currency->value,
            'method' => $this->method,
            'destination_present' => $this->destination !== null,
            'request_date' => $this->requestDate,
            'note' => $this->note,
            'context' => $this->context,
        ];
    }

    /**
     * Deterministic destination fingerprint (canonical JSON encoding —
     * key-sorted — so destination orderings don't fork the anchor).
     *
     * @param  array<string, mixed>  $destination
     */
    private static function canonicalDestination(array $destination): string
    {
        ksort($destination);

        return hash('sha256', (string) json_encode($destination, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
