<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\BetMarket;
use App\Enums\BetType;
use App\Exceptions\BetDomainException;
use App\Exceptions\UnsupportedBetMarketException;
use App\ValueObjects\PayoutMultiplier;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Resolves the payout multiplier for a market from the project's authoritative
 * source, and refuses to guess when the declared sources contradict one another.
 *
 * WHICH SOURCE IS AUTHORITATIVE, AND WHY
 * The project states it itself: config('lottery.payouts.multiplier_source') holds
 * 'lottery.markets'. That declaration is the reason this service reads
 * config('lottery.markets.<key>.payout_multiplier') and not one of the other two
 * places a multiplier appears. Nothing here overrides that declaration; if an
 * operator changes it, resolution follows the new declaration or reports that it
 * points nowhere usable.
 *
 * THE THREE PLACES A MULTIPLIER IS DECLARED
 *   1. config('lottery.markets.<key>.payout_multiplier')  - authoritative per the above
 *   2. config('lottery.types.<bet_type>.payout_multiplier')
 *   3. App\Enums\BetType::payoutMultiplier()
 *
 * For 3D, Tod and 2D all three agree. For RUN they do not:
 *   run_top    -> 3
 *   run_bottom -> 4
 *   types.run  -> 12
 *   BetType::payoutMultiplier() for Run -> 12
 *
 * WHAT THIS SERVICE DOES ABOUT THAT
 * Nothing that changes a value. The existing source of truth is preserved exactly as
 * written, in configuration and in the enum, both of which are untouched by
 * Phase 4.1. In STRICT mode - the default - a market whose sources contradict one
 * another is refused with SPECIFICATION REQUIRED and the contradiction is named, so
 * the operator decides. The alternative, picking whichever number looks plausible,
 * would mean quietly selling Run at a rate nobody signed off: a 3x market sold at 12x
 * is a 4x liability the risk engine never budgeted for, and a 12x market sold at 3x
 * short-pays every winner. Neither is a guess this service is entitled to make.
 *
 * resolvePermissive() exists for the moment after sign-off: it returns the
 * authoritative market value while still reporting the contradiction. It is never the
 * default and is never reached by the validation path.
 *
 * NO PRICING, NO PERSISTENCE
 * This service returns rates. It does not compute a payout - that is
 * App\Services\Betting\BetCalculationService - and it writes nothing.
 */
class PayoutMultiplierService
{
    /**
     * The configuration prefix this service treats as authoritative when
     * config('lottery.payouts.multiplier_source') declares it.
     */
    public const MARKET_SOURCE = 'lottery.markets';

    /**
     * @param  bool  $strict  refuse a contradictory market rather than price it
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly bool $strict = true,
    ) {
    }

    /**
     * The multiplier for a market, from the authoritative source.
     *
     * In strict mode a market whose declared sources disagree is refused.
     *
     * @throws UnsupportedBetMarketException when the market is absent or disabled
     * @throws BetDomainException when the multiplier is missing, unusable, or
     *                            contradictory while strict
     */
    public function resolve(string $marketKey): PayoutMultiplier
    {
        $audit = $this->multiplierAudit($marketKey);

        if ($audit['authoritative'] === null) {
            throw BetDomainException::specificationRequired(
                sprintf(
                    'market %s declares no usable payout multiplier at its authoritative source %s',
                    $marketKey,
                    $audit['authoritative_source'],
                ),
                ['market' => $marketKey],
            );
        }

        if ($this->strict && $audit['contradictory'] === true) {
            throw BetDomainException::specificationRequired(
                sprintf(
                    'the payout multiplier for market %s is contradictory across the project\'s own '
                    .'declared sources (%s). No value has been changed and none has been chosen; '
                    .'the correct production rate must be specified before this market can be priced',
                    $marketKey,
                    $this->describeSources($audit['sources']),
                ),
                ['market' => $marketKey],
            );
        }

        return PayoutMultiplier::fromConfig($audit['authoritative'], $audit['authoritative_source']);
    }

