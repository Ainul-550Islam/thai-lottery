<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused or failed REAL MONEY payout.
 *
 * WHAT THIS CLASS IS FOR
 * ----------------------
 * A payout discharges a real obligation: it moves money from the prize-expense
 * ledger account into a player's wallet (or into a linked payout row awaiting
 * batch processing). When that cannot be done correctly — the payout row is in
 * a state where processing is undefined, the amount no longer balances the
 * expectation pinned at creation time, the wallet that must receive the money
 * is missing or locked, the destination state refuses — the failure is thrown
 * as a PayoutException and caught by finance code that then compensates or
 * refuses: it is the class that owns the semantics of "real money failed to
 * land, restore invariants".
 *
 * WHAT IT IS NOT
 * --------------
 * It is not SettlementSimulationException: a simulated run never moves money,
 * so its failures have nothing to compensate and must never be routed into a
 * code path that touches a balance. The two classes deliberately do not share
 * factories, error codes or catch sites.
 *
 * It is not FinancialException either: that one carries transaction-level
 * finance invariants (idempotency claim mismatch, unbalanced entry), which
 * may be thrown deep inside WalletService and do not identify which payout run
 * suffered them. A payout operation wraps whatever it catches with the payout
 * identity so a report can answer "which payout failed and why" without
 * parsing a stack trace.
 *
 * CONTEXT CONTRACT
 * Context carries ids, status strings and amounts as decimal strings. No
 * stack trace, SQL, credential, or personal datum is ever placed in it.
 */
class PayoutException extends RuntimeException
{
    public const CODE_PAYOUT_NOT_FOUND = 'PAYOUT_NOT_FOUND';

    public const CODE_STATE_FORBIDS = 'PAYOUT_STATE_FORBIDS';

    public const CODE_ALREADY_PAID = 'PAYOUT_ALREADY_PAID';

    public const CODE_AMOUNT_DRIFT = 'PAYOUT_AMOUNT_DRIFT';

    public const CODE_WALLET_UNAVAILABLE = 'PAYOUT_WALLET_UNAVAILABLE';

    public const CODE_CURRENCY_MISMATCH = 'PAYOUT_CURRENCY_MISMATCH';

    public const CODE_TRANSACTION_REPLAYED = 'PAYOUT_TRANSACTION_REPLAYED';

    public const CODE_BATCH_EMPTY = 'PAYOUT_BATCH_EMPTY';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The payout row the caller asked for does not exist.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notFound(int $payoutId, array $context = []): self
    {
        return new self(
            sprintf('Payout #%d does not exist.', $payoutId),
            self::CODE_PAYOUT_NOT_FOUND,
            $context + ['payout_id' => $payoutId],
        );
    }

    /**
     * The payout is in a status from which the attempted step is undefined.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function stateForbids(int $payoutId, string $status, string $attemptedStep, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout #%d is %s and cannot be %s.',
                $payoutId,
                $status,
                $attemptedStep,
            ),
            self::CODE_STATE_FORBIDS,
            $context + [
                'payout_id' => $payoutId,
                'payout_status' => $status,
                'attempted_step' => $attemptedStep,
            ],
        );
    }

    /**
     * Money for this payout already landed. Paying again would pay twice.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyPaid(int $payoutId, ?int $financialTransactionId = null, array $context = []): self
    {
        return new self(
            sprintf('Payout #%d is already completed; paying again would double-pay the player.', $payoutId),
            self::CODE_ALREADY_PAID,
            $context + [
                'payout_id' => $payoutId,
                'financial_transaction_id' => $financialTransactionId,
            ],
        );
    }

    /**
     * The amount captured at payout creation no longer agrees with the amount
     * the claiming computation derives. Paying either one would be wrong.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function amountDrift(int $payoutId, string $expected, string $actual, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout #%d was pinned at %s but the current computation derives %s; drift is reported, never paid.',
                $payoutId,
                $expected,
                $actual,
            ),
            self::CODE_AMOUNT_DRIFT,
            $context + [
                'payout_id' => $payoutId,
                'expected_amount' => $expected,
                'actual_amount' => $actual,
            ],
        );
    }

    /**
     * The wallet that must receive the money is missing or unusable.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function walletUnavailable(int $payoutId, int $userId, ?string $district = null, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout #%d cannot find a usable destination wallet for user #%d%s.',
                $payoutId,
                $userId,
                $district !== null ? ' in currency '.$district : '',
            ),
            self::CODE_WALLET_UNAVAILABLE,
            $context + [
                'payout_id' => $payoutId,
                'user_id' => $userId,
                'currency' => $district,
            ],
        );
    }

    /**
     * The payout's currency and the destination wallet's currency disagree.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function currencyMismatch(int $payoutId, string $expectedCurrency, string $actualCurrency, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout #%d is denominated in %s but the destination wallet holds %s; paying across currencies is refused.',
                $payoutId,
                $expectedCurrency,
                $actualCurrency,
            ),
            self::CODE_CURRENCY_MISMATCH,
            $context + [
                'payout_id' => $payoutId,
                'expected_currency' => $expectedCurrency,
                'actual_currency' => $actualCurrency,
            ],
        );
    }

    /**
     * The finance engine reports the credit for this payout was already
     * claimed under the same idempotency key with a different payload — the
     * same key discharging different money is never allowed silently.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function transactionReplayed(int $payoutId, string $idempotencyKey, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout #%d idempotency key [%s] was already claimed with a different request; the second claim is refused.',
                $payoutId,
                $idempotencyKey,
            ),
            self::CODE_TRANSACTION_REPLAYED,
            $context + [
                'payout_id' => $payoutId,
                'idempotency_key' => $idempotencyKey,
            ],
        );
    }

    /**
     * A payout batch was asked to run with zero eligible rows; nothing to do
     * is a permanent outcome, not a transient error.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function batchEmpty(string $reason, array $context = []): self
    {
        return new self(
            sprintf('No payout was eligible for batch processing: %s.', $reason),
            self::CODE_BATCH_EMPTY,
            $context,
        );
    }

    /**
     * The stable, machine-readable error classification.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Safe diagnostic context. Never holds secrets or personal data.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * A copy of this exception with additional diagnostic context layered on.
     * Exceptions stay immutable-this returns a new instance rather than
     * mutating, so a thrown instance can be enriched on the way up without a
     * readonly violation.
     *
     * @param  array<string, scalar|null>  $additionalContext
     */
    public function withContext(array $additionalContext): static
    {
        /** @var array<string, scalar|null> $merged */
        $merged = $this->context + $additionalContext;

        return new static($this->message, $this->errorCode, $merged, $this);
    }
}
