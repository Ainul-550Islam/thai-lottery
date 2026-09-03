<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\BetType;
use App\Enums\Currency;
use App\Enums\ExposureType;
use App\Enums\NumberLimitStatus;
use App\Exceptions\RiskConfigurationException;
use App\Models\NumberLimit;
use App\Services\Finance\Money;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Finds the number_limits row that governs a bet, and resolves its ceilings.
 *
 * SCHEMA INSPECTION RESULT — WHAT THIS TABLE CAN AND CANNOT DO
 *
 * number_limits has a UNIQUE key (draw_id, bet_type, number) named
 * number_limits_draw_type_number_unique, so at most one row governs any
 * (draw, bet type, number) triple. That row is the lock target for reservation.
 *
 * `draw_id` is a NOT NULL foreign key to draws with cascadeOnDelete. The table
 * therefore CANNOT hold a global default row, a per-bet-type default row, or a
 * draw-independent template: every row belongs to exactly one draw. There is no
 * schema-supported global/default limit, and supportsDatabaseLevelDefaults()
 * returns false to state that plainly.
 *
 * `max_amount` is NOT NULL with CHECK (max_amount > 0), so a stake ceiling always
 * exists on any row that exists.
 *
 * `maximum_payout_exposure` is NULLABLE. A NULL payout ceiling is MISSING
 * CONFIGURATION, never "unlimited". It is resolved against the explicitly
 * configured fallback config('risk.exposure.max_per_number'), and if that key is
 * absent or unusable the resolution FAILS instead of defaulting to unlimited.
 *
 * There is no `currency` column on number_limits. Every amount on a limit row is
 * implicitly in the default betting currency, config('lottery.betting.currency'),
 * which is THB in the audited configuration. This resolver is the single place
 * that decision is made, so no other risk class has to guess.
 *
 * SOFT DELETES: the model uses SoftDeletes, so the default query already excludes
 * trashed rows. A soft-deleted limit is treated as no limit at all, which is
 * consistent with NumberLimitStatus::Removed meaning "do not use for decisions".
 */
class NumberLimitResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly MoneyExposureCalculator $money,
        private readonly NumberNormalizationService $numbers,
    ) {
    }

    /**
     * The row governing this triple, or null when none exists.
     *
     * $number must already be canonical; pass it through
     * NumberNormalizationService::normalize() first. resolveCanonical() does that
     * for callers holding raw input.
     */
    public function resolve(int $drawId, BetType $betType, string $number): ?NumberLimit
    {
        return NumberLimit::query()
            ->where('draw_id', $drawId)
            ->where('bet_type', $betType->value)
            ->where('number', $number)
            ->first();
    }

    /**
     * Canonicalise the number, then resolve.
     *
     * @throws RiskConfigurationException when the number is invalid
     */
    public function resolveCanonical(int $drawId, BetType $betType, string $rawNumber): ?NumberLimit
    {
        return $this->resolve($drawId, $betType, $this->numbers->normalize($rawNumber, $betType));
    }

    /**
     * The row governing this triple, or a hard failure.
     *
     * This is the method the reservation path uses, because reservation needs a
     * row to lock and a missing row cannot be reserved against a configuration
     * value. It never falls back to "unlimited".
     *
     * @throws RiskConfigurationException with reason MISSING_LIMIT
     */
    public function resolveOrFail(int $drawId, BetType $betType, string $number): NumberLimit
    {
        $limit = $this->resolve($drawId, $betType, $number);

        if (! $limit instanceof NumberLimit) {
            throw RiskConfigurationException::missingLimit($number, $betType->value, $drawId);
        }

        return $limit;
    }

    /**
     * Whether the schema can express a limit that is not tied to a single draw.
     *
     * Always false: number_limits.draw_id is NOT NULL. Kept as a method rather
     * than a comment so calling code and the report read the same answer.
     */
    public function supportsDatabaseLevelDefaults(): bool
    {
        return false;
    }

    /**
     * Whether a missing row can still be assessed against a configured ceiling.
     *
     * True when config('risk.exposure.max_per_number') is present and usable. Note
     * carefully: this permits ASSESSMENT only. Reservation still requires a row,
     * because a configuration value cannot be locked and cannot accumulate.
     */
    public function hasConfiguredFallbackCeiling(): bool
    {
        try {
            return $this->fallbackCeiling(ExposureType::PotentialPayout) instanceof Money;
        } catch (RiskConfigurationException) {
            return false;
        }
    }

    /**
     * The explicitly configured fallback ceiling for an exposure type, or null when
     * that type has no configured fallback.
     *
     * @throws RiskConfigurationException when the key exists but is unusable
     */
    public function fallbackCeiling(ExposureType $type): ?Money
    {
        $key = $type->fallbackCeilingConfigKey();

        if ($key === null) {
            return null;
        }

        $configured = $this->config->get($key);

        if ($configured === null || $configured === '') {
            throw RiskConfigurationException::missingKey($key);
        }

        if (! is_string($configured) && ! is_int($configured)) {
            throw RiskConfigurationException::invalidKey(
                $key,
                'a fallback ceiling must be an exact decimal string or an integer',
            );
        }

        $ceiling = $this->money->money((string) $configured, $this->currency());

        if (! $ceiling->isPositive()) {
            throw RiskConfigurationException::invalidKey(
                $key,
                'a fallback ceiling must be greater than zero',
                (string) $configured,
            );
        }

        return $ceiling;
    }

    /**
     * The ceiling that applies to $limit for $type, and where it came from.
     *
     * @return array{ceiling: Money, source: string}
     *                                                source is 'row' when the
     *                                                limit row supplied it, or
     *                                                'config' when the nullable
     *                                                column was NULL and the
     *                                                configured fallback was used.
     *
     * @throws RiskConfigurationException when no ceiling can be established
     */
    public function ceilingFor(NumberLimit $limit, ExposureType $type): array
    {
        $column = $type->ceilingColumn();

        if ($column === null) {
            throw RiskConfigurationException::invalidLimit(
                sprintf('%s is a derived figure and has no ceiling column', $type->value),
                ['exposure_type' => $type->value, 'number_limit_id' => $limit->getKey()],
            );
        }

        $raw = $limit->getAttribute($column);

        if ($raw !== null && $raw !== '') {
            $ceiling = $this->money->money((string) $raw, $this->currency());

            if (! $ceiling->isPositive()) {
                throw RiskConfigurationException::invalidLimit(
                    sprintf('%s on this limit is not greater than zero', $column),
                    [
                        'exposure_type' => $type->value,
                        'column' => $column,
                        'value' => (string) $raw,
                        'number_limit_id' => $limit->getKey(),
                    ],
                );
            }

            return ['ceiling' => $ceiling, 'source' => 'row'];
        }

        if (! $type->ceilingMayBeNull()) {
            // max_amount is NOT NULL in the schema, so reaching here means the row
            // was built outside the schema's guarantees.
            throw RiskConfigurationException::invalidLimit(
                sprintf('%s is NULL but the schema declares it NOT NULL', $column),
                [
                    'exposure_type' => $type->value,
                    'column' => $column,
                    'number_limit_id' => $limit->getKey(),
                ],
            );
        }

        $fallback = $this->fallbackCeiling($type);

        if (! $fallback instanceof Money) {
            throw RiskConfigurationException::invalidLimit(
                sprintf(
                    '%s is NULL and no fallback ceiling is configured; refusing to treat the '
                    .'number as unlimited',
                    $column,
                ),
                [
                    'exposure_type' => $type->value,
                    'column' => $column,
                    'number_limit_id' => $limit->getKey(),
                ],
            );
        }

        return ['ceiling' => $fallback, 'source' => 'config'];
    }

    /**
     * The accumulated amount on $limit for $type.
     *
     * CombinedExposure has no column, so it is computed as stake plus payout
     * exposure and is never read from or written to the database.
     *
     * @throws RiskConfigurationException
     */
    public function currentFor(NumberLimit $limit, ExposureType $type): Money
    {
        if ($type === ExposureType::CombinedExposure) {
            return $this->money->combinedExposure(
                $this->currentFor($limit, ExposureType::Stake),
                $this->currentFor($limit, ExposureType::PotentialPayout),
            );
        }

        $column = $type->currentColumn();

        if ($column === null) {
            throw RiskConfigurationException::invalidLimit(
                sprintf('%s has no accumulated-amount column', $type->value),
                ['exposure_type' => $type->value],
            );
        }

        // Both columns default to 0 and carry CHECK (>= 0); a NULL would mean the
        // row was written outside the schema, and fromColumn reads it as zero,
        // which is the only non-guessing reading of "no exposure recorded".
        return $this->money->fromColumn(
            $limit->getAttribute($column),
            $this->currency(),
        );
    }

    /**
     * The status of $limit as a risk-domain value.
     *
     * The model casts the column to App\Enums\LimitStatus, so the value arriving
     * here is a LimitStatus instance and is bridged, not re-parsed. Vocabulary
     * drift between the two enums is asserted first so a mismatch surfaces as a
     * refusal rather than as a wrong decision.
     *
     * @throws RiskConfigurationException
     */
    public function statusOf(NumberLimit $limit): NumberLimitStatus
    {
        NumberLimitStatus::assertVocabularyMatchesDatabaseEnum();

        return NumberLimitStatus::coerce($limit->getAttribute('status'));
    }

    /**
     * The currency every amount on a number_limits row is denominated in.
     *
     * There is no currency column on the table, so the default betting currency is
     * authoritative. An unrecognised configured value fails rather than defaulting,
     * because silently switching currency would corrupt every comparison.
     *
     * @throws RiskConfigurationException
     */
    public function currency(): Currency
    {
        $configured = $this->config->get('lottery.betting.currency');

        if (! is_string($configured) || trim($configured) === '') {
            throw RiskConfigurationException::missingKey('lottery.betting.currency');
        }

        $currency = Currency::tryFrom(strtoupper(trim($configured)));

        if (! $currency instanceof Currency) {
            throw RiskConfigurationException::invalidKey(
                'lottery.betting.currency',
                'the configured betting currency is not a supported currency',
                trim($configured),
            );
        }

        return $currency;
    }

    /**
     * A complete, log-safe snapshot of what governs this triple.
     *
     * Returns the same shape whether or not a row exists, so callers never have to
     * branch on null before reading 'limit_source'. 'reservable' is the honest
     * answer to "can capacity be atomically reserved here", and it is false
     * whenever there is no row to lock.
     *
     * @return array{
     *     draw_id: int,
     *     bet_type: string,
     *     number: string,
     *     limit_source: string,
     *     number_limit_id: int|null,
     *     status: string|null,
     *     currency: string,
     *     reservable: bool,
     *     ceilings: array<string, array{ceiling: string, source: string, current: string}>,
     *     errors: array<string, string>
     * }
     *
     * @throws RiskConfigurationException when the number itself is invalid
     */
    public function describe(int $drawId, BetType $betType, string $rawNumber): array
    {
        $number = $this->numbers->normalize($rawNumber, $betType);
        $limit = $this->resolve($drawId, $betType, $number);
        $currency = $this->currency();

        $snapshot = [
            'draw_id' => $drawId,
            'bet_type' => $betType->value,
            'number' => $number,
            'limit_source' => 'none',
            'number_limit_id' => null,
            'status' => null,
            'currency' => $currency->value,
            'reservable' => false,
            'ceilings' => [],
            'errors' => [],
        ];

        if (! $limit instanceof NumberLimit) {
            try {
                $fallback = $this->fallbackCeiling(ExposureType::PotentialPayout);
            } catch (RiskConfigurationException $exception) {
                $snapshot['errors']['fallback'] = $exception->getMessage();
                $fallback = null;
            }

            if ($fallback instanceof Money) {
                $snapshot['limit_source'] = 'config_fallback';
                $snapshot['ceilings'][ExposureType::PotentialPayout->value] = [
                    'ceiling' => $fallback->amount(),
                    'source' => 'config',
                    'current' => Money::zero($currency)->amount(),
                ];
            }

            return $snapshot;
        }

        $snapshot['limit_source'] = 'number_limit_row';
        $snapshot['number_limit_id'] = (int) $limit->getKey();

        try {
            $snapshot['status'] = $this->statusOf($limit)->value;
        } catch (RiskConfigurationException $exception) {
            $snapshot['errors']['status'] = $exception->getMessage();
        }

        foreach (ExposureType::enforced() as $type) {
            try {
                $resolved = $this->ceilingFor($limit, $type);
                $snapshot['ceilings'][$type->value] = [
                    'ceiling' => $resolved['ceiling']->amount(),
                    'source' => $resolved['source'],
                    'current' => $this->currentFor($limit, $type)->amount(),
                ];
            } catch (RiskConfigurationException $exception) {
                $snapshot['errors'][$type->value] = $exception->getMessage();
            }
        }

        // Reservation needs a lockable row, a resolvable ceiling for every enforced
        // type, and no resolution error.
        $snapshot['reservable'] = $snapshot['errors'] === []
            && count($snapshot['ceilings']) === count(ExposureType::enforced());

        return $snapshot;
    }
}