    /**
     * The authoritative multiplier even when the sources contradict one another.
     *
     * Deliberately separate from resolve() so that pricing a contradictory market is
     * always an explicit, reviewable decision at the call site rather than a default.
     * Intended for Phase 4.2 after the Run rate has been signed off.
     *
     * @throws UnsupportedBetMarketException
     * @throws BetDomainException
     */
    public function resolvePermissive(string $marketKey): PayoutMultiplier
    {
        $audit = $this->multiplierAudit($marketKey);

        if ($audit['authoritative'] === null) {
            throw BetDomainException::specificationRequired(
                sprintf(
                    'market %s declares no usable payout multiplier at its authoritative source %s',
                    $marketKey,
                    $audit['authoritative_source'],
                ),
                ['market' => $marketKey],
            );
        }

        return PayoutMultiplier::fromConfig($audit['authoritative'], $audit['authoritative_source']);
    }

    /**
     * Resolve without throwing; null when the market cannot be priced.
     */
    public function tryResolve(string $marketKey): ?PayoutMultiplier
    {
        try {
            return $this->resolve($marketKey);
        } catch (BetDomainException | UnsupportedBetMarketException) {
            return null;
        }
    }

    /**
     * Can this market be priced right now.
     */
    public function canResolve(string $marketKey): bool
    {
        return $this->tryResolve($marketKey) instanceof PayoutMultiplier;
    }

    /**
     * Is this service configured to refuse contradictory markets.
     */
    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * A copy of this service that prices contradictory markets from the
     * authoritative source instead of refusing them.
     */
    public function permissive(): self
    {
        return new self($this->config, false);
    }

    /**
     * Where the project declares its multipliers to live.
     */
    public function declaredSource(): string
    {
        $declared = $this->config->get('lottery.payouts.multiplier_source');

        return is_string($declared) && $declared !== '' ? $declared : self::MARKET_SOURCE;
    }

    /**
     * Every declared multiplier for a market, and whether they agree.
     *
     * This is the audit the specification asks for. It reports what the project
     * actually says; it changes nothing and chooses nothing.
     *
     * @return array{
     *     market: string,
     *     bet_type: string|null,
     *     authoritative_source: string,
     *     authoritative: string|int|null,
     *     sources: array<string, string|int|null>,
     *     distinct_values: list<string>,
     *     contradictory: bool,
     *     specification_required: bool,
     *     note: string
     * }
     *
     * @throws UnsupportedBetMarketException when the market is absent or disabled
     */
    public function multiplierAudit(string $marketKey): array
    {
        $definition = $this->marketDefinition($marketKey);
        $betType = BetMarket::betTypeFor($definition);

        $declaredSource = $this->declaredSource();
        $authoritativeSource = $declaredSource === self::MARKET_SOURCE
            ? sprintf('config %s.%s.payout_multiplier', self::MARKET_SOURCE, $marketKey)
            : sprintf('config %s (as declared by lottery.payouts.multiplier_source)', $declaredSource);

        $marketValue = $this->scalarOrNull($definition['payout_multiplier'] ?? null);

        $typeKey = $betType instanceof BetType
            ? sprintf('config lottery.types.%s.payout_multiplier', $betType->value)
            : 'config lottery.types.<unknown>.payout_multiplier';
        $typeValue = $betType instanceof BetType
            ? $this->scalarOrNull($this->config->get(sprintf('lottery.types.%s.payout_multiplier', $betType->value)))
            : null;

        $enumValue = $betType instanceof BetType ? $betType->payoutMultiplier() : null;

        $sources = [
            sprintf('config lottery.markets.%s.payout_multiplier', $marketKey) => $marketValue,
            $typeKey => $typeValue,
            'App\\Enums\\BetType::payoutMultiplier()' => $this->scalarOrNull($enumValue),
        ];

        $distinct = [];

        foreach ($sources as $value) {
            if ($value === null) {
                continue;
            }

            $canonical = PayoutMultiplier::tryOf($value)?->value();

            if ($canonical !== null && ! in_array($canonical, $distinct, true)) {
                $distinct[] = $canonical;
            }
        }

        $contradictory = count($distinct) > 1;

        $authoritative = $declaredSource === self::MARKET_SOURCE ? $marketValue : null;

        $note = match (true) {
            $authoritative === null => sprintf(
                'SPECIFICATION REQUIRED: no usable multiplier at %s.',
                $authoritativeSource,
            ),
            $contradictory => sprintf(
                'SPECIFICATION REQUIRED: declared multipliers disagree (%s). The existing values have '
                .'been preserved exactly as written and nothing has been changed.',
                $this->describeSources($sources),
            ),
            default => 'All declared sources agree.',
        };

        return [
            'market' => $marketKey,
            'bet_type' => $betType?->value,
            'authoritative_source' => $authoritativeSource,
            'authoritative' => $authoritative,
            'sources' => $sources,
            'distinct_values' => $distinct,
            'contradictory' => $contradictory,
            'specification_required' => $contradictory || $authoritative === null,
            'note' => $note,
        ];
    }

