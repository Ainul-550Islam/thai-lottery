<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\BetValidationCode;
use Throwable;

/**
 * Base exception for the Phase 4.2 market rule engine.
 *
 * Extends App\Exceptions\BetDomainException so every existing caller that
 * already catches the betting domain keeps catching these failures unchanged,
 * while code that cares specifically about market rules can catch this narrower
 * type. Two subclasses exist:
 *
 *   App\Exceptions\InvalidMarketRuleException      the rule itself is unusable
 *   App\Exceptions\MarketResultUnavailableException the drawn value cannot be read
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No wallet, ledger, bet, bet item, ticket or payout mutation. Nothing in the
 *   market rule engine writes money or bets.
 * - No queries. Safe to throw inside a transaction that is about to roll back.
 * - No HTTP concerns.
 *
 * SECURITY
 * Context carries safe diagnostics only: market key, draw id, canonical digit
 * strings, digit counts, configuration key paths. Never credentials or payloads.
 */
class MarketRuleException extends BetDomainException
{
    /**
     * A market rule cannot be resolved at all.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function unresolvable(string $marketKey, string $reason, array $context = []): static
    {
        return new static(
            sprintf('The market rule for %s cannot be resolved: %s.', $marketKey, $reason),
            BetValidationCode::UnsupportedMarket->value,
            $context + ['market_key' => $marketKey],
        );
    }

    /**
     * A market rule exists but conflicts with another declared rule.
     *
     * Reported rather than resolved by preference, because silently choosing one
     * of two contradictory rules is how a platform starts paying the wrong price.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function conflicting(string $marketKey, string $reason, array $context = []): static
    {
        return new static(
            sprintf('REPORT CONFLICT: the market rule for %s is contradictory: %s.', $marketKey, $reason),
            BetValidationCode::ValidationFailed->value,
            $context + ['market_key' => $marketKey],
        );
    }

    /**
     * The market this failure concerns, when one was supplied.
     */
    public function marketKey(): ?string
    {
        $value = $this->contextValue('market_key');

        return is_string($value) ? $value : null;
    }

    /**
     * Whether the failure is caused by the database schema rather than by input.
     *
     * Overridden by App\Exceptions\MarketResultUnavailableException. Kept here so
     * a caller can ask any market rule failure the question without type checks.
     */
    public function isSchemaLimitation(): bool
    {
        return false;
    }

    /**
     * Preserve the parent's named constructor typing for subclasses.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function forMarket(
        string $marketKey,
        string $errorCode,
        string $message,
        array $context = [],
        ?Throwable $previous = null,
    ): static {
        return new static($message, $errorCode, $context + ['market_key' => $marketKey], $previous);
    }
}
