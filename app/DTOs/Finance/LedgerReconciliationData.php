<?php

declare(strict_types=1);

namespace App\DTOs\Finance;

/**
 * The wallet-ledger reconciliation input pack.
 *
 * WHAT IS IN THE PACK
 * - wallet_id + currency: the coined lane under comparison.
 * - expected_balance: what the wallet row itself claims its whole
 *   money to be (balance + locked_balance — locked money never leaves
 *   the liability; it is always owed).
 * - ledger_aggregate: what the wallet-liability lane's own postings
 *   claim on this wallet (credits − debits).
 * - reservation_effect: what the live reservation lane holds against
 *   this wallet (Σ open reservations), for the second-order check.
 * - fingerprint: sha256 over the pack — deterministic identity of THIS
 *   exact comparison of THIS exact state.
 *
 * Money is decimal-string only, never float.
 */
final readonly class LedgerReconciliationData
{
    public function __construct(
        public int $walletId,
        public string $currency,
        public string $expectedBalance,
        public string $ledgerAggregate,
        public string $reservationEffect,
        public string $fingerprint,
    ) {
    }

    /**
     * @throws \App\Exceptions\LedgerReconciliationException
     */
    public static function fromInput(
        int $walletId,
        string $currency,
        string $expectedBalance,
        string $ledgerAggregate,
        string $reservationEffect,
        string $fingerprint,
    ): self {
        $fp = strtolower(trim($fingerprint));
        $cur = strtolower(trim($currency));

        if ($walletId < 1) {
            throw \App\Exceptions\LedgerReconciliationException::malformed(
                'the wallet handle must be a positive integer',
            );
        }

        if (! preg_match('/^[a-z]{3}$/', $cur)) {
            throw \App\Exceptions\LedgerReconciliationException::malformed(
                'the currency must be a 3-letter code',
            );
        }

        foreach (['expected_balance' => $expectedBalance, 'ledger_aggregate' => $ledgerAggregate, 'reservation_effect' => $reservationEffect] as $field => $value) {
            if (! preg_match('/^-?\d+(\.\d{1,2})?$/', trim($value))) {
                throw \App\Exceptions\LedgerReconciliationException::malformed(
                    sprintf('the %s must be a decimal string (money, never float)', $field),
                );
            }
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw \App\Exceptions\LedgerReconciliationException::malformed(
                'the reconciliation fingerprint must be exactly 64 lowercase hex characters',
            );
        }

        return new self(
            walletId: $walletId,
            currency: strtoupper($cur),
            expectedBalance: trim($expectedBalance),
            ledgerAggregate: trim($ledgerAggregate),
            reservationEffect: trim($reservationEffect),
            fingerprint: $fp,
        );
    }

    /**
     * The fingerprint of THIS exact wallet state — the deterministic
     * identity the reconciliation court uses for replay/dedupe of one
     * comparison conversation.
     */
    public static function fingerprintOf(
        int $walletId,
        string $currency,
        string $expectedBalance,
        string $ledgerAggregate,
        string $reservationEffect,
    ): string {
        return hash('sha256', sprintf(
            'ledger-recon:%s:%s:%s:%s:%s',
            $walletId,
            strtoupper($currency),
            $expectedBalance,
            $ledgerAggregate,
            $reservationEffect,
        ));
    }

    /**
     * The deterministic conversation id per wallet (lanes reuse one row
     * per wallet conversation, everything else rotates through its
     * status).
     */
    public function reconciliationKey(): string
    {
        return hash('sha256', sprintf('ledger-recon-key:%d', $this->walletId));
    }
}
