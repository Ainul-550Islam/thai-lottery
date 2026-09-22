<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The financial reconciliation lane's pronounced refusals — fail-closed
 * by constitution: money grammar errors never answer silently.
 *
 * - LEDGER_RECON_MALFORMED          grammar never accepted the pack.
 * - LEDGER_RECON_NOT_FOUND          named wallet/account missing.
 * - LEDGER_RECON_BALANCE_MISMATCH   expected ≠ aggregate beyond zero
 *                                   tolerance.
 * - LEDGER_RECON_CURRENCY_MISMATCH  the lanes don't even speak the same
 *                                   coin.
 * - LEDGER_RECON_MISSING_LEDGER     a wallet with money on paper but no
 *                                   postings at all — the ledger lane
 *                                   itself is absent.
 * - LEDGER_RECON_DUPLICATE          a different ask under the same
 *                                   conversation id — fork.
 */
final class LedgerReconciliationException extends Exception
{
    public const CODE_MALFORMED = 'LEDGER_RECON_MALFORMED';

    public const CODE_NOT_FOUND = 'LEDGER_RECON_NOT_FOUND';

    public const CODE_BALANCE_MISMATCH = 'LEDGER_RECON_BALANCE_MISMATCH';

    public const CODE_CURRENCY_MISMATCH = 'LEDGER_RECON_CURRENCY_MISMATCH';

    public const CODE_MISSING_LEDGER = 'LEDGER_RECON_MISSING_LEDGER';

    public const CODE_DUPLICATE = 'LEDGER_RECON_DUPLICATE';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $errorContext = [],
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $reason, array $context = []): self
    {
        return new self(
            sprintf('Ledger reconciliation refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Ledger reconciliation refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function balanceMismatch(int $walletId, string $expected, string $actual, array $context = []): self
    {
        return new self(
            sprintf('Ledger reconciliation refused: wallet #%d expected %s but the ledger answers %s', $walletId, $expected, $actual),
            self::CODE_BALANCE_MISMATCH,
            $context + ['wallet_id' => $walletId, 'expected' => $expected, 'actual' => $actual],
        );
    }

    public static function currencyMismatch(int $walletId, string $walletCurrency, string $laneCurrency, array $context = []): self
    {
        return new self(
            sprintf('Ledger reconciliation refused: wallet #%d speaks %s, the lane speaks %s', $walletId, $walletCurrency, $laneCurrency),
            self::CODE_CURRENCY_MISMATCH,
            $context + ['wallet_id' => $walletId, 'wallet_currency' => $walletCurrency, 'lane_currency' => $laneCurrency],
        );
    }

    public static function missingLedger(int $walletId, array $context = []): self
    {
        return new self(
            sprintf('Ledger reconciliation refused: wallet #%d has paper money but no ledger postings — the lane is MISSING', $walletId),
            self::CODE_MISSING_LEDGER,
            $context + ['wallet_id' => $walletId],
        );
    }

    public static function duplicate(string $reconciliationKey, array $context = []): self
    {
        return new self(
            sprintf('Ledger reconciliation refused: a different ask presented under key %s...', substr($reconciliationKey, 0, 12)),
            self::CODE_DUPLICATE,
            $context + ['reconciliation_key' => $reconciliationKey],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->errorContext;
    }
}
