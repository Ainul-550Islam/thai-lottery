<?php

declare(strict_types=1);

namespace App\DTOs\Payment;

use App\Exceptions\PaymentWebhookException;

/**
 * One inbound webhook envelope, normalized.
 *
 * The PAYLOAD rides the object for application, but identity is the
 * FINGERPRINT (sha-256 over canonical bytes): logs and keys never
 * hold raw payloads, and the same bytes arriving twice compute the
 * same fingerprint — which is exactly where exactly-once lives.
 */
final readonly class PaymentWebhookData
{
    public function __construct(
        public string $providerCode,
        public string $eventId,
        public string $eventType,
        public ?string $signature,
        public string $receivedAtIso,
        public string $payloadFingerprint,
        public array $payload,
    ) {
    }

    /**
     * Canonical bytes for signing/fingerprinting: PHP's deterministic
     * JSON of the payload (sorted keys upstream when available; this
     * retains whatever the controller parsed).
     */
    public static function payloadBytes(array $payload): string
    {
        ksort($payload);

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function fingerprintOf(string $providerCode, string $eventId, array $payload): string
    {
        return hash('sha256', sprintf('pay-wh:%s:%s:%s', strtolower(trim($providerCode)), trim($eventId), self::payloadBytes($payload)));
    }

    /**
     * @throws PaymentWebhookException
     */
    public static function fromInput(
        string $providerCode,
        string $eventId,
        string $eventType,
        ?string $signature,
        string $receivedAtIso,
        array $payload,
        ?string $payloadFingerprint = null,
    ): PaymentWebhookData {
        $provider = strtolower(trim($providerCode));
        $event = trim($eventId);
        $type = trim($eventType);

        if (! preg_match('/^[a-z0-9][a-z0-9\-_]{1,30}[a-z0-9]$/', $provider) && ! preg_match('/^[a-z0-9]{2,32}$/', $provider)) {
            throw PaymentWebhookException::malformed('a provider code is 2-32 lowercase alphanumerics');
        }

        if (strlen($event) < 2 || strlen($event) > 96) {
            throw PaymentWebhookException::malformed('the provider event id must be 2-96 characters');
        }

        if ($type === '' || mb_strlen($type) > 96) {
            throw PaymentWebhookException::malformed('the event type is required (1-96 characters)');
        }

        if ($payload === []) {
            throw PaymentWebhookException::malformed('an empty payload carries no fact — refused');
        }

        $fp = $payloadFingerprint ?? self::fingerprintOf($provider, $event, $payload);

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw PaymentWebhookException::malformed('the payload fingerprint must be exactly 64 lowercase hex characters');
        }

        return new self(
            providerCode: $provider,
            eventId: $event,
            eventType: $type,
            signature: $signature === null ? null : trim($signature),
            receivedAtIso: trim($receivedAtIso),
            payloadFingerprint: $fp,
            payload: $payload,
        );
    }

    /**
     * The webhook's paper identity.
     */
    public function webhookKey(): string
    {
        return $this->payloadFingerprint;
    }
}
