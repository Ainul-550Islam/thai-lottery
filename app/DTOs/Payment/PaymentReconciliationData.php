<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\PaymentTransactionStatus;
use App\Exceptions\PaymentReconciliationException;

/**
 * The identity of ONE provider-vs-internal comparison: which payment,
 * which external reference, and both sides' amounts/statuses as read
 * under locks. The fingerprint pins the exact fact-set compared, so a
 * same-day rerun with unchanged facts REPLIES with the recorded row
 * and new facts rotate the conversation forward.
 */
final readonly class PaymentReconciliationData
{
    public function __construct(
        public int $paymentId,
        public string $providerCode,
        public string $externalReference,
        public string $expectedAmount,
        public ?string $observedAmount,
        public string $currency,
        public PaymentTransactionStatus $internalStatus,
        public ?PaymentTransactionStatus $observedStatus,
        public string $fingerprint,
    ) {
    }

    public static function fingerprintOf(
        int $paymentId,
        string $providerCode,
        string $externalReference,
        string $expectedAmount,
        ?string $observedAmount,
        string $currency,
        string $internalStatus,
        ?string $observedStatus,
    ): string {
        return hash('sha256', sprintf(
            'pay-recon:%d:%s:%s:%s:%s:%s:%s:%s',
            $paymentId,
            $providerCode,
            $externalReference,
            $expectedAmount,
            $observedAmount ?? '(unobserved)',
            $currency,
            $internalStatus,
            $observedStatus ?? '(unobserved)',
        ));
    }

    /**
     * One live conversation per payment.
     */
    public static function baseKeyOf(int $paymentId): string
    {
        return hash('sha256', sprintf('pay-recon-key:%d', $paymentId));
    }

    /**
     * @throws PaymentReconciliationException
     */
    public static function fromInput(
        int $paymentId,
        string $providerCode,
        string $externalReference,
        string $expectedAmount,
        ?string $observedAmount,
        string $currency,
        string|PaymentTransactionStatus $internalStatus,
        string|PaymentTransactionStatus|null $observedStatus,
    ): PaymentReconciliationData {
        $provider = strtolower(trim($providerCode));
        $ref = trim($externalReference);
        $expected = trim($expectedAmount);
        $observed = $observedAmount === null ? null : trim($observedAmount);
        $cur = strtoupper(trim($currency));

        $internal = $internalStatus instanceof PaymentTransactionStatus
            ? $internalStatus
            : PaymentTransactionStatus::tryFrom(strtolower(trim($internalStatus)));

        $observedEnum = $observedStatus instanceof PaymentTransactionStatus || $observedStatus === null
            ? $observedStatus
            : PaymentTransactionStatus::tryFrom(strtolower(trim((string) $observedStatus)));

        if ($paymentId < 1) {
            throw PaymentReconciliationException::malformed('the payment handle must be a positive integer');
        }

        if ($provider === '' || mb_strlen($provider) > 32) {
            throw PaymentReconciliationException::malformed('a provider code is 1-32 characters');
        }

        if ($ref === '' || mb_strlen($ref) > 191) {
            throw PaymentReconciliationException::malformed('an external reference is 1-191 characters');
        }

        if (! preg_match('/^\-?\d+(\.\d{1,2})?$/', $expected)) {
            throw PaymentReconciliationException::malformed('the expected amount must be a decimal string');
        }

        if ($observed !== null && ! preg_match('/^\-?\d+(\.\d{1,2})?$/', $observed)) {
            throw PaymentReconciliationException::malformed('the observed amount must be a decimal string');
        }

        if (! preg_match('/^[A-Z]{3}$/', $cur)) {
            throw PaymentReconciliationException::malformed('the currency must be a 3-letter code');
        }

        if (! $internal instanceof PaymentTransactionStatus) {
            throw PaymentReconciliationException::malformed('the internal status word could not be normalized');
        }

        if ($observedStatus !== null && ! $observedEnum instanceof PaymentTransactionStatus) {
            throw PaymentReconciliationException::malformed('the observed status word could not be normalized');
        }

        $expected = bcadd($expected, '0', 2);
        $observed = $observed === null ? null : bcadd($observed, '0', 2);

        return new self(
            paymentId: $paymentId,
            providerCode: $provider,
            externalReference: $ref,
            expectedAmount: $expected,
            observedAmount: $observed,
            currency: $cur,
            internalStatus: $internal,
            observedStatus: $observedEnum,
            fingerprint: self::fingerprintOf(
                $paymentId,
                $provider,
                $ref,
                $expected,
                $observed,
                $cur,
                $internal->value,
                $observedEnum?->value,
            ),
        );
    }
}
