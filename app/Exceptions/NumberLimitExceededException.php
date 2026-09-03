<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ExposureType;

/**
 * A number limit cannot absorb the requested amount.
 *
 * Thrown from inside the row-locked critical section in
 * App\Services\Risk\NumberLimitEngine when the projected amount for the ceiling
 * being checked would exceed that ceiling. The bet must not be placed and no
 * money may be debited.
 *
 * The context deliberately carries the exact decimal strings the engine compared,
 * so a support engineer can reproduce the refusal without re-running it. All
 * amounts are exact decimal strings; there is no float anywhere in this class.
 */
class NumberLimitExceededException extends RiskException
{
    /**
     * Stable reason code for the decision layer.
     */
    public const REASON_CODE = 'NUMBER_LIMIT_EXCEEDED';

    /**
     * Build the exception for a ceiling that would be breached.
     *
     * @param  ExposureType  $type  which ceiling was breached (stake or payout)
     * @param  string  $number  canonical zero-padded number, e.g. '007'
     * @param  string  $betType  bet type value, e.g. '3d'
     * @param  string  $current  exact decimal already accumulated
     * @param  string  $requested  exact decimal this request would add
     * @param  string  $projected  exact decimal current + requested
     * @param  string  $ceiling  exact decimal maximum permitted
     * @param  int|null  $numberLimitId  id of the number_limits row, when known
     * @param  int|null  $drawId  id of the draw, when known
     */
    public static function forCeiling(
        ExposureType $type,
        string $number,
        string $betType,
        string $current,
        string $requested,
        string $projected,
        string $ceiling,
        ?int $numberLimitId = null,
        ?int $drawId = null,
    ): self {
        return new self(
            sprintf(
                '%s for number %s (%s) would reach %s against a ceiling of %s.',
                $type->label(),
                $number,
                $betType,
                $projected,
                $ceiling,
            ),
            self::REASON_CODE,
            [
                'exposure_type' => $type->value,
                'number' => $number,
                'bet_type' => $betType,
                'current' => $current,
                'requested' => $requested,
                'projected' => $projected,
                'ceiling' => $ceiling,
                'number_limit_id' => $numberLimitId,
                'draw_id' => $drawId,
            ],
        );
    }

    /**
     * The ceiling that was breached, or null when the context did not record it.
     */
    public function exposureType(): ?ExposureType
    {
        $value = $this->contextValue('exposure_type');

        return is_string($value) ? ExposureType::tryFrom($value) : null;
    }

    /**
     * Canonical number the refusal concerns, as a string with leading zeros kept.
     */
    public function number(): ?string
    {
        $value = $this->contextValue('number');

        return is_string($value) ? $value : null;
    }

    /**
     * Exact decimal amount the ceiling still had room for at refusal time.
     *
     * Computed from the recorded ceiling and current amount rather than stored
     * separately, and floored at zero so an already-breached row reports '0.00'
     * remaining instead of a negative figure. bcsub keeps this exact; no rounding
     * is applied as a correction.
     */
    public function remainingCapacity(?int $scale = 2): ?string
    {
        $ceiling = $this->contextValue('ceiling');
        $current = $this->contextValue('current');

        if (! is_string($ceiling) || ! is_string($current) || ! extension_loaded('bcmath')) {
            return null;
        }

        $scale ??= 2;
        $remaining = bcsub($ceiling, $current, $scale);

        return bccomp($remaining, '0', $scale) < 0
            ? bcadd('0', '0', $scale)
            : $remaining;
    }

    public function reasonCode(): string
    {
        return self::REASON_CODE;
    }
}
