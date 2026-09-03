<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\DrawLifecycleState;
use RuntimeException;
use Throwable;

/**
 * A refused draw lifecycle operation: an invalid state transition, a modification of
 * a frozen draw, or a duplicate result publication.
 *
 * WHY A NEW CLASS AND NOT AN EXISTING ONE
 * ---------------------------------------
 * App\Exceptions\InvalidFinancialStateTransitionException exists and looks similar,
 * but it belongs to the finance domain: code that catches it does so in order to
 * unwind a wallet or ledger operation, and such a catch block must not swallow a
 * draw lifecycle refusal, which involves no money at all.
 *
 * App\Exceptions\BetDomainException is the base of the betting domain
 * (App\Services\Betting, App\ValueObjects, App\DTOs) and carries the
 * App\Enums\BetValidationCode vocabulary, which has no case for a draw state.
 * Extending it would make a draw refusal look like a rejected bet.
 *
 * The shape is deliberately identical to BetDomainException - (message, errorCode,
 * context) with errorCode(), context() and toArray() - so callers handle betting,
 * finance and draw failures with the same idioms.
 *
 * DELIBERATE NON-RESPONSIBILITIES
 * - No HTTP status, no render(). Phase 5.1 adds no HTTP surface.
 * - No queries and no models, so it is safe to throw from inside a transaction that
 *   is about to roll back, including while holding SELECT ... FOR UPDATE on the draw.
 *
 * SECURITY
 * The context array carries safe diagnostic identifiers only: draw id, draw number,
 * state names, timestamps. Never credentials, tokens, raw request payloads or
 * personal data, because these values are logged.
 */
class DrawLifecycleException extends RuntimeException
{
    public const CODE_INVALID_TRANSITION = 'DRAW_INVALID_TRANSITION';

    public const CODE_TERMINAL_STATE = 'DRAW_TERMINAL_STATE';

    public const CODE_IMMUTABLE = 'DRAW_IMMUTABLE';

    public const CODE_DUPLICATE_PUBLICATION = 'DRAW_DUPLICATE_PUBLICATION';

    public const CODE_NOT_PUBLISHABLE = 'DRAW_NOT_PUBLISHABLE';

    public const CODE_NOT_SETTLEABLE = 'DRAW_NOT_SETTLEABLE';

    public const CODE_NOT_FOUND = 'DRAW_NOT_FOUND';

    public const CODE_RESULT_MISSING = 'DRAW_RESULT_MISSING';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * A transition the lifecycle table does not declare.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function invalidTransition(
        int $drawId,
        DrawLifecycleState $from,
        DrawLifecycleState $to,
        array $context = [],
    ): self {
        $allowed = array_map(
            static fn (DrawLifecycleState $state): string => $state->value,
            $from->allowedTransitions(),
        );

        return new self(
            sprintf(
                'Draw %d cannot move from %s to %s. Allowed from %s: %s.',
                $drawId,
                $from->value,
                $to->value,
                $from->value,
                $allowed === [] ? 'nothing, it is terminal' : implode(', ', $allowed),
            ),
            self::CODE_INVALID_TRANSITION,
            $context + [
                'draw_id' => $drawId,
                'from' => $from->value,
                'to' => $to->value,
                'allowed' => implode(',', $allowed),
            ],
        );
    }

    /**
     * A transition out of a terminal state.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function terminalState(
        int $drawId,
        DrawLifecycleState $state,
        DrawLifecycleState $to,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Draw %d is in terminal state %s and accepts no further transition, so it cannot '
                .'move to %s.',
                $drawId,
                $state->value,
                $to->value,
            ),
            self::CODE_TERMINAL_STATE,
            $context + [
                'draw_id' => $drawId,
                'state' => $state->value,
                'to' => $to->value,
            ],
        );
    }

    /**
     * A modification attempted on a draw that is no longer mutable.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function immutable(
        int $drawId,
        DrawLifecycleState $state,
        string $attemptedChange,
        array $context = [],
    ): self {
        return new self(
            sprintf(
                'Draw %d is in state %s and is frozen; %s is refused. A draw may only be modified '
                .'while it is draft or open.',
                $drawId,
                $state->value,
                $attemptedChange,
            ),
            self::CODE_IMMUTABLE,
            $context + [
                'draw_id' => $drawId,
                'state' => $state->value,
                'attempted_change' => $attemptedChange,
            ],
        );
    }

    /**
     * A second publication attempt for a draw that already has an official result.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicatePublication(int $drawId, string $detectedBy, array $context = []): self
    {
        return new self(
            sprintf(
                'Draw %d already has a published official result; a second publication is refused. '
                .'Detected by: %s.',
                $drawId,
                $detectedBy,
            ),
            self::CODE_DUPLICATE_PUBLICATION,
            $context + [
                'draw_id' => $drawId,
                'detected_by' => $detectedBy,
            ],
        );
    }

    /**
     * Publication attempted from a state that does not allow it.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notPublishable(int $drawId, DrawLifecycleState $state, array $context = []): self
    {
        return new self(
            sprintf(
                'Draw %d is in state %s; an official result may only be published from %s.',
                $drawId,
                $state->value,
                DrawLifecycleState::ResultPending->value,
            ),
            self::CODE_NOT_PUBLISHABLE,
            $context + [
                'draw_id' => $drawId,
                'state' => $state->value,
                'required_state' => DrawLifecycleState::ResultPending->value,
            ],
        );
    }

    /**
     * Settlement attempted from a state that does not allow it.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notSettleable(int $drawId, DrawLifecycleState $state, array $context = []): self
    {
        return new self(
            sprintf(
                'Draw %d is in state %s; simulated settlement may only run from %s.',
                $drawId,
                $state->value,
                DrawLifecycleState::ResultPublished->value,
            ),
            self::CODE_NOT_SETTLEABLE,
            $context + [
                'draw_id' => $drawId,
                'state' => $state->value,
                'required_state' => DrawLifecycleState::ResultPublished->value,
            ],
        );
    }

    /**
     * The draw does not exist.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function notFound(int $drawId, array $context = []): self
    {
        return new self(
            sprintf('Draw %d does not exist.', $drawId),
            self::CODE_NOT_FOUND,
            $context + ['draw_id' => $drawId],
        );
    }

    /**
     * The draw is in a state that implies a published result, but no draw_results row
     * exists. Reported rather than repaired.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function resultMissing(int $drawId, DrawLifecycleState $state, array $context = []): self
    {
        return new self(
            sprintf(
                'Draw %d is in state %s, which implies a published official result, but no '
                .'draw_results row exists for it. This is refused rather than repaired.',
                $drawId,
                $state->value,
            ),
            self::CODE_RESULT_MISSING,
            $context + [
                'draw_id' => $drawId,
                'state' => $state->value,
            ],
        );
    }

    /**
     * Stable machine-readable identifier for this refusal.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Safe diagnostic identifiers attached to the refusal.
     *
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * A single context value, or null when the key was not supplied.
     */
    public function contextValue(string $key): string|int|float|bool|null
    {
        return $this->context[$key] ?? null;
    }

    /**
     * Structured, log-safe representation.
     *
     * @return array{type: string, error_code: string, message: string, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'type' => static::class,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }
}
