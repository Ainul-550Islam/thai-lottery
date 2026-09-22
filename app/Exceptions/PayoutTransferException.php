<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused or broken OUTBOUND PAYOUT TRANSFER.
 *
 * COVERAGE
 * --------
 * Everything the transfer leg may refuse: a transfer whose idempotency
 * anchor already completed (duplicate — answered by replay, not by new
 * execution), an amount that drifted between approval and execution, a
 * transfer for a payout whose state can no longer support money leaving,
 * or a hard refusal from the payment abstraction (gateway down, account
 * invalid). Compensation (reversal) failures are separate and stay with
 * the reversal lane.
 *
 * WHAT IT IS NOT
 * --------------
 * PayoutException is one payout's ledger invariant. PayoutBatchException is
 * the group's manufacturing/lifecycle state. This exception stops ONE
 * attempted movement of money out — its catch site is the executor's
 * per-transfer try/catch, which turns the refusal into a failed-transfer
 * record and NEVER into a retried-silently double send.
 *
 * Context: transfer anchors, payout references, batch keys, decimal amount
 * strings, gateway-leg tags. Never a credential, stack, SQL, or personal
 * datum such as a document number.
 */
class PayoutTransferException extends RuntimeException
{
    public const CODE_DUPLICATE_TRANSFER = 'PAYOUT_TRANSFER_DUPLICATE';

    public const CODE_AMOUNT_DRIFT = 'PAYOUT_TRANSFER_AMOUNT_DRIFT';

    public const CODE_PAYOUT_STATE_FORBIDS = 'PAYOUT_TRANSFER_PAYOUT_STATE_FORBIDS';

    public const CODE_GATEWAY_REFUSED = 'PAYOUT_TRANSFER_GATEWAY_REFUSED';

    public const CODE_MISSING_PAYOUT = 'PAYOUT_TRANSFER_MISSING_PAYOUT';

    public const CODE_ALREADY_RUNNING = 'PAYOUT_TRANSFER_ALREADY_RUNNING';

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
     * A transfer with this anchor already committed. Thrown only when a
     * caller tries to force a fresh execution — replays handled through the
     * service's idempotent path never see this exception.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicateTransfer(string $idempotencyKey, array $context = []): self
    {
        return new self(
            sprintf(
                'Payout transfer [%s] already committed; force-re-executing it would spend the same obligation twice.',
                $idempotencyKey,
            ),
            self::CODE_DUPLICATE_TRANSFER,
            $context + ['idempotency_key' => $idempotencyKey],
        );
    }

    /**
     * The amount authorized no longer equals the amount the transfer
     * carries. Refusing is the only legal answer: paying MORE than approved
     * is embezzlement-shaped, LESS is a short-pay.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function amountDrift(
        string $payoutReference,
        string $authorized,
        string $attempted,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Payout [%s]: authorized amount %s drifted to attempted %s between approval and execution; transfer refused.',
                $payoutReference,
                $authorized,
                $attempted,
            ),
            self::CODE_AMOUNT_DRIFT,
            $context + ['payout_reference' => $payoutReference, 'authorized' => $authorized, 'attempted' => $attempted],
        );
    }

    /**
     * The payout's own state cannot support money leaving (already
     * completed, reversed, failed past recovery, cancelled).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function payoutStateForbids(
        string $payoutReference,
        string $payoutStatus,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Payout [%s] is %s; an outbound transfer may not leave against it.',
                $payoutReference,
                $payoutStatus,
            ),
            self::CODE_PAYOUT_STATE_FORBIDS,
            $context + ['payout_reference' => $payoutReference, 'payout_status' => $payoutStatus],
        );
    }

    /**
     * The payment abstraction refused the transfer. The refusal travels as
     * the canonical reason; the raw gateway message stays in logs, not in
     * the exception surface.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function gatewayRefused(
        string $payoutReference,
        string $reason,
        array $context = [],
    ): self {
        return new self(
            sprintf('Payout [%s]: payment gateway refused the transfer (%s).', $payoutReference, $reason),
            self::CODE_GATEWAY_REFUSED,
            $context + ['payout_reference' => $payoutReference, 'reason' => $reason],
        );
    }

    /**
     * The payout the transfer names does not exist.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function missingPayout(string $payoutReference, array $context = []): self
    {
        return new self(
            sprintf('Payout [%s] does not exist; nothing may transfer against it.', $payoutReference),
            self::CODE_MISSING_PAYOUT,
            $context + ['payout_reference' => $payoutReference],
        );
    }

    /**
     * The transfer lane owns its transaction boundary.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function alreadyRunning(int $transactionLevel, array $context = []): self
    {
        return new self(
            sprintf('Payout transfers own their transaction boundary; caller is at transaction level %d.', $transactionLevel),
            self::CODE_ALREADY_RUNNING,
            $context + ['transaction_level' => $transactionLevel],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
