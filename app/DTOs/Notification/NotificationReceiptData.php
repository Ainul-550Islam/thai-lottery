<?php

declare(strict_types=1);

namespace App\DTOs\Notification;

use App\Enums\NotificationFailureReason;
use App\Exceptions\NotificationException;

/**
 * Provider receipt/status callback data, normalized into desk
 * delivery states BEFORE they touch the ledger: 'delivered',
 * 'failed' or nothing interesting (pending echo = ignore).
 */
final class NotificationReceiptData
{
    public readonly NotificationFailureReason $failureReason;

    public readonly \DateTimeInterface $reportedAt;

    public function __construct(
        public readonly string $providerReference,
        public readonly string $deliveryState,
        ?NotificationFailureReason $failureReason = null,
        ?\DateTimeInterface $reportedAt = null,
    ) {
        $this->failureReason = $failureReason ?? NotificationFailureReason::ProviderFailure;
        $this->reportedAt = $reportedAt ?? now();
    }

    /**
     * @param array{provider_reference:string, delivery_state:string, failure_reason?:string|null, reported_at?:\DateTimeInterface|null} $data
     */
    public static function fromProvider(array $data): self
    {
        $reference = trim((string) ($data['provider_reference'] ?? ''));
        $state = strtolower(trim((string) ($data['delivery_state'] ?? '')));

        if ($reference === '') {
            throw NotificationException::malformed('A provider reference is required');
        }

        if (! in_array($state, ['delivered', 'failed', 'read', 'pending'], true)) {
            throw NotificationException::malformed(sprintf('Unknown provider delivery state [%s]', $state));
        }

        $reason = null;

        if ($state === 'failed') {
            $reason = NotificationFailureReason::tryFrom((string) ($data['failure_reason'] ?? ''))
                ?? NotificationFailureReason::ProviderFailure;
        }

        return new self(
            providerReference: substr($reference, 0, 128),
            deliveryState: $state,
            failureReason: $reason,
            reportedAt: $data['reported_at'] ?? null,
        );
    }

    /**
     * Same callback = one receipt, forever.
     */
    public function receiptFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-notif-receipt', $this->providerReference, $this->deliveryState,
            $this->failureReason->value, $this->reportedAt->format(DATE_ATOM),
        ]));
    }
}
