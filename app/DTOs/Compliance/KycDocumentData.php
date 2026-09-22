<?php

declare(strict_types=1);

namespace App\DTOs\Compliance;

use App\Enums\KycDocumentType;
use App\Exceptions\KycVerificationException;

/**
 * Document identity: WHO holds it, WHAT kind, WHERE issued, the
 * fingerprint of the exact BYTES, its own expiry and the verification
 * reference any verdict on it produced.
 *
 * IDENTITY: (user, fingerprint) — the same bytes re-uploaded by the
 * same user are the SAME document (replay); different bytes under an
 * old handle are a new document with its own fingerprint.
 */
final readonly class KycDocumentData
{
    public function __construct(
        public int $userId,
        public KycDocumentType $documentType,
        public string $issuerCountry,
        public string $documentFingerprint,
        public ?string $expiryIso,
        public ?string $verificationReference,
    ) {}

    /**
     * Fingerprint of the document bytes.
     */
    public static function fingerprintOf(string $bytesHashMaterial): string
    {
        return hash('sha256', $bytesHashMaterial);
    }

    /**
     * @throws KycVerificationException
     */
    public static function fromInput(
        int $userId,
        string|KycDocumentType $documentType,
        string $issuerCountry,
        string $documentFingerprint,
        ?string $expiryIso = null,
        ?string $verificationReference = null,
    ): KycDocumentData {
        $type = $documentType instanceof KycDocumentType
            ? $documentType
            : KycDocumentType::tryFrom(strtolower(trim($documentType)));

        $country = strtoupper(trim($issuerCountry));
        $fp = strtolower(trim($documentFingerprint));
        $ref = $verificationReference === null ? null : strtoupper(trim($verificationReference));

        if ($userId < 1) {
            throw KycVerificationException::malformed('the document must name a real user');
        }

        if (! $type instanceof KycDocumentType) {
            throw KycVerificationException::malformed('unsupported identity-document kind');
        }

        if (! preg_match('/^[A-Z]{2}$/', $country)) {
            throw KycVerificationException::malformed('the issuer country must be a 2-letter code');
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw KycVerificationException::malformed('the document fingerprint must be exactly 64 lowercase hex characters');
        }

        if ($ref !== null && (strlen($ref) < 8 || strlen($ref) > 96 || ! preg_match('/^[A-Z0-9:\-._]+$/', $ref))) {
            throw KycVerificationException::malformed('the verification reference must be a canonical 8-96 character token');
        }

        return new self(
            userId: $userId,
            documentType: $type,
            issuerCountry: $country,
            documentFingerprint: $fp,
            expiryIso: $expiryIso === null ? null : trim($expiryIso),
            verificationReference: $ref,
        );
    }
}
