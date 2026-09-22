<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\Currency;
use App\Enums\PrizePayoutMethod;

/**
 * Immutable definition of ONE outbound payout transfer.
 *
 * WHAT ONE OBJECT NAMES
 * ---------------------
 * The full identity of one money-leaving attempt: the batch and payout it
 * executes for, the beneficiary it must reach, the amount and currency, the
 * payout method, and the idempotency anchor that makes replays safe.
 *
 * WHY TRANSFERS CARRY THEIR OWN ANCHOR
 * ------------------------------------
 * The transfer is the LAST thing that can retry: a network cut after the
 * gateway debited but before we recorded the credit, a worker dying after
 * the external commit and before the local one. Transfer idempotency can
 * therefore not ride on "the batch id" — a re-run manufactures a new batch
 * of the same payouts. The anchor is instead derived from what is
 * INVARIANT under every re-run: (payout reference + amount + currency +
 * beneficiary).
 *
 * IDEMPOTENCY KEY
 * ---------------
 * sha256 over (batch key + payout reference + beneficiary + amount +
 * currency). The batch key participates so the same payout in TWO DIFFERENT
 * batches is a visibly different transfer lane — which should never happen
 * legalistically and is the exact anomaly operations wants named — while
 * within one batch a replay derives the same transfer and joins it.
 *
 * Decimals never go through float anywhere below.
 */
class PayoutTransferData
{
    /**
     * @param  string  $batchKey  Deterministic key of the owning batch.
     * @param  string  $payoutReference  The payout this transfer settles.
     * @param  array<string, mixed>|null  $beneficiary  Beneficiary details the
     *                                                 method needs (wallet id
     *                                                 for wallet, account keys
     *                                                 for bank). Personal data
     *                                                 flows here ONLY as
     *                                                 payment-routing data, and
     *                                                 toArray never emits it —
     *                                                 projections keep it out.
     * @param  string  $amount  2-decimal money string of THIS transfer.
     * @param  array<string, mixed>  $context  Execution context (run tag,
     *                                        gateway hint id, executor pid —
     *                                        never credentials).
     */
    public function __construct(
        public readonly string $batchKey,
        public readonly string $payoutReference,
        public readonly int $beneficiaryUserId,
        public readonly PrizePayoutMethod $method,
        public readonly string $amount,
        public readonly Currency $currency,
        public readonly ?array $beneficiary,
        public readonly string $idempotencyKey,
        public readonly array $context = [],
    ) {
    }

    /**
     * The replay anchor for one transfer lane.
     *
     * @param  int|null  $beneficiaryUserId
     */
    public static function deriveIdempotencyKey(
        string $batchKey,
        string $payoutReference,
        int $beneficiaryUserId,
        string $amount,
        Currency $currency,
    ): string {
        return hash('sha256', sprintf(
            'payout-transfer:%s:%s:%d:%s:%s',
            $batchKey,
            $payoutReference,
            $beneficiaryUserId,
            bcadd($amount, '0.00', 2),
            $currency->value,
        ));
    }

    public function amountIsWellFormed(): bool
    {
        return preg_match('/^\d+(\.\d{1,2})?$/', $this->amount) === 1;
    }

    /**
     * The transfer lane in projection form. The beneficiary payload is
     * deliberately REDUCTED to its owner identity — account numbers are not
     * part of projections/analytics and never cross this boundary.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'batch_key' => $this->batchKey,
            'payout_reference' => $this->payoutReference,
            'beneficiary_user_id' => $this->beneficiaryUserId,
            'method' => $this->method->value,
            'amount' => bcadd($this->amount, '0.00', 2),
            'currency' => $this->currency->value,
            'idempotency_key' => $this->idempotencyKey,
            'context' => $this->context,
        ];
    }
}
