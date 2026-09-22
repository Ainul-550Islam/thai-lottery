<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\PaymentFailureReason;
use App\Enums\PaymentTransactionStatus;
use App\Exceptions\PaymentWebhookException;

/**
 * A provider success/failure callback normalized into trusted internal
 * fields.
 *
 * TRUST DISCIPLINE: the client body is NEVER trusted for money facts.
 * This object is built ONLY from provider envelopes whose signature
 * verification has already happened (PaymentWebhookVerificationService)
 * plus the raw-signature evidence pair (signature string + payload
 * fingerprint), so downstream lanes read normalized facts WITHOUT
 * re-parsing dialect.
 */
final readonly class PaymentCallbackData
{
    public function __construct(
        public string $providerCode,
        public string $externalReference,
        public string $amount,
        public string $currency,
        public PaymentTransactionStatus $providerStatus,
        public ?PaymentFailureReason $failureReason,
        public ?string $signature,
        public string $payloadFingerprint,
        public ?string $providerEventId,
    ) {
    }

    /**
     * @throws PaymentWebhookException
     */
    public static function fromInput(
        string $providerCode,
        string $externalReference,
        string $amount,
        string $currency,
        string|PaymentTransactionStatus $providerStatus,
        ?string $failureReason,
        ?string $signature,
        string $payloadFingerprint,
        ?string $providerEventId = null,
    ): PaymentCallbackData {
        $provider = strtolower(trim($providerCode));
        $ref = trim($externalReference);
        $amt = trim($amount);
        $cur = strtoupper(trim($currency));
        $fp = strtolower(trim($payloadFingerprint));

        $status = $providerStatus instanceof PaymentTransactionStatus
            ? $providerStatus
            : PaymentTransactionStatus::fromProviderWord((string) $providerStatus);

        if (! preg_match('/^[a-z0-9][a-z0-9\-_]{1,30}[a-z0-9]$/', $provider) && ! preg_match('/^[a-z0-9]{2,32}$/', $provider)) {
            throw PaymentWebhookException::malformed('a provider code is 2-32 lowercase alphanumerics');
        }

        if ($ref === '' || mb_strlen($ref) > 191) {
            throw PaymentWebhookException::malformed('an external reference is 1-191 characters');
        }

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amt)) {
            throw PaymentWebhookException::malformed('the callback amount must be a decimal string (money, never float)');
        }

        if (! preg_match('/^[A-Z]{3}$/', $cur)) {
            throw PaymentWebhookException::malformed('the callback currency must be a 3-letter code');
        }

        if (! $status instanceof PaymentTransactionStatus) {
            throw PaymentWebhookException::malformed('the callback status word could not be normalized — refusing to guess');
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw PaymentWebhookException::malformed('the payload fingerprint must be exactly 64 lowercase hex characters');
        }

        $reason = $status === PaymentTransactionStatus::Failed
            ? PaymentFailureReason::fromProviderWord($failureReason)
            : ($failureReason === null ? null : PaymentFailureReason::fromProviderWord($failureReason));

        return new self(
            providerCode: $provider,
            externalReference: $ref,
            amount: bcadd($amt, '0', 2),
            currency: $cur,
            providerStatus: $status,
            failureReason: $reason,
            signature: $signature === null ? null : trim($signature),
            payloadFingerprint: $fp,
            providerEventId: $providerEventId === null ? null : trim($providerEventId),
        );
    }

    /**
     * The stable fact-key of this normalized callback (provider + which
     * transaction + what it says). Used by callers that need write-once
     * state evidence anchors.
     */
    public function callbackKey(): string
    {
        return hash('sha256', sprintf(
            'pay-callback:%s:%s:%s:%s:%s',
            $this->providerCode,
            $this->externalReference,
            $this->amount,
            $this->currency,
            $this->providerStatus->value,
        ));
    }
}
