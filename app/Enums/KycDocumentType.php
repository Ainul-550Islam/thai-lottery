<?php

declare(strict_types=1);

namespace App\Enums;

enum KycDocumentType: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
    case DrivingLicense = 'driving_license';
    case ProofOfAddress = 'proof_of_address';

    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID Card',
            self::Passport => 'Passport',
            self::DrivingLicense => "Driver's License",
            self::ProofOfAddress => 'Proof of Address (Utility Bill)',
        };
    }
}
