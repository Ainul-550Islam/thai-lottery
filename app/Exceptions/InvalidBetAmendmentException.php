<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The requested amendment payload is structurally unusable: nothing to change,
 * an unchanged selection, or a stake that is not a positive decimal string.
 *
 * Raised before any state is touched, so it always maps to 422 with a stable
 * code and never leaves an amendment row behind.
 */
class InvalidBetAmendmentException extends BetAmendmentException
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function nothingToChange(int $betId, array $context = []): static
    {
        return static::withCode(
            'bet_amendment_nothing_to_change',
            sprintf('Amendment for bet %d changes neither the number nor the stake.', $betId),
            array_merge(['bet_id' => $betId], $context),
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function unchangedSelection(int $betId, array $context = []): static
    {
        return static::withCode(
            'bet_amendment_unchanged_selection',
            sprintf('The amended selection is identical to the current bet %d.', $betId),
            array_merge(['bet_id' => $betId], $context),
        );
    }

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function invalidField(string $field, string $reason, array $context = []): static
    {
        return static::withCode(
            'bet_amendment_invalid_field',
            sprintf('The amendment field "%s" is invalid: %s.', $field, $reason),
            array_merge(['field' => $field], $context),
        );
    }
}
