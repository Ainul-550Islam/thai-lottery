<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Supported identity-document taxonomy.
 *
 * ADDITIVE HISTORY (batch-13): the four original document kinds are
 * preserved bit-for-bit (existing rows, casts and Filament resources
 * depend on them); Selfie and Other extend the taxonomy. `AddressProof`
 * from the batch-13 contract is served by the pre-existing
 * ProofOfAddress ('proof_of_address') case — the same fact, spelled the
 * way the estate already speaks it.
 */
enum KycDocumentType: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
    case DrivingLicense = 'driving_license';
    case ProofOfAddress = 'proof_of_address';
    case Selfie = 'selfie';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID Card',
            self::Passport => 'Passport',
            self::DrivingLicense => "Driver's License",
            self::ProofOfAddress => 'Proof of Address (Utility Bill)',
            self::Selfie => 'Selfie / Liveness Check',
            self::Other => 'Other Supporting Document',
        };
    }

    /**
     * Whether this kind counts as a PRIMARY identity document for the
     * server-authoritative verification decision.
     */
    public function isPrimaryIdentityDocument(): bool
    {
        return in_array($this, [self::NationalId, self::Passport, self::DrivingLicense], true);
    }

    /**
     * Whether this kind is a LIVENESS/likeness artifact.
     */
    public function isLivenessArtifact(): bool
    {
        return $this === self::Selfie;
    }
}
