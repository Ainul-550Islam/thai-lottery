<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BetValidationCode;

/**
 * The market rule, or a selection measured against it, is unusable.
 *
 * Thrown by App\Services\Betting\MarketRuleResolver and by the four match
 * services when a selection cannot be evaluated: wrong digit count, a market
 * that is not configured or disabled, a permutation asked of a market that does
 * not permute, or a rule the project has not declared.
 *
 * These are refusals, not accidents. Every factory states the exact rule that was
 * violated, using digit strings so '007' is never reduced to 7.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * No money, no persistence, no HTTP status, no correction of the caller's input.
 */
class InvalidMarketRuleException extends MarketRuleException
{
    /**
     * The market key is not present in config('lottery.markets').
     */
    public static function unknownMarket(string $marketKey, string $configuredKeys): self
    {
        return new self(
            sprintf(
                'Market %s is not declared in config(lottery.markets). Configured markets: %s.',
                $marketKey,
                $configuredKeys,
            ),
            BetValidationCode::UnsupportedMarket->value,
            ['market_key' => $marketKey, 'configured_markets' => $configuredKeys],
        );
    }

    /**
     * The market exists but is switched off.
     */
    public static function disabledMarket(string $marketKey): self
    {
        return new self(
            sprintf('Market %s is disabled in configuration and cannot be evaluated.', $marketKey),
            BetValidationCode::UnsupportedMarket->value,
            ['market_key' => $marketKey, 'enabled' => false],
        );
    }

    /**
     * A required rule attribute is missing or of the wrong shape.
     */
    public static function malformedRule(string $marketKey, string $attribute, string $reason): self
    {
        return new self(
            sprintf(
                'The market rule for %s has an unusable %s: %s.',
                $marketKey,
                $attribute,
                $reason,
            ),
            BetValidationCode::ValidationFailed->value,
            ['market_key' => $marketKey, 'attribute' => $attribute],
        );
    }

    /**
     * The selection carries the wrong number of digits for the market.
     *
     * Both values are reported as strings and digit counts, never as integers, so
     * a leading zero problem is visible in the message.
     */
    public static function wrongDigitCount(string $marketKey, string $selection, int $required): self
    {
        return new self(
            sprintf(
                'Market %s requires exactly %d digit(s) but the selection "%s" carries %d.',
                $marketKey,
                $required,
                $selection,
                strlen($selection),
            ),
            BetValidationCode::InvalidDigits->value,
            [
                'market_key' => $marketKey,
                'selection' => $selection,
                'required_digits' => $required,
                'given_digits' => strlen($selection),
            ],
        );
    }

    /**
     * The selection contains something other than the characters 0-9.
     */
    public static function nonNumericSelection(string $marketKey, string $selection): self
    {
        return new self(
            sprintf(
                'Market %s accepts digit characters 0-9 only; the selection "%s" does not qualify.',
                $marketKey,
                $selection,
            ),
            BetValidationCode::InvalidNumber->value,
            ['market_key' => $marketKey, 'selection' => $selection],
        );
    }

    /**
     * Permutations were requested for a market that does not permute.
     *
     * The authoritative Phase 4.2 rule is RUN = NO PERMUTATION, and the direct
     * three digit and both two digit markets are exact match markets.
     */
    public static function permutationNotAllowed(string $marketKey): self
    {
        return new self(
            sprintf(
                'Market %s does not permute its selection, so permutations must not be generated for it.',
                $marketKey,
            ),
            BetValidationCode::InvalidSelectionType->value,
            ['market_key' => $marketKey, 'permutation_allowed' => false],
        );
    }

    /**
     * A permutation market was asked to evaluate under an exact match rule, or
     * the reverse.
     */
    public static function wrongMatchMode(string $marketKey, string $expected, string $actual): self
    {
        return new self(
            sprintf(
                'Market %s is evaluated with the %s rule, not the %s rule.',
                $marketKey,
                $expected,
                $actual,
            ),
            BetValidationCode::ValidationFailed->value,
            [
                'market_key' => $marketKey,
                'expected_match_mode' => $expected,
                'attempted_match_mode' => $actual,
            ],
        );
    }

    /**
     * The winning value handed to a matcher does not fit the market's result type.
     */
    public static function unusableWinningValue(string $marketKey, string $winning, int $expectedDigits): self
    {
        return new self(
            sprintf(
                'Market %s is decided by a %d digit result but the value "%s" carries %d digit(s).',
                $marketKey,
                $expectedDigits,
                $winning,
                strlen($winning),
            ),
            BetValidationCode::ValidationFailed->value,
            [
                'market_key' => $marketKey,
                'winning_value' => $winning,
                'expected_digits' => $expectedDigits,
            ],
        );
    }

    /**
     * The configured result source is not one this engine knows.
     */
    public static function unknownResultSource(string $marketKey, string $source, string $known): self
    {
        return new self(
            sprintf(
                'Market %s declares result source "%s", which is not one of the supported sources: %s.',
                $marketKey,
                $source,
                $known,
            ),
            BetValidationCode::UnsupportedMarket->value,
            ['market_key' => $marketKey, 'result_source' => $source, 'supported_sources' => $known],
        );
    }
}
