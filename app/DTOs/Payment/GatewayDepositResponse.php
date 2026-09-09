<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

/**
 * Immutable DTO representing the outcome of an initiated gateway deposit.
 */
final class GatewayDepositResponse
{
    /**
     * @param  array<string, mixed>  $rawResponse
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $successful,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $clientSecret = null,
        public readonly ?string $errorMessage = null,
        public readonly array $rawResponse = [],
        public readonly array $metadata = [],
    ) {
    }

    public static function redirect(
        string $url,
        string $providerReference,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            redirectUrl: $url,
            providerReference: $providerReference,
            rawResponse: $rawResponse,
            metadata: $metadata,
        );
    }

    public static function secret(
        string $clientSecret,
        string $providerReference,
        array $rawResponse = [],
        array $metadata = [],
    ): self {
        return new self(
            successful: true,
            providerReference: $providerReference,
            clientSecret: $clientSecret,
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

    public static function manual(
        string $providerReference,
        array $instructions = [],
    ): self {
        return new self(
            successful: true,
            providerReference: $providerReference,
            metadata: array_merge(['instructions' => $instructions], ['manual_flow' => true]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'successful' => $this->successful,
            'redirect_url' => $this->redirectUrl,
            'provider_reference' => $this->providerReference,
            'client_secret' => $this->clientSecret,
            'error_message' => $this->errorMessage,
            'metadata' => $this->metadata,
        ];
    }
}
