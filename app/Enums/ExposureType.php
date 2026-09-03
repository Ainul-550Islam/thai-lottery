<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which quantity an exposure figure measures.
 *
 * SCHEMA INSPECTION RESULT
 * `number_limits` tracks TWO independent ceilings, which is why this enum has to
 * exist at all:
 *
 *   stake liability   current_amount            against max_amount
 *                     (DECIMAL(20,2), default 0)  (DECIMAL(20,2), NOT NULL, CHECK > 0)
 *
 *   payout liability  current_payout_exposure   against maximum_payout_exposure
 *                     (DECIMAL(20,2), default 0)  (DECIMAL(20,2), NULLABLE)
 *
 * The migration's own documentation states these are independent ceilings and
 * that the schema contains no concurrency logic: the unique key
 * (draw_id, bet_type, number) plus row locking is what the risk service must
 * build on, re-checking each ceiling inside its own transaction.
 *
 * The AUTHORITATIVE payout-exposure column is `current_payout_exposure`. No
 * other exposure column exists on this table and none is invented here.
 *
 * CombinedExposure is a derived reporting view (stake + potential payout). It has
 * NO column of its own and must never be written to the database; it exists so a
 * dashboard can state total liability without a second definition of the term.
 */
enum ExposureType: string
{
    /** Money staked on the number. Backed by current_amount / max_amount. */
    case Stake = 'stake';

    /**
     * Money the house could owe if the number wins.
     * Backed by current_payout_exposure / maximum_payout_exposure.
     */
    case PotentialPayout = 'potential_payout';

    /**
     * Stake plus potential payout. DERIVED ONLY — no database column.
     */
    case CombinedExposure = 'combined_exposure';

    public function label(): string
    {
        return match ($this) {
            self::Stake => 'Stake Exposure',
            self::PotentialPayout => 'Potential Payout Exposure',
            self::CombinedExposure => 'Combined Exposure',
        };
    }

    /**
     * Column on `number_limits` holding the accumulated amount, or null when the
     * type is derived and has no column.
     */
    public function currentColumn(): ?string
    {
        return match ($this) {
            self::Stake => 'current_amount',
            self::PotentialPayout => 'current_payout_exposure',
            self::CombinedExposure => null,
        };
    }

    /**
     * Column on `number_limits` holding the ceiling, or null when derived.
     */
    public function ceilingColumn(): ?string
    {
        return match ($this) {
            self::Stake => 'max_amount',
            self::PotentialPayout => 'maximum_payout_exposure',
            self::CombinedExposure => null,
        };
    }

    /**
     * Whether this type is backed by real columns and can therefore be reserved.
     *
     * CombinedExposure is false: reserving it would mean writing a number no
     * column owns, which is exactly the kind of fabricated mechanism this phase
     * must not build.
     */
    public function isPersisted(): bool
    {
        return $this->currentColumn() !== null;
    }

    /**
     * Whether the ceiling column may legitimately be NULL in the schema.
     *
     * `max_amount` is NOT NULL with a CHECK (max_amount > 0), so a stake ceiling
     * always exists on any row. `maximum_payout_exposure` is nullable, so a row
     * can exist with no payout ceiling configured. A NULL payout ceiling means
     * MISSING CONFIGURATION and is never read as unlimited.
     */
    public function ceilingMayBeNull(): bool
    {
        return $this === self::PotentialPayout;
    }

    /**
     * Configuration key providing the fallback ceiling when the row's own
     * ceiling column is NULL, or null when there is no fallback.
     *
     * config/risk.php declares 'exposure.max_per_number' as the maximum total
     * potential payout accepted on a single number for a single draw, which is
     * precisely the payout ceiling. There is no configured fallback for the stake
     * ceiling because the column cannot be NULL.
     */
    public function fallbackCeilingConfigKey(): ?string
    {
        return match ($this) {
            self::PotentialPayout => 'risk.exposure.max_per_number',
            self::Stake, self::CombinedExposure => null,
        };
    }

    /**
     * The types the engine actually enforces ceilings on, in a deterministic
     * order so two evaluations of the same request always check in the same
     * sequence.
     *
     * @return list<self>
     */
    public static function enforced(): array
    {
        return [self::Stake, self::PotentialPayout];
    }
}
