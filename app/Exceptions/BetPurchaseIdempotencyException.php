<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * An idempotency key that cannot be honoured.
 *
 * WHY THIS IS NOT App\Exceptions\IdempotencyConflictException
 * Phase 2.1 already ships IdempotencyConflictException for keys reused across
 * FINANCIAL TRANSACTIONS, and that exception keeps its job: it is what the wallet
 * engine throws when a key was previously used for a different amount, wallet or
 * transaction type. This exception is about the PURCHASE REQUEST, whose identity is
 * broader - it also covers the draw, the market, the number and the stake. A key
 * reused for a different NUMBER is a purchase conflict even though the amount and
 * wallet may match perfectly, so the financial exception could not express it.
 * Where the underlying fault does come from Phase 2.1, that exception is attached
 * as $previous and never discarded.
 *
 * WHY A REUSED KEY IS NOT SILENTLY ACCEPTED
 * The safe-looking alternative - return the earlier bet whenever the key matches,
 * whatever else changed - is dangerous: a client bug that reuses one key for two
 * genuinely different bets would then have its second bet silently swallowed, the
 * player would be shown a ticket for a number they did not choose, and no error
 * would ever be raised. A replay is therefore only honoured when the request is
 * materially identical; a key reused with a different payload is refused here.
 *
 * NUMBER DUPLICATION IS NOT REQUEST DUPLICATION
 * Buying the same number twice with two different keys is legitimate and must
 * create two bets. Nothing in this class treats a repeated NUMBER as a repeated
 * REQUEST; only the key decides.
 *
 * MUTATION GUARANTEE
 * Every factory below is raised either before the purchase transaction opens or
 * immediately after the wallet row lock and before any write. No wallet, ledger,
 * bet, bet item, ticket or number limit has been mutated when a caller sees this
 * exception.
 */
final class BetPurchaseIdempotencyException extends BetPurchaseException
{
    /**
     * The client supplied no idempotency key, but the pipeline requires one.
     *
     * A purchase without a key cannot be made safe against a retried request, and
     * a silently generated random key would defeat the guarantee entirely: every
     * retry would then look like a brand new purchase and debit the player twice.
     */
    public static function keyRequired(): self
    {
        return new self(
            'An idempotency key is required for a bet purchase. Without one, a retried request '
            .'could not be distinguished from a new purchase and the player could be debited twice.',
            'bet_purchase_idempotency_key_required',
            [],
        );
    }

    /**
     * The key is structurally unusable.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function keyInvalid(string $reason, array $context = [], ?Throwable $previous = null): self
    {
        return new self(
            sprintf('The supplied idempotency key is unusable: %s.', $reason),
            'bet_purchase_idempotency_key_invalid',
            $context,
            $previous,
        );
    }

    /**
     * The key was already used by a purchase whose request differs from this one.
     *
     * @param  list<string>  $mismatchedFields
     * @param  array<string, scalar|null>  $context
     */
    public static function payloadMismatch(
        string $idempotencyKey,
        array $mismatchedFields,
        array $context = [],
        ?Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Idempotency key "%s" was already used for a different purchase. Mismatched '
                .'field(s): %s. Refusing to replay a bet the player did not ask for, and refusing '
                .'to create a second bet under a key that is already taken.',
                $idempotencyKey,
                implode(', ', $mismatchedFields),
            ),
            'bet_purchase_idempotency_payload_mismatch',
            array_merge([
                'idempotency_key' => $idempotencyKey,
                'mismatched_fields' => implode(',', $mismatchedFields),
            ], $context),
            $previous,
        );
    }

    /**
     * A bet exists for the key but its committed companion rows cannot be read
     * back, so no faithful replay can be returned.
     *
     * Raised instead of returning a partially populated result, because a result
     * missing its ticket or its financial transaction would look to a caller like a
     * purchase that had lost those rows.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function replayIncomplete(int $betId, string $missing, array $context = []): self
    {
        return new self(
            sprintf(
                'Bet %d was found for this idempotency key but its %s could not be read back, so no '
                .'faithful replay can be returned. This indicates the earlier purchase did not '
                .'commit as a whole and must be investigated rather than replayed.',
                $betId,
                $missing,
            ),
            'bet_purchase_replay_incomplete',
            array_merge(['bet_id' => $betId, 'missing' => $missing], $context),
        );
    }

    /**
     * The database offers no unique constraint capable of enforcing this key.
     *
     * Kept as an explicit, loud failure so that a deployment against an older
     * schema cannot degrade into cache-only idempotency, which would stop working
     * the moment two application processes did not share a cache.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function schemaChangeRequired(string $detail, array $context = []): self
    {
        return new self(
            sprintf(
                'SCHEMA CHANGE REQUIRED: %s. Refusing to fall back to cache-only idempotency, '
                .'because a cache cannot prevent two concurrent processes from creating two bets.',
                $detail,
            ),
            'bet_purchase_idempotency_schema_change_required',
            $context,
        );
    }
}
