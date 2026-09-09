<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Models\FinancialTransaction;
use App\Models\Payment;

/**
 * Result of webhook processing or payment finalization.
 */
final class PaymentProcessingResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $replayed,
        public readonly string $gateway,
        public readonly ?Payment $payment,
        public readonly ?FinancialTransaction $transaction,
        public readonly string $actionTaken,
        public readonly ?string $message = null,
        public readonly array $metadata = [],
    ) {
    }

    public static function completed(
        string $gateway,
        ?Payment $payment,
        ?FinancialTransaction $transaction,
        string $actionTaken,
        bool $replayed = false,
        array $metadata = [],
    ): self {
        return new self(
            success: true,
            replayed: $replayed,
            gateway: $gateway,
            payment: $payment,
            transaction: $transaction,
            actionTaken: $actionTaken,
            metadata: $metadata,
        );
    }

    public static function ignored(
        string $gateway,
        string $reason,
        bool $replayed = true,
        ?Payment $payment = null,
    ): self {
        return new self(
            success: true,
            replayed: $replayed,
            gateway: $gateway,
            payment: $payment,
            transaction: null,
            actionTaken: 'ignored',
            message: $reason,
        );
    }

    public static function failed(
        string $gateway,
        string $errorMessage,
        ?Payment $payment = null,
        array $metadata = [],
    ): self {
        return new self(
            success: false,
            replayed: false,
            gateway: $gateway,
            payment: $payment,
            transaction: null,
            actionTaken: 'failed',
            message: $errorMessage,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'replayed' => $this->replayed,
            'gateway' => $this->gateway,
            'action_taken' => $this->actionTaken,
            'payment_id' => $this->payment?->getKey(),
            'transaction_id' => $this->transaction?->getKey(),
            'message' => $this->message,
            'metadata' => $this->metadata,
        ];
    }
}
