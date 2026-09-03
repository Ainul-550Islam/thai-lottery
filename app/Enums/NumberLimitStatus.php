<?php

declare(strict_types=1);

namespace App\Enums;

use App\Exceptions\RiskConfigurationException;

/**
 * Lifecycle of a per-draw number limit, as the EXISTING schema supports it.
 *
 * SCHEMA INSPECTION RESULT — READ THIS BEFORE CHANGING ANY CASE
 *
 * The `number_limits.status` column is a varchar(32) with default 'active', and
 * App\Models\NumberLimit already casts that column to App\Enums\LimitStatus,
 * which defines exactly four values:
 *
 *     active, exceeded, suspended, removed
 *
 * config/risk.php builds 'exposure.limit_statuses' from
 * array_column(LimitStatus::cases(), 'value') and sets 'default_limit_status'
 * to LimitStatus::Active->value, so LimitStatus is already the authoritative
 * vocabulary for this column in both the model and the configuration.
 *
 * Therefore this enum does NOT invent a new lifecycle and does NOT introduce
 * values such as 'inactive' or 'blocked' that the column has never held. It
 * mirrors LimitStatus one-to-one and exists only as the risk domain's typed
 * READ view over that column, adding risk-specific predicates
 * (allowsBetting(), isBlocking(), isExceeded()) that the risk engine needs.
 *
 * IMPORTANT: this enum is deliberately NOT installed as an Eloquent cast.
 * Changing App\Models\NumberLimit's cast from LimitStatus to this enum would
 * create two competing sources of truth for one column and would break every
 * existing caller that compares against LimitStatus. The bridge methods
 * fromLimitStatus() and toLimitStatus() convert in both directions, losslessly,
 * because the value sets are identical.
 *
 * The general concepts the risk engine cares about map onto the real values as:
 *   "sellable"        -> Active
 *   "ceiling reached" -> Exceeded
 *   "manually held"   -> Suspended
 *   "retired"         -> Removed
 * There is no separate database representation for "blocked"; a hard block is
 * expressed as Suspended (operator action) or Exceeded (capacity consumed).
 */
enum NumberLimitStatus: string
{
    /** Selling is permitted on this number for this draw. */
    case Active = 'active';

    /** The configured ceiling has been consumed; selling must stop. */
    case Exceeded = 'exceeded';

    /** An operator has held this number; selling must stop, capacity intact. */
    case Suspended = 'suspended';

    /** The limit row is retired and must not be used for new decisions. */
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Exceeded => 'Exceeded',
            self::Suspended => 'Suspended',
            self::Removed => 'Removed',
        };
    }

    /**
     * Only an active limit may accept new liability.
     *
     * This mirrors LimitStatus::allowsBetting() exactly, on purpose: the two
     * enums must never disagree about whether a status sells.
     */
    public function allowsBetting(): bool
    {
        return $this === self::Active;
    }

    /**
     * Inverse of allowsBetting(), stated positively for rejection paths.
     */
    public function isBlocking(): bool
    {
        return ! $this->allowsBetting();
    }

    /**
     * The ceiling was reached, as opposed to an operator hold or retirement.
     */
    public function isExceeded(): bool
    {
        return $this === self::Exceeded;
    }

    /**
     * An operator hold, which is reversible without changing any amount.
     */
    public function isSuspended(): bool
    {
        return $this === self::Suspended;
    }

    /**
     * Retired rows are ignored by the engine entirely.
     */
    public function isRemoved(): bool
    {
        return $this === self::Removed;
    }

    /**
     * Machine-readable rejection reason for a status that will not sell.
     *
     * Returns null for Active. The codes are the stable ones used by
     * App\Services\Risk\RiskDecisionService so API clients can branch on them.
     */
    public function rejectionReasonCode(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::Exceeded => 'NUMBER_LIMIT_EXCEEDED',
            self::Suspended => 'NUMBER_BLOCKED',
            self::Removed => 'INVALID_LIMIT',
        };
    }

    /**
     * Convert from the enum the database column is actually cast to.
     *
     * Lossless: LimitStatus and NumberLimitStatus declare identical value sets,
     * which is asserted by assertVocabularyMatchesDatabaseEnum().
     */
    public static function fromLimitStatus(LimitStatus $status): self
    {
        return match ($status) {
            LimitStatus::Active => self::Active,
            LimitStatus::Exceeded => self::Exceeded,
            LimitStatus::Suspended => self::Suspended,
            LimitStatus::Removed => self::Removed,
        };
    }

    /**
     * Convert back to the enum the database column is cast to.
     *
     * Anything written to number_limits.status must go through this method so a
     * risk-domain value can never reach the column in a shape LimitStatus does
     * not accept.
     */
    public function toLimitStatus(): LimitStatus
    {
        return match ($this) {
            self::Active => LimitStatus::Active,
            self::Exceeded => LimitStatus::Exceeded,
            self::Suspended => LimitStatus::Suspended,
            self::Removed => LimitStatus::Removed,
        };
    }

    /**
     * Accept whatever App\Models\NumberLimit hands back for `status`.
     *
     * The model casts the column, so reading `$limit->status` yields a
     * LimitStatus instance rather than a string. A raw query builder read of the
     * same column yields a string. Both shapes are normalised here, and an
     * unknown string is reported instead of being coerced to Active, because
     * defaulting an unrecognised status to "sellable" would be the single most
     * dangerous guess this file could make.
     *
     * @throws RiskConfigurationException
     */
    public static function coerce(LimitStatus|self|string|null $status): self
    {
        if ($status instanceof self) {
            return $status;
        }

        if ($status instanceof LimitStatus) {
            return self::fromLimitStatus($status);
        }

        if ($status === null || trim($status) === '') {
            throw RiskConfigurationException::withCode(
                'number_limit_status_missing',
                'A number limit was read without a status; refusing to assume it is sellable.',
            );
        }

        $resolved = self::tryFrom(trim($status));

        if ($resolved === null) {
            throw RiskConfigurationException::withCode(
                'number_limit_status_unknown',
                sprintf('Unrecognised number limit status "%s".', trim($status)),
                ['status' => trim($status)],
            );
        }

        return $resolved;
    }

    /**
     * The statuses the engine considers sellable.
     *
     * @return list<self>
     */
    public static function sellable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $case): bool => $case->allowsBetting(),
        ));
    }

    /**
     * Guard that this enum has not drifted from the enum the column is cast to.
     *
     * Called by App\Services\Risk\NumberLimitResolver during resolution so drift
     * is caught by the risk engine at runtime instead of by a wrong decision in
     * production. Kept as an explicit assertion rather than a test, because
     * Phase 3.1 is not permitted to add permanent test files.
     *
     * @throws RiskConfigurationException
     */
    public static function assertVocabularyMatchesDatabaseEnum(): void
    {
        $risk = array_column(self::cases(), 'value');
        $database = array_column(LimitStatus::cases(), 'value');

        sort($risk);
        sort($database);

        if ($risk !== $database) {
            throw RiskConfigurationException::withCode(
                'number_limit_status_vocabulary_drift',
                'NumberLimitStatus no longer mirrors LimitStatus, which is the enum the '
                .'number_limits.status column is cast to. Reconcile them before evaluating risk.',
                [
                    'risk_domain' => implode(',', $risk),
                    'database_cast' => implode(',', $database),
                ],
            );
        }
    }
}
