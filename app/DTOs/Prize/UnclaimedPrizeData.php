<?php

declare(strict_types=1);

namespace App\DTOs\Prize;

use App\Enums\Currency;

/**
 * Immutable fact-pack for ONE expired/unclaimed prize.
 *
 * WHAT ONE OBJECT NAMES
 * ---------------------
 * The complete identity of one lapsed prize obligation at sweep time: the
 * payout reference, the (rightful but absent) claimant, the amount that
 * lapsed, the exact moment the window died, and the sweep context (job
 * tag, chunk, run id). Everything the disposal lane knows about the prize
 * travels in this shape — the service reads it, audits it, and idempotency
 * keys derive from it.
 *
 * WHY A DTO HERE
 * --------------
 * The sweeper walks hundreds of rows; each row's facts must be captured as
 * of selection, frozen against the drift a long sweep could otherwise
 * smear across rows (a payout row edited mid-sweep). The DTO is the
 * freeze.
 *
 * THE EXPIRATION TIMESTAMP TRAVELS WITH THE DATA
 * ----------------------------------------------
 * The window edge the prize lapsed against is carried as an ISO-8601
 * STRING — pinning the edge the claim-window lane itself stamped rather
 * than re-deriving it. A replay re-deriving a slightly different edge
 * (config change between runs) would mint a different key and duplicate a
 * disposal row; carried edges cannot.
 *
 * Decimals never go through float anywhere below.
 */
class UnclaimedPrizeData
{
    /**
     * @param  string  $amount  Lapsed prize as a 2-decimal string.
     * @param  string  $expiredAt  ISO-8601 moment the claim window closed.
     * @param  array<string, mixed>  $sweepContext  run tag, chunk index, source.
     */
    public function __construct(
        public readonly string $payoutReference,
        public readonly int $claimantUserId,
        public readonly string $amount,
        public readonly Currency $currency,
        public readonly string $expiredAt,
        public readonly ?int $betId = null,
        public readonly array $sweepContext = [],
    ) {
    }

    /**
     * The deterministic disposal identity of one lapsed prize.
     *
     * Derivation: sha256 over (reference + amount + currency + expiry edge).
     * The claimant participates not here but on the row: one payout has one
     * rightful claimant by construction, so the reference already implies
     * them. Identical lapses always derive the same key; a re-run lands on
     * the same disposal record.
     */
    public static function deriveDisposalKey(
        string $payoutReference,
        string $amount,
        Currency $currency,
        string $expiredAt,
    ): string {
        return hash('sha256', sprintf(
            'unclaimed-prize:%s:%s:%s:%s',
            $payoutReference,
            bcadd($amount, '0.00', 2),
            $currency->value,
            $expiredAt,
        ));
    }

    public function disposalKey(): string
    {
        return self::deriveDisposalKey(
            $this->payoutReference,
            $this->amount,
            $this->currency,
            $this->expiredAt,
        );
    }

    public function amountIsWellFormed(): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $this->amount) === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'disposal_key' => $this->disposalKey(),
            'payout_reference' => $this->payoutReference,
            'claimant_user_id' => $this->claimantUserId,
            'amount' => bcadd($this->amount, '0.00', 2),
            'currency' => $this->currency->value,
            'expired_at' => $this->expiredAt,
            'bet_id' => $this->betId,
            'sweep_context' => $this->sweepContext,
        ];
    }
}
