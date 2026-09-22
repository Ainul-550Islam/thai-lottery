<?php

declare(strict_types=1);

namespace App\DTOs\Prize;

/**
 * Disbursement identity.
 *
 * THE GRAMMAR
 * - payout_id: positive handle to the APPROVED prize claim's payout row
 *   (the money-out ground truth elsewhere in the house).
 * - batch_id: optional payout-batch handle when settling within a batch.
 * - amount / currency: decimal string + 3-letter code; amount must be
 *   strictly positive (a zero disbursement is not a disbursement).
 * - settlement_fingerprint: 64 lowercase hex over (payout ref|amount|
 *   currency) — caller-presented; the SERVICE re-derives and compares
 *   against the payout's own row, never on trust.
 */
final readonly class PrizeDisbursementData
{
    public function __construct(
        public int $payoutId,
        public ?int $batchId,
        public string $amount,
        public string $currency,
        public string $settlementFingerprint,
    ) {
    }

    /**
     * @throws \App\Exceptions\PrizeDisbursementException
     */
    public static function fromInput(
        int $payoutId,
        ?int $batchId,
        string $amount,
        string $currency,
        string $settlementFingerprint,
    ): self {
        $fp = strtolower(trim($settlementFingerprint));
        $amt = trim($amount);
        $cur = strtolower(trim($currency));

        if ($payoutId < 1 || ($batchId !== null && $batchId < 1)) {
            throw \App\Exceptions\PrizeDisbursementException::malformed(
                'payout and batch handles must be positive integers',
            );
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amt)) {
            throw \App\Exceptions\PrizeDisbursementException::malformed(
                'the amount must be a decimal string (money, never float)',
            );
        }

        if (extension_loaded('bcmath') ? bccomp($amt, '0', 2) !== 1 : ((float) $amt <= 0)) {
            throw \App\Exceptions\PrizeDisbursementException::malformed(
                'the amount must be strictly positive',
            );
        }

        if (! preg_match('/^[a-z]{3}$/', $cur)) {
            throw \App\Exceptions\PrizeDisbursementException::malformed(
                'the currency must be a 3-letter code',
            );
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw \App\Exceptions\PrizeDisbursementException::malformed(
                'the settlement fingerprint must be exactly 64 lowercase hex characters',
            );
        }

        return new self(
            payoutId: $payoutId,
            batchId: $batchId,
            amount: $amt,
            currency: strtoupper($cur),
            settlementFingerprint: $fp,
        );
    }

    /**
     * The disbursement-row identity: deterministic over (payout, batch,
     * amount, currency) — the same ask replays, a different ask forks.
     */
    public function disbursementKey(): string
    {
        return hash('sha256', sprintf(
            'prize-disb:%d:%s:%s:%s',
            $this->payoutId,
            $this->batchId !== null ? (string) $this->batchId : '-',
            $this->amount,
            $this->currency,
        ));
    }
}
