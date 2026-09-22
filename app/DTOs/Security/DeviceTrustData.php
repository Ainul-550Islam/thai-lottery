<?php

declare(strict_types=1);

namespace App\DTOs\Security;

use App\Exceptions\DeviceTrustException;

/**
 * Device identity + trust state + verification evidence. The PUBLIC
 * presentation hash never leaves the ledger; verification evidence
 * always arrives already fingerprinted (never raw TOTP codes, push
 * payloads, or scans).
 */
final class DeviceTrustData
{
    public function __construct(
        public readonly int $userId,
        public readonly string $deviceFingerprint,
        public readonly string $presentationHash,
        public readonly string $evidenceFingerprint,
    ) {}

    /**
     * @param  array{user_id:int, device_fingerprint:string, presentation_hash:string, evidence_fingerprint:string}  $data
     */
    public static function fromInput(array $data): self
    {
        $device = strtolower(trim((string) ($data['device_fingerprint'] ?? '')));
        $presentation = strtolower(trim((string) ($data['presentation_hash'] ?? '')));
        $evidence = strtolower(trim((string) ($data['evidence_fingerprint'] ?? '')));

        foreach (['device_fingerprint' => $device, 'presentation_hash' => $presentation, 'evidence_fingerprint' => $evidence] as $field => $value) {
            if (! preg_match('/^[a-f0-9]{64}$/', $value)) {
                throw DeviceTrustException::malformed(sprintf('%s must be 64 hex chars', $field));
            }
        }

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            deviceFingerprint: $device,
            presentationHash: $presentation,
            evidenceFingerprint: $evidence,
        );
    }
}
