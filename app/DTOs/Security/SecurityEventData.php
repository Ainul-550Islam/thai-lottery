<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use App\Enums\SecurityEventType;
use App\Enums\SecurityRiskLevel;
use App\Exceptions\AuthenticationSecurityException;

/**
 * Immutable security-event payload with the deterministic audit
 * fingerprint. The payload may carry only SAFE context: fingerprints,
 * statuses, hashes, counts — never secrets, codes or cleartext
 * identifiers (enforced by the caller-facing services; the ledger
 * itself never learns cleartext).
 */
final class SecurityEventData
{
    public readonly SecurityEventType $eventType;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        SecurityEventType|string $eventType,
        public readonly ?int $userId,
        public readonly SecurityRiskLevel $riskLevel,
        public readonly ?string $ipAddress,
        public readonly ?string $deviceFingerprint,
        public readonly ?string $sessionFingerprint,
        public readonly array $payload,
        public readonly \DateTimeInterface $occurredAt,
    ) {
        $this->eventType = is_string($eventType) ? SecurityEventType::from($eventType) : $eventType;
    }

    /**
     * @param  array{event_type:SecurityEventType|string, user_id?:int|null, risk_level?:SecurityRiskLevel|null, ip_address?:string|null, device_fingerprint?:string|null, session_fingerprint?:string|null, payload?:array<string, mixed>, occurred_at?:\DateTimeInterface|null}  $data
     */
    public static function fromInput(array $data): self
    {
        $type = $data['event_type'] ?? null;

        if (($type === null) || (is_string($type) && SecurityEventType::tryFrom($type) === null) || (! $type instanceof SecurityEventType && ! is_string($type))) {
            throw AuthenticationSecurityException::malformed('A valid security event type is required');
        }

        return new self(
            eventType: $type,
            userId: isset($data['user_id']) ? (int) $data['user_id'] : null,
            riskLevel: $data['risk_level'] ?? SecurityRiskLevel::Low,
            ipAddress: isset($data['ip_address']) ? substr((string) $data['ip_address'], 0, 45) : null,
            deviceFingerprint: isset($data['device_fingerprint']) ? substr((string) $data['device_fingerprint'], 0, 64) : null,
            sessionFingerprint: isset($data['session_fingerprint']) ? substr((string) $data['session_fingerprint'], 0, 64) : null,
            payload: (array) ($data['payload'] ?? []),
            occurredAt: $data['occurred_at'] ?? now(),
        );
    }

    /**
     * Same occurrence = one fingerprint, exactly-once persistence.
     */
    public function eventFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-sec-evt', $this->eventType->value, (string) ($this->userId ?? 0),
            (string) $this->deviceFingerprint, (string) $this->sessionFingerprint,
            $this->occurredAt->format(DATE_ATOM), json_encode($this->payload),
        ]));
    }
}
