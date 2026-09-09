<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Enums\Currency;
use App\Enums\WebhookEventType;

/**
 * Normalized inbound webhook payload.
 */
final class WebhookPayload
{
    /**
     * @param  array<string, mixed>  $rawData
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $gateway,
        public readonly string $eventId,
        public readonly WebhookEventType $eventType,
        public readonly ?string $providerReference,
        public readonly ?string $internalReference,
        public readonly ?string $amount,
        public readonly ?Currency $currency,
        public readonly bool $isSuccess,
        public readonly ?string $failureReason = null,
        public readonly array $rawData = [],
        public readonly array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'gateway' => $this->gateway,
            'event_id' => $this->eventId,
            'event_type' => $this->eventType->value,
            'provider_reference' => $this->providerReference,
            'internal_reference' => $this->internalReference,
            'amount' => $this->amount,
            'currency' => $this->currency?->value,
            'is_success' => $this->isSuccess,
            'failure_reason' => $this->failureReason,
            'metadata' => $this->metadata,
        ];
    }
}
