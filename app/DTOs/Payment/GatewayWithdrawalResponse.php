<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

/**
 * Immutable DTO representing the outcome of an initiated gateway payout/withdrawal.
 */
final class GatewayWithdrawalResponse
{
    /**
     * @param  array<string, mixed>  $rawResponse
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $successful,
        public readonly bool $isPending = false,
        public readonly ?string $providerReference = null,
        public readonly ?string $errorMessage = null,
        public readonly array $rawResponse = [],
        public readonly array $metadata = [],
    ) {
    }

    public static function completed(
        string $providerReference,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            isPending: false,
            providerReference: $providerReference,
            rawResponse: $rawResponse,
            metadata: $metadata,
        );
    }

    public static function pending(
        string $providerReference,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            isPending: true,
            providerReference: $providerReference,
            rawResponse: $rawResponse,
            metadata: $metadata,
        );
    }

    public static function failed(
        string $errorMessage,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: false,
            errorMessage: $errorMessage,
            rawResponse: $rawResponse,
            metadata: $metadata,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'is_pending' => $this->isPending,
            'provider_reference' => $this->providerReference,
            'error_message' => $this->errorMessage,
            'metadata' => $this->metadata,
        ];
    }
}