    /**
     * The multiplier audit for every declared market.
     *
     * @return array<string, array<string, mixed>>
     */
    public function multiplierAuditAll(): array
    {
        $audit = [];

        foreach (BetMarket::allMarketKeys() as $marketKey) {
            try {
                $audit[$marketKey] = $this->multiplierAudit($marketKey);
            } catch (UnsupportedBetMarketException $exception) {
                $audit[$marketKey] = [
                    'market' => $marketKey,
                    'bet_type' => null,
                    'authoritative_source' => 'unavailable',
                    'authoritative' => null,
                    'sources' => [],
                    'distinct_values' => [],
                    'contradictory' => false,
                    'specification_required' => true,
                    'note' => 'SPECIFICATION REQUIRED: '.$exception->getMessage(),
                ];
            }
        }

        return $audit;
    }

    /**
     * The market keys that cannot be priced because their sources contradict.
     *
     * @return list<string>
     */
    public function contradictoryMarkets(): array
    {
        $affected = [];

        foreach ($this->multiplierAuditAll() as $marketKey => $audit) {
            if (($audit['contradictory'] ?? false) === true) {
                $affected[] = (string) $marketKey;
            }
        }

        return $affected;
    }

    /**
     * Markets whose multiplier is fractional and so cannot be written to
     * bet_items.payout_multiplier, which is an unsigned integer column.
     *
     * @return list<string>
     */
    public function marketsExceedingBetItemColumn(): array
    {
        $affected = [];

        foreach (BetMarket::allMarketKeys() as $marketKey) {
            $multiplier = $this->tryResolve($marketKey);

            if ($multiplier instanceof PayoutMultiplier && ! $multiplier->fitsBetItemColumn()) {
                $affected[] = $marketKey;
            }
        }

        return $affected;
    }

    /**
     * Human-readable rendering of a source map, for a message or a report.
     *
     * @param  array<string, string|int|null>  $sources
     */
    private function describeSources(array $sources): string
    {
        $parts = [];

        foreach ($sources as $key => $value) {
            $parts[] = sprintf('%s = %s', $key, $value === null ? 'absent' : (string) $value);
        }

        return implode('; ', $parts);
    }

    /**
     * Keep only values a multiplier can legitimately be declared as.
     */
    private function scalarOrNull(mixed $value): string|int|null
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * The configuration block for a market, requiring it to exist and be enabled.
     *
     * @return array<string, mixed>
     *
     * @throws UnsupportedBetMarketException
     */
    private function marketDefinition(string $marketKey): array
    {
        $definition = $this->config->get('lottery.markets.'.$marketKey);

        if (! is_array($definition) || $definition === []) {
            throw UnsupportedBetMarketException::notConfigured(
                $marketKey,
                'config lottery.markets.'.$marketKey.' is absent',
            );
        }

        if (($definition['enabled'] ?? false) !== true) {
            throw UnsupportedBetMarketException::notConfigured($marketKey, 'it is disabled in configuration');
        }

        return $definition;
    }
}
