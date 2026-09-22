<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a certified result says it came from — its provenance.
 *
 * The source classification matters operationally because the lanes the
 * certification court trusts differ: OfficialOperator is an upstream
 * attestation, AdminEntry is a person in the console, Import is a bulk
 * recovery tool, Recovery is the same shape after an incident. All four
 * are allowed; none is silently the same lane as another, and the source
 * travels with the certified fingerprint forever.
 */
enum ResultSourceType: string
{
    case OfficialOperator = 'official_operator';
    case AdminEntry = 'admin_entry';
    case Import = 'import';
    case Recovery = 'recovery';

    /**
     * Is this source an ATTESTATION by an external party (as opposed to a
     * human-driven internal write)? Attested lanes must also produce
     * externally-checkable evidence (certifier reference carries the
     * caller's documented identity).
     */
    public function isAttested(): bool
    {
        return $this === self::OfficialOperator || $this === self::Import;
    }

    /**
     * Is this source the internal human lane?
     */
    public function isInternal(): bool
    {
        return $this === self::AdminEntry || $this === self::Recovery;
    }
}
