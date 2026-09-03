<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\NumberLimitStatus;

/**
 * A number is refused because it is blocked or too hot to sell.
 *
 * Two distinct causes share this class because both mean "this number, not this
 * amount", and both are surfaced by App\Services\Risk\HotNumberService. They keep
 * separate stable reason codes so a client can tell them apart:
 *
 *   NUMBER_BLOCKED  a hard block: the number is listed in
 *                   config('risk.blocked_numbers.global'), or its limit row is
 *                   Suspended or Removed. Amount is irrelevant.
 *
 *   HOT_NUMBER      a soft refusal: the number is still sellable in principle but
 *                   its utilisation has passed the hot threshold and
 *                   config('risk.exposure.block_on_exceeded') semantics apply.
 *
 * Numbers are always carried as strings so leading zeros survive: '007' stays
 * '007' and is never rendered as 7.
 */
class HotNumberException extends RiskException
{
    public const REASON_BLOCKED = 'NUMBER_BLOCKED';

    public const REASON_HOT = 'HOT_NUMBER';

    /**
     * The number is globally blocked by configuration.
     *
     * @param  string  $number  canonical zero-padded number
     * @param  string  $betType  bet type value, e.g. '2d'
     */
    public static function globallyBlocked(string $number, string $betType): self
    {
        return new self(
            sprintf('Number %s (%s) is on the global blocked list.', $number, $betType),
            self::REASON_BLOCKED,
            [
                'number' => $number,
                'bet_type' => $betType,
                'cause' => 'global_blocked_list',
            ],
        );
    }

    /**
     * The number's limit row exists but its status forbids selling.
     *
     * @param  string  $number  canonical zero-padded number
     * @param  string  $betType  bet type value
     * @param  NumberLimitStatus  $status  the real status read from the row
     */
    public static function blockedByStatus(
        string $number,
        string $betType,
        NumberLimitStatus $status,
        ?int $numberLimitId = null,
        ?int $drawId = null,
    ): self {
        return new self(
            sprintf(
                'Number %s (%s) is not sellable: its limit is %s.',
                $number,
                $betType,
                $status->label(),
            ),
            $status->rejectionReasonCode() ?? self::REASON_BLOCKED,
            [
                'number' => $number,
                'bet_type' => $betType,
                'cause' => 'limit_status',
                'status' => $status->value,
                'number_limit_id' => $numberLimitId,
                'draw_id' => $drawId,
            ],
        );
    }

    /**
     * The number is hot: utilisation has passed the configured hot threshold.
     *
     * @param  string  $number  canonical zero-padded number
     * @param  string  $betType  bet type value
     * @param  string  $utilisation  exact decimal ratio, e.g. '0.9250'
     * @param  string  $threshold  exact decimal ratio that was crossed
     */
    public static function tooHot(
        string $number,
        string $betType,
        string $utilisation,
        string $threshold,
        ?int $numberLimitId = null,
        ?int $drawId = null,
    ): self {
        return new self(
            sprintf(
                'Number %s (%s) is hot: utilisation %s has passed the threshold %s.',
                $number,
                $betType,
                $utilisation,
                $threshold,
            ),
            self::REASON_HOT,
            [
                'number' => $number,
                'bet_type' => $betType,
                'cause' => 'hot_threshold',
                'utilisation' => $utilisation,
                'threshold' => $threshold,
                'number_limit_id' => $numberLimitId,
                'draw_id' => $drawId,
            ],
        );
    }

    /**
     * True when the refusal is a hard block rather than a heat warning.
     */
    public function isHardBlock(): bool
    {
        return $this->reasonCode() !== self::REASON_HOT;
    }

    /**
     * Canonical number the refusal concerns, leading zeros intact.
     */
    public function number(): ?string
    {
        $value = $this->contextValue('number');

        return is_string($value) ? $value : null;
    }

    public function reasonCode(): string
    {
        $code = $this->errorCode();

        return is_string($code) && $code !== '' ? $code : self::REASON_BLOCKED;
    }
}
