<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

/**
 * The controlled adjustment request.
 *
 * THE GRAMMAR — a correction needs all of:
 * - wallet_id: positive handle.
 * - amount: DECIMAL STRING WITH ITS SIGN INSIDE. +12.00 credits the
 *   wallet (liability rises, adjustment-equity contra absorbs), -12.00
 *   debits it. Zero adjustments are not adjustments and refuse.
 * - currency: 3-letter code; must equal the wallet's own — enforce by
 *   the service.
 * - reason: a full sentence (8-255 chars).
 * - source_evidence: the audit-opener (an internal ticket, a dispute
 *   id, a reconciliation key... always a canonical token).
 * - operator_identity: WHO signed (operator's user id, never a name the
 *   console makes up).
 *
 * COMPENSATING, NEVER EDITING: the adjustment service posts NEW entries
 * through the wallet's own flow. No update ever touches a historical
 * ledger row.
 */
final readonly class LedgerAdjustmentData
{
    public function __construct(
        public int $walletId,
        public string $amount,
        public string $currency,
        public string $reason,
        public string $sourceEvidence,
        public int $operatorUserId,
    ) {
    }

    /**
     * @throws \App\Exceptions\LedgerAdjustmentException
     */
    public static function fromInput(
        int $walletId,
        string $amount,
        string $currency,
        string $reason,
        string $sourceEvidence,
        int $operatorUserId,
    ): self {
        $amt = trim($amount);
        $cur = strtolower(trim($currency));
        $why = trim($reason);
        $evid = strtoupper(trim($sourceEvidence));

        if ($walletId < 1) {
            throw \App\Exceptions\LedgerAdjustmentException::malformed(
                'the wallet handle must be a positive integer',
            );
        }

        if (! preg_match('/^[+-]?\d+(\.\d{1,2})?$/', $amt)) {
            throw \App\Exceptions\LedgerAdjustmentException::malformed(
                'the amount must be a signed decimal string (money, never float)',
            );
        }

        if (extension_loaded('bcmath') ? bccomp($amt, '0', 2) === 0 : ((float) $amt === 0.0)) {
            throw \App\Exceptions\LedgerAdjustmentException::malformed(
                'a zero adjustment is not an adjustment — no movement at all',
            );
        }

        if (! preg_match('/^[a-z]{3}$/', $cur)) {
            throw \App\Exceptions\LedgerAdjustmentException::malformed(
                'the currency must be a 3-letter code',
            );
        }

        if (strlen($why) < 8 || strlen($why) > 255) {
            throw \App\Exceptions\LedgerAdjustmentException::malformed(
                'the reason must be 8-255 characters (a real sentence, not shorthand)',
            );
        }

        if (strlen($evid) < 8 || strlen($evid) > 64 || ! preg_match('/^[A-Z0-9:\-\._]+$/', $evid)) {
            throw \App\Exceptions\LedgerAdjustmentException::malformed(
                'the source evidence must be a canonical 8-64 character token',
            );
        }

        if ($operatorUserId < 1) {
            throw \App\Exceptions\LedgerAdjustmentException::malformed(
                'the operator handle must be a positive integer',
            );
        }

        return new self(
            walletId: $walletId,
            amount: $amt,
            currency: strtoupper($cur),
            reason: $why,
            sourceEvidence: $evid,
            operatorUserId: $operatorUserId,
        );
    }

    /**
     * The deterministic adjustment identity (wallet, evidence, amount):
     * a retry of THIS correction re-serves; any DIFFERENT correction
     * under the same evidence token forks and refuses.
     */
    public function adjustmentKey(): string
    {
        return hash('sha256', sprintf(
            'ledger-adj:%d:%s:%s:%s',
            $this->walletId,
            $this->sourceEvidence,
            $this->amount,
            $this->currency,
        ));
    }

    /**
     * Does the direction CREDIT the wallet?
     */
    public function isCredit(): bool
    {
        return str_starts_with($this->amount, '+') || ! str_starts_with($this->amount, '-');
    }
}
