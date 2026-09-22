<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The publication lane's pronounced refusals.
 *
 * - DRAW_PUB_MALFORMED        grammar never accepted the ask.
 * - DRAW_PUB_NOT_FOUND        named publication/draw/certification missing.
 * - DRAW_PUB_STATE_FORBIDS    lifecycle in the way (retracting a row that
 *                             isn't live, etc.).
 * - DRAW_PUB_STALE_VERSION    version isn't exactly board_max+1 — someone
 *                             is trying to re-publish an old conversation.
 * - DRAW_PUB_ALREADY_PUBLISHED asked to publish what is already the live
 *                              version (no work to do — refused loudly so
 *                             replay loops never multiply evidence).
 * - DRAW_PUB_ALREADY_RETRACTED asked to retract what isn't up.
 * - DRAW_PUB_UNCERTIFIED      the certification named is not yet Certified
 *                             — the board never eats/drinks anything else.
 */
final class DrawPublicationException extends Exception
{
    public const CODE_MALFORMED = 'DRAW_PUB_MALFORMED';

    public const CODE_NOT_FOUND = 'DRAW_PUB_NOT_FOUND';

    public const CODE_STATE_FORBIDS = 'DRAW_PUB_STATE_FORBIDS';

    public const CODE_STALE_VERSION = 'DRAW_PUB_STALE_VERSION';

    public const CODE_ALREADY_PUBLISHED = 'DRAW_PUB_ALREADY_PUBLISHED';

    public const CODE_ALREADY_RETRACTED = 'DRAW_PUB_ALREADY_RETRACTED';

    public const CODE_UNCERTIFIED = 'DRAW_PUB_UNCERTIFIED';

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $errorContext = [],
    ) {
        parent::__construct($message);
    }

    public static function malformed(string $reason, array $context = []): self
    {
        return new self(
            sprintf('Draw publication refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Draw publication refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function stateForbids(string $publicationKey, string $currentState, string $asked, array $context = []): self
    {
        return new self(
            sprintf('Draw publication refused: publication is in [%s], which forbids [%s]', $currentState, $asked),
            self::CODE_STATE_FORBIDS,
            $context + ['publication_key' => $publicationKey, 'current_state' => $currentState, 'asked' => $asked],
        );
    }

    public static function staleVersion(int $drawId, int $offered, int $expected, array $context = []): self
    {
        return new self(
            sprintf('Draw publication refused: for draw #%d the next live version must be %d, not %d', $drawId, $expected, $offered),
            self::CODE_STALE_VERSION,
            $context + ['draw_id' => $drawId, 'offered' => $offered, 'expected' => $expected],
        );
    }

    public static function alreadyPublished(int $drawId, int $version, array $context = []): self
    {
        return new self(
            sprintf('Draw publication refused: draw #%d version %d is already on the board', $drawId, $version),
            self::CODE_ALREADY_PUBLISHED,
            $context + ['draw_id' => $drawId, 'version' => $version],
        );
    }

    public static function alreadyRetracted(int $drawId, int $version, array $context = []): self
    {
        return new self(
            sprintf('Draw publication refused: draw #%d version %d has already retracted', $drawId, $version),
            self::CODE_ALREADY_RETRACTED,
            $context + ['draw_id' => $drawId, 'version' => $version],
        );
    }

    public static function uncertified(int $drawId, string $certificationStatus, array $context = []): self
    {
        return new self(
            sprintf('Draw publication refused: draw #%d certification is [%s], and the board only publishes Certified', $drawId, $certificationStatus),
            self::CODE_UNCERTIFIED,
            $context + ['draw_id' => $drawId, 'certification_status' => $certificationStatus],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->errorContext;
    }
}
