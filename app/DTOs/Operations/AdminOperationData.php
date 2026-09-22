<?php

declare(strict_types=1);

namespace App\DTOs\Operations;

use App\Enums\AdminOperationType;
use App\Exceptions\AdminOperationException;

/**
 * AdminOperationData — the deterministic identity of one admin
 * operation: actor + type + sealed parameters + evidence digest.
 * Same ask = same fingerprint, forever.
 */
final class AdminOperationData
{
    private const MAX_PAYLOAD_BYTES = 8192;

    public function __construct(
        public readonly int $actorUserId,
        public readonly AdminOperationType $type,
        public readonly ?string $targetReference,
        public readonly array $payload,
        public readonly ?string $evidenceFingerprint,
    ) {
    }

    /**
     * @param array{actor_user_id:int, type:string|AdminOperationType, target_reference?:string|null, payload?:array, evidence?:string|null} $data
     */
    public static function fromInput(array $data): self
    {
        $type = $data['type'] ?? null;
        if (! $type instanceof AdminOperationType) {
            $type = is_string($type) ? AdminOperationType::tryFrom(strtolower(trim($type))) : null;
        }
        if (! $type instanceof AdminOperationType) {
            throw AdminOperationException::malformed('A valid operation type is required');
        }

        $payload = $data['payload'] ?? [];
        if (! is_array($payload)) {
            throw AdminOperationException::malformed('Payload must be a sealed array');
        }
        $encoded = json_encode($payload);
        if ($encoded === false || strlen($encoded) > self::MAX_PAYLOAD_BYTES) {
            throw AdminOperationException::malformed('Payload must serialize under '.self::MAX_PAYLOAD_BYTES.' bytes');
        }

        $targetReference = isset($data['target_reference']) ? trim((string) $data['target_reference']) : null;

        if ($type->requiresTargetReference() && ($targetReference === null || $targetReference === '')) {
            throw AdminOperationException::missingTargetReference($type->value);
        }
        if ($targetReference !== null && ! preg_match('/^[A-Za-z0-9_.:\-]{1,96}$/', $targetReference)) {
            throw AdminOperationException::malformed('A target reference must be a tidy identity token');
        }

        $evidence = isset($data['evidence']) ? hash('sha256', (string) $data['evidence']) : null;

        return new self(
            actorUserId: (int) ($data['actor_user_id'] ?? 0),
            type: $type,
            targetReference: $targetReference !== '' ? $targetReference : null,
            payload: $payload,
            evidenceFingerprint: $evidence,
        );
    }

    /**
     * Payload digest — the parameters as sealed.
     */
    public function payloadFingerprint(): string
    {
        return hash('sha256', 'glo-adminop-payload|'.$this->type->value.'|'.json_encode($this->payload));
    }

    /**
     * Operation identity: actor + type + target + payload + evidence.
     */
    public function operationFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-adminop', (string) $this->actorUserId, $this->type->value,
            (string) $this->targetReference, $this->payloadFingerprint(),
            (string) $this->evidenceFingerprint,
        ]));
    }
}
