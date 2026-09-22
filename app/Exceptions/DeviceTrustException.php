<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * DeviceTrustException — device verification/trust/revocation refusals.
 */
final class DeviceTrustException extends RuntimeException
{
    public const MALFORMED = 'DT_MALFORMED';

    public const EVIDENCE_MISMATCH = 'DT_EVIDENCE_MISMATCH';

    public const ALREADY_TRUSTED = 'DT_ALREADY_TRUSTED';

    public const PRESENTATION_MISMATCH = 'DT_PRESENTATION_MISMATCH';

    public const REVOCATION_CONFLICT = 'DT_REVOCATION_CONFLICT';

    public const NOT_FOUND = 'DT_NOT_FOUND';

    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Device trust refused: '.$message);
    }

    public function errorCode(): string
    {
        return $this->deskCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function malformed(string $reason): self
    {
        return new self(self::MALFORMED, $reason, ['reason' => $reason]);
    }

    public static function evidenceMismatch(string $deviceFingerprint): self
    {
        return new self(self::EVIDENCE_MISMATCH, sprintf(
            'verification evidence does not bind to device [%s]', substr($deviceFingerprint, 0, 12),
        ), ['device_fingerprint' => $deviceFingerprint]);
    }

    public static function alreadyTrusted(string $deviceFingerprint): self
    {
        return new self(self::ALREADY_TRUSTED, sprintf(
            'device [%s] is already trusted — re-trusting is refused', substr($deviceFingerprint, 0, 12),
        ), ['device_fingerprint' => $deviceFingerprint]);
    }

    public static function presentationMismatch(string $deviceFingerprint): self
    {
        return new self(self::PRESENTATION_MISMATCH, sprintf(
            'the presented device does not match the registered identity [%s]', substr($deviceFingerprint, 0, 12),
        ), ['device_fingerprint' => $deviceFingerprint]);
    }

    public static function revocationConflict(string $deviceFingerprint, string $status): self
    {
        return new self(self::REVOCATION_CONFLICT, sprintf(
            'device [%s] in status [%s] cannot be revoked from here', substr($deviceFingerprint, 0, 12), $status,
        ), ['device_fingerprint' => $deviceFingerprint, 'status' => $status]);
    }

    public static function notFound(string $deviceFingerprint): self
    {
        return new self(self::NOT_FOUND, sprintf('device [%s] is unknown', substr($deviceFingerprint, 0, 12)), ['device_fingerprint' => $deviceFingerprint]);
    }
}
