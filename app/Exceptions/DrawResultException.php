<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A refused draw-result ingestion or confirmation.
 *
 * Thrown when an official result cannot safely enter the result pipeline:
 * the draw is in a lifecycle state where results are undefined, the ingested
 * numbers contradict the recorded publication, an unreviewed row tries to be
 * published, or confirmation is attempted on a row whose state forbids it.
 *
 * WHY NOT DrawLifecycleException
 * That one guards the lifecycle state machine itself (open/close/settle
 * transitions). Ingestion and confirmation are the INPUT side of results —
 * an operator paste, a GLO feed line, a correction — and can be wrong even on
 * draws in a perfectly valid lifecycle state. Feeding and moving are
 * different concerns with different catch sites (feed handlers vs schedulers).
 *
 * CONTEXT CONTRACT: ids, draw numbers, prize values and status strings only.
 */
class DrawResultException extends RuntimeException
{
    public const CODE_DRAW_NOT_FOUND = 'RESULT_DRAW_NOT_FOUND';

    public const CODE_EMPTY_RESULT = 'RESULT_EMPTY_PAYLOAD';

    public const CODE_MALFORMED = 'RESULT_MALFORMED';

    public const CODE_DUPLICATE_INGESTION = 'RESULT_DUPLICATE_INGESTION';

    public const CODE_CONFIRMATION_FORBIDDEN = 'RESULT_CONFIRMATION_FORBIDDEN';

    public const CODE_PUBLISH_REQUIRES_CONFIRMED = 'RESULT_PUBLISH_REQUIRES_CONFIRMED';

    public const CODE_MISMATCH_CONFIRMED = 'RESULT_MISMATCH_CONFIRMED';

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
     * The draw the result names does not exist.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function drawNotFound(int $drawId, array $context = []): self
    {
        return new self(
            sprintf('Draw #%d does not exist; its result cannot enter the pipeline.', $drawId),
            self::CODE_DRAW_NOT_FOUND,
            $context + ['draw_id' => $drawId],
        );
    }

    /**
     * The ingested payload violates the result format contract (six-digit
     * first prize, two-digit bottom-two agreeing with the first prize).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function malformed(string $source, string $reason, array $context = []): self
    {
        return new self(
            sprintf('The result ingested from [%s] is malformed: %s.', $source, $reason),
            self::CODE_MALFORMED,
            $context + ['source' => $source, 'reason' => $reason],
        );
    }

    /**
     * The ingested payload carries no first-prize number at all: a result
     * with no first prize is not a partial result, it is a broken one.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function emptyResult(int $drawId, string $source, array $context = []): self
    {
        return new self(
            sprintf('The result for draw #%d ingested from [%s] carries no first prize number.', $drawId, $source),
            self::CODE_EMPTY_RESULT,
            $context + ['draw_id' => $drawId, 'source' => $source],
        );
    }

    /**
     * The exact same result fingerprint was already ingested for this draw.
     * A replay yields the same stored row and writes nothing; a second,
     * DIFFERENT result for the same draw is a correction and must never be
     * served by the idempotent path.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function duplicateIngestion(int $drawId, string $fingerprint, array $context = []): self
    {
        return new self(
            sprintf(
                'Draw #%d already ingested a result with fingerprint [%s]; an identical re-ingestion is a no-op, '
                .'a different one is a correction that must supersede, not append.',
                $drawId,
                $fingerprint,
            ),
            self::CODE_DUPLICATE_INGESTION,
            $context + ['draw_id' => $drawId, 'fingerprint' => $fingerprint],
        );
    }

    /**
     * Confirmation was attempted on a row whose confirmation state forbids
     * it (already confirmed, rejected, or superseded).
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function confirmationForbidden(int $resultId, string $status, string $attemptedStep, array $context = []): self
    {
        return new self(
            sprintf(
                'Draw-result row #%d is %s and cannot be %s.',
                $resultId,
                $status,
                $attemptedStep,
            ),
            self::CODE_CONFIRMATION_FORBIDDEN,
            $context + [
                'result_id' => $resultId,
                'confirmation_status' => $status,
                'attempted_step' => $attemptedStep,
            ],
        );
    }

    /**
     * Publication reached for an UNCONFIRMED result. The four-eyes rule is
     * that no single hand can publish what only one hand ingested.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function publishRequiresConfirmed(int $drawId, string $currentStatus, array $context = []): self
    {
        return new self(
            sprintf(
                'The result of draw #%d is %s; only a confirmed result may be published to players.',
                $drawId,
                $currentStatus,
            ),
            self::CODE_PUBLISH_REQUIRES_CONFIRMED,
            $context + ['draw_id' => $drawId, 'confirmation_status' => $currentStatus],
        );
    }

    /**
     * A confirmation attempt compared the row against values that disagree —
     * the confirming operator was shown numbers different from what the
     * ingestion stored. Confirming either version would attest a falsehood.
     *
     * @param  array<string, scalar|null>  $context
     */
    public static function mismatchConfirmed(int $resultId, string $field, string $stored, string $driven, array $context = []): self
    {
        return new self(
            sprintf(
                'Draw-result row #%d stores %s=[%s] but the confirmation claimed [%s]; the two are reported, not reconciled silently.',
                $resultId,
                $field,
                $stored,
                $driven,
            ),
            self::CODE_MISMATCH_CONFIRMED,
            $context + [
                'result_id' => $resultId,
                'field' => $field,
                'stored_value' => $stored,
                'claimed_value' => $driven,
            ],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
