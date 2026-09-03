<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BetValidationCode;
use App\Enums\MarketResultType;

/**
 * The winning value a market needs cannot be read from the draw.
 *
 * Thrown by App\Services\Betting\MarketResultResolver. It exists so the engine
 * never invents a result: an absent, unpublished, malformed or schema-unbacked
 * winning value stops the evaluation instead of producing a plausible number.
 *
 * SCHEMA LIMITATION
 * The draw_results table has no bottom_two column. The two digit bottom number is
 * carried inside draw_results.metadata under
 * config('lottery.results.bottom_two_metadata_key'). When that key is absent or
 * malformed, bottomTwoMissing() or bottomTwoMalformed() is thrown and
 * isSchemaLimitation() returns true, so a report can distinguish "the operator has
 * not entered the bottom number" from "the code is broken". No migration is
 * created and no column is invented.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * No fallback value, no default, no zero, no empty string result. No writes.
 */
class MarketResultUnavailableException extends MarketRuleException
{
    /**
     * Machine readable reason for a schema-backed unavailability.
     */
    public const SCHEMA_LIMITATION = 'SCHEMA_LIMITATION';

    /**
     * The draw itself does not exist.
     */
    public static function drawNotFound(int $drawId): self
    {
        return new self(
            sprintf('Draw %d does not exist, so no winning value can be resolved.', $drawId),
            BetValidationCode::DrawNotFound->value,
            ['draw_id' => $drawId],
        );
    }

    /**
     * The draw has no draw_results row yet.
     */
    public static function noResult(int $drawId): self
    {
        return new self(
            sprintf('Draw %d has no result row yet, so no winning value can be resolved.', $drawId),
            BetValidationCode::ValidationFailed->value,
            ['draw_id' => $drawId, 'reason' => 'result_row_absent'],
        );
    }

    /**
     * A result row exists but has not been published.
     *
     * config('lottery.payouts.require_published_result') declares whether an
     * unpublished result may be read.
     */
    public static function notPublished(int $drawId): self
    {
        return new self(
            sprintf(
                'The result of draw %d is not published and config(lottery.payouts.require_published_result) '
                .'forbids reading an unpublished result.',
                $drawId,
            ),
            BetValidationCode::ValidationFailed->value,
            ['draw_id' => $drawId, 'reason' => 'result_not_published'],
        );
    }

    /**
     * The first prize number is absent, so no top value can be derived.
     */
    public static function firstPrizeMissing(int $drawId): self
    {
        return new self(
            sprintf('Draw %d has no first prize number, so the top result cannot be derived.', $drawId),
            BetValidationCode::ValidationFailed->value,
            ['draw_id' => $drawId, 'reason' => 'first_prize_absent'],
        );
    }

    /**
     * The first prize number is present but unusable.
     */
    public static function firstPrizeUnusable(int $drawId, string $value, int $requiredDigits): self
    {
        return new self(
            sprintf(
                'The first prize of draw %d is "%s", which cannot yield %d trailing digit(s) of digit '
                .'characters only.',
                $drawId,
                $value,
                $requiredDigits,
            ),
            BetValidationCode::ValidationFailed->value,
            [
                'draw_id' => $drawId,
                'first_prize' => $value,
                'required_digits' => $requiredDigits,
                'reason' => 'first_prize_unusable',
            ],
        );
    }

    /**
     * The bottom two number is not present in the metadata JSON.
     *
     * SCHEMA LIMITATION: there is no bottom_two column to fall back to.
     */
    public static function bottomTwoMissing(int $drawId, string $metadataKey): self
    {
        return new self(
            sprintf(
                'SCHEMA LIMITATION: draw_results has no bottom_two column, and draw %d carries no "%s" key in '
                .'its metadata JSON, so the two digit bottom result cannot be resolved. No value is assumed.',
                $drawId,
                $metadataKey,
            ),
            self::SCHEMA_LIMITATION,
            [
                'draw_id' => $drawId,
                'metadata_key' => $metadataKey,
                'result_type' => MarketResultType::TwoDigitBottom->value,
                'reason' => 'bottom_two_metadata_absent',
            ],
        );
    }

    /**
     * The bottom two metadata key exists but does not hold two digit characters.
     *
     * SCHEMA LIMITATION: the JSON column is untyped, so a hand-edited value such
     * as the integer 7 instead of the string '07' surfaces here rather than being
     * padded into something the platform then treats as authoritative.
     */
    public static function bottomTwoMalformed(int $drawId, string $metadataKey, string $given): self
    {
        return new self(
            sprintf(
                'SCHEMA LIMITATION: the "%s" metadata value of draw %d is "%s", which is not a two digit '
                .'string. The two digit bottom result is refused rather than repaired.',
                $metadataKey,
                $drawId,
                $given,
            ),
            self::SCHEMA_LIMITATION,
            [
                'draw_id' => $drawId,
                'metadata_key' => $metadataKey,
                'given_value' => $given,
                'result_type' => MarketResultType::TwoDigitBottom->value,
                'reason' => 'bottom_two_metadata_malformed',
            ],
        );
    }

    /**
     * The configured source key for this market is not supported by the resolver.
     */
    public static function unsupportedSource(string $marketKey, string $source): self
    {
        return new self(
            sprintf(
                'Market %s declares result source "%s", which App\Services\Betting\MarketResultResolver '
                .'cannot read.',
                $marketKey,
                $source,
            ),
            BetValidationCode::UnsupportedMarket->value,
            ['market_key' => $marketKey, 'result_source' => $source],
        );
    }

    /**
     * True when the cause is the database schema rather than the input.
     */
    public function isSchemaLimitation(): bool
    {
        return $this->errorCode() === self::SCHEMA_LIMITATION;
    }
}
