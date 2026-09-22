<?php

declare(strict_types=1);

namespace App\DTOs\Compliance;

use App\Enums\KycVerificationStatus;
use App\Exceptions\KycVerificationException;

/**
 * Immutable KYC decision evidence: whose verification, which
 * verification reference, the document-set fingerprint the verdict
 * was derived from, the reviewer/source and the pronouncing moment.
 *
 * The DTO carries NO verdict input: the status here is always the
 * DERIVED one computed by KycVerificationService (never the caller's
 * claim — the service constructs this DTO itself).
 */
final readonly class KycVerificationData
{
    public function __construct(
        public int $userId,
        public string $verificationReference,
        public string $documentSetFingerprint,
        public KycVerificationStatus $status,
        public ?int $reviewerId,
        public string $source,
        public string $decidedAtIso,
    ) {}

    /**
     * Deterministic document-set fingerprint: sha-256 over the sorted
     * verified-document fingerprints — the exact BYTES the verdict
     * read. Same set in, same fingerprint out.
     *
     * @param  array<int, string>  $documentFingerprints
     */
    public static function documentSetFingerprint(array $documentFingerprints): string
    {
        $fps = array_map(static fn ($f): string => strtolower(trim((string) $f)), $documentFingerprints);
        sort($fps);

        return hash('sha256', sprintf('kyc-docset:%s', implode('|', $fps)));
    }

    /**
     * The deterministic verification reference: which decision this is
     * (user + the evidence set + the moment of pronouncement).
     */
    public static function referenceFor(int $userId, string $documentSetFingerprint, ?string $momentIso = null): string
    {
        return 'KYCV-'.strtoupper(substr(hash('sha256', sprintf('kyc-ver:%d:%s:%s', $userId, $documentSetFingerprint, $momentIso ?? '')), 0, 20));
    }

    /**
     * @throws KycVerificationException
     */
    public static function fromInput(
        int $userId,
        string $verificationReference,
        string $documentSetFingerprint,
        string|KycVerificationStatus $status,
        ?int $reviewerId,
        string $source,
        string $decidedAtIso,
    ): KycVerificationData {
        $ref = strtoupper(trim($verificationReference));
        $fp = strtolower(trim($documentSetFingerprint));
        $src = strtolower(trim($source));

        $status = $status instanceof KycVerificationStatus
            ? $status
            : KycVerificationStatus::tryFrom(strtolower(trim($status)));

        if ($userId < 1) {
            throw KycVerificationException::malformed('the verification must name a real user');
        }

        if (strlen($ref) < 8 || strlen($ref) > 96 || ! preg_match('/^[A-Z0-9:\-._]+$/', $ref)) {
            throw KycVerificationException::malformed('the verification reference must be a canonical 8-96 character token');
        }

        if (! preg_match('/^[0-9a-f]{64}$/', $fp)) {
            throw KycVerificationException::malformed('the document-set fingerprint must be exactly 64 lowercase hex characters');
        }

        if (! $status instanceof KycVerificationStatus) {
            throw KycVerificationException::malformed('unknown KYC verification lifecycle word');
        }

        if ($reviewerId !== null && $reviewerId < 1) {
            throw KycVerificationException::malformed('a reviewer handle must be a positive integer');
        }

        if (! preg_match('/^[a-z0-9_]{2,32}$/', $src)) {
            throw KycVerificationException::malformed('the verification source is a lowercase 2-32 character slug');
        }

        return new self(
            userId: $userId,
            verificationReference: $ref,
            documentSetFingerprint: $fp,
            status: $status,
            reviewerId: $reviewerId,
            source: $src,
            decidedAtIso: trim($decidedAtIso),
        );
    }
}
