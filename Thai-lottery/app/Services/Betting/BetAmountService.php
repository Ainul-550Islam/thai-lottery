<?php

declare(strict_types=1);

namespace App\Services\Betting;

use App\Enums\Currency;
use App\Exceptions\BetDomainException;
use App\Exceptions\FinancialException;
use App\Exceptions\InvalidBetAmountException;
use App\Services\Finance\Money;
use App\ValueObjects\BetAmount;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Validates stakes against the project's declared betting bounds.
 *
 * WHERE THE BOUNDS COME FROM
 * config('lottery.betting'): min_amount '10.00', max_amount '100000.00',
 * amount_step '1.00' and currency 'THB'. Every bound is read from there and none is
 * hard-coded, so an operator changes limits in configuration rather than in code.
 *
 * VALIDATION ONLY - NO MONEY MOVES
 * This service reads configuration and compares decimal strings. It does not touch a
 * wallet, does not check a balance, does not create a hold, does not lock a balance
 * and does not post a ledger entry. Whether the player can AFFORD the stake is a
 * different question, answered by the Phase 2 wallet services at purchase time under
 * their own lock; this service only answers whether the stake is a legal amount.
 *
 * EXACT DECIMAL THROUGHOUT
 * All arithmetic runs through App\Services\Finance\Money and bcmath. There is no
 * (float), no (double), no floatval and no round(). An amount with more decimal
 * places than the currency scale is refused, not rounded, and an amount off the
 * configured step is refused, not snapped, because silently changing a stake changes
 * the contract the player is agreeing to.
 *
 * THE STEP CHECK IS EXACT AND STRING-BASED
 * Divisibility is tested with bcmod at working precision rather than with fmod, since
 * fmod on binary floats reports a non-zero remainder for values that are exactly
 * divisible in decimal.
 */
class BetAmountService
{
    /**
     * Working precision for the divisibility test. Wide enough for any configured
     * step at any supported currency scale.
     */
    private const WORKING_SCALE = 12;

    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * Parse and fully validate a stake: format, positivity, bounds and step.
     *
     * @throws InvalidBetAmountException
     * @throws BetDomainException when the configured bounds are unusable
     */
    public function parse(string $raw, ?Currency $currency = null): BetAmount
    {
        $currency ??= $this->currency();
        $stake = BetAmount::of($raw, $currency);

        $this->assertWithinBounds($stake);
        $this->assertOnStep($stake);

        return $stake;
    }

    /**
     * Parse and validate, returning null instead of throwing on a bad stake.
     *
     * @throws BetDomainException when the configured bounds are unusable
     */
    public function tryParse(string $raw, ?Currency $currency = null): ?BetAmount
    {
        try {
            return $this->parse($raw, $currency);
        } catch (InvalidBetAmountException) {
            return null;
        }
    }

    /**
     * Is a raw value a fully valid stake.
     */
    public function isValid(string $raw, ?Currency $currency = null): bool
    {
        try {
            $this->parse($raw, $currency);

            return true;
        } catch (InvalidBetAmountException | BetDomainException) {
            return false;
        }
    }

    /**
     * Validate an already-constructed stake.
     *
     * @throws InvalidBetAmountException
     * @throws BetDomainException
     */
    public function validate(BetAmount $stake): BetAmount
    {
        $this->assertWithinBounds($stake);
        $this->assertOnStep($stake);

        return $stake;
    }

    /**
     * The configured betting currency.
     *
     * @throws BetDomainException when configuration names no currency this project
     *                            declares
     */
    public function currency(): Currency
    {
        $configured = $this->config->get('lottery.betting.currency');

        if (! is_string($configured) || $configured === '') {
            throw BetDomainException::misconfigured(
                'lottery.betting.currency',
                'no betting currency is configured',
            );
        }

        $currency = Currency::tryFrom($configured);

        if (! $currency instanceof Currency) {
            throw BetDomainException::misconfigured(
                'lottery.betting.currency',
                sprintf('"%s" is not a currency this project declares', $configured),
            );
        }

        return $currency;
    }

    /**
     * The configured minimum stake.
     *
     * @throws BetDomainException
     */
    public function minimum(?Currency $currency = null): Money
    {
        return $this->boundary('min_amount', $currency ?? $this->currency());
    }

    /**
     * The configured maximum stake.
     *
     * @throws BetDomainException
     */
    public function maximum(?Currency $currency = null): Money
    {
        return $this->boundary('max_amount', $currency ?? $this->currency());
    }

    /**
     * The configured stake step, or null when no step is configured.
     *
     * @throws BetDomainException when a step is configured but unusable
     */
    public function step(?Currency $currency = null): ?Money
    {
        $configured = $this->config->get('lottery.betting.amount_step');

        if ($configured === null || $configured === '') {
            return null;
        }

        $step = $this->boundary('amount_step', $currency ?? $this->currency());

        if (! $step->isPositive()) {
            throw BetDomainException::misconfigured(
                'lottery.betting.amount_step',
                'a stake step must be greater than zero',
            );
        }

        return $step;
    }

    /**
     * Reject a stake outside the configured bounds.
     *
     * @throws InvalidBetAmountException
     * @throws BetDomainException
     */
    public function assertWithinBounds(BetAmount $stake): void
    {
        $minimum = $this->minimum($stake->currency());
        $maximum = $this->maximum($stake->currency());

        if ($minimum->isGreaterThan($maximum)) {
            throw BetDomainException::misconfigured(
                'lottery.betting.min_amount',
                sprintf(
                    'the configured minimum %s exceeds the configured maximum %s, so no stake could '
                    .'ever be valid',
                    $minimum->amount(),
                    $maximum->amount(),
                ),
            );
        }

        if ($stake->isBelow($minimum)) {
            throw InvalidBetAmountException::belowMinimum($stake->amount(), $minimum->amount(), [
                'currency' => $stake->currency()->value,
            ]);
        }

        if ($stake->isAbove($maximum)) {
            throw InvalidBetAmountException::aboveMaximum($stake->amount(), $maximum->amount(), [
                'currency' => $stake->currency()->value,
            ]);
        }
    }

    /**
     * Reject a stake that does not sit on the configured step.
     *
     * @throws InvalidBetAmountException
     * @throws BetDomainException
     */
    public function assertOnStep(BetAmount $stake): void
    {
        $step = $this->step($stake->currency());

        if (! $step instanceof Money) {
            return;
        }

        if (! $this->isOnStep($stake, $step)) {
            throw InvalidBetAmountException::offStep($stake->amount(), $step->amount(), [
                'currency' => $stake->currency()->value,
            ]);
        }
    }

    /**
     * Exact decimal divisibility, with no float and no rounding.
     */
    public function isOnStep(BetAmount $stake, Money $step): bool
    {
        $remainder = bcmod($stake->amount(), $step->amount(), self::WORKING_SCALE);

        return bccomp($remainder, '0', self::WORKING_SCALE) === 0;
    }

    /**
     * The declared bounds, for reporting.
     *
     * @return array{
     *     currency: string,
     *     minimum: string,
     *     maximum: string,
     *     step: string|null,
     *     scale: int,
     *     sources: array<string, string>
     * }
     *
     * @throws BetDomainException
     */
    public function bounds(?Currency $currency = null): array
    {
        $currency ??= $this->currency();
        $step = $this->step($currency);

        return [
            'currency' => $currency->value,
            'minimum' => $this->minimum($currency)->amount(),
            'maximum' => $this->maximum($currency)->amount(),
            'step' => $step?->amount(),
            'scale' => $currency->scale(),
            'sources' => [
                'currency' => 'config lottery.betting.currency',
                'minimum' => 'config lottery.betting.min_amount',
                'maximum' => 'config lottery.betting.max_amount',
                'step' => 'config lottery.betting.amount_step',
            ],
        ];
    }

    /**
     * Read a configured monetary boundary as exact money.
     *
     * @throws BetDomainException
     */
    private function boundary(string $key, Currency $currency): Money
    {
        $configured = $this->config->get('lottery.betting.'.$key);

        if (is_int($configured)) {
            $configured = (string) $configured;
        }

        if (! is_string($configured) || trim($configured) === '') {
            throw BetDomainException::misconfigured(
                'lottery.betting.'.$key,
                'it must hold an exact decimal string',
            );
        }

        try {
            return Money::of(trim($configured), $currency);
        } catch (FinancialException $exception) {
            throw BetDomainException::misconfigured(
                'lottery.betting.'.$key,
                sprintf('"%s" is not an exact decimal amount', trim($configured)),
                ['underlying' => $exception->getMessage()],
            );
        }
    }
}
