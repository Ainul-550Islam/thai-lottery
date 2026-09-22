<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The webhook ingress lane's pronounced refusals.
 *
 * - PAYMENT_WEBHOOK_MALFORMED         the envelope cannot be parsed into
 *                                       the trusted internal shape.
 * - PAYMENT_WEBHOOK_SIGNATURE_INVALID the bytes were not signed by the
 *                                       key the provider registered —
 *                                       the single loudest refusal.
 * - PAYMENT_WEBHOOK_REPLAY_WINDOW     the envelope arrived past its
 *                                       replay horizon without a chain
 *                                       of custody we can check.
 * - PAYMENT_WEBHOOK_PROVIDER_MISMATCH the route names one provider, the
 *                                       envelope claims another.
 * - PAYMENT_WEBHOOK_EVENT_MISMATCH    the envelope's own event identity
 *                                       disagrees with its payload facts.
 * - PAYMENT_WEBHOOK_DUPLICATE         same fingerprint, DIFFERENT facts —
 *                                       replay of identical evidence is a
 *                                       free no-op; this is a fork.
 * - PAYMENT_WEBHOOK_NOT_FOUND         a named webhook is missing from
 *                                       the evidence room.
 */
final class PaymentWebhookException extends Exception
{
    public const CODE_MALFORMED = 'PAYMENT_WEBHOOK_MALFORMED';

    public const CODE_SIGNATURE_INVALID = 'PAYMENT_WEBHOOK_SIGNATURE_INVALID';

    public const CODE_REPLAY_WINDOW = 'PAYMENT_WEBHOOK_REPLAY_WINDOW';

    public const CODE_PROVIDER_MISMATCH = 'PAYMENT_WEBHOOK_PROVIDER_MISMATCH';

    public const CODE_EVENT_MISMATCH = 'PAYMENT_WEBHOOK_EVENT_MISMATCH';

    public const CODE_DUPLICATE = 'PAYMENT_WEBHOOK_DUPLICATE';

    public const CODE_NOT_FOUND = 'PAYMENT_WEBHOOK_NOT_FOUND';

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
            sprintf('Payment webhook refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function signatureInvalid(string $provider, array $context = []): self
    {
        return new self(
            sprintf('Payment webhook refused: bytes claiming to be [%s] were not signed by its key', $provider),
            self::CODE_SIGNATURE_INVALID,
            $context + ['provider' => $provider],
        );
    }

    public static function replayWindow(string $provider, string $eventId, array $context = []): self
    {
        return new self(
            sprintf('Payment webhook refused: [%s] event [%s] arrived past the replay horizon', $provider, $eventId),
            self::CODE_REPLAY_WINDOW,
            $context + ['provider' => $provider, 'event_id' => $eventId],
        );
    }

    public static function providerMismatch(string $routeProvider, string $claimedProvider, array $context = []): self
    {
        return new self(
            sprintf('Payment webhook refused: the route says [%s], the envelope claims [%s]', $routeProvider, $claimedProvider),
            self::CODE_PROVIDER_MISMATCH,
            $context + ['route_provider' => $routeProvider, 'claimed_provider' => $claimedProvider],
        );
    }

    public static function eventMismatch(string $provider, string $eventId, string $why, array $context = []): self
    {
        return new self(
            sprintf('Payment webhook refused: [%s] event [%s] disagrees with its own payload — %s', $provider, $eventId, $why),
            self::CODE_EVENT_MISMATCH,
            $context + ['provider' => $provider, 'event_id' => $eventId, 'why' => $why],
        );
    }

    public static function duplicate(string $fingerprint, array $context = []): self
    {
        return new self(
            sprintf('Payment webhook refused: fingerprint [%s] is known, but the facts differ — a fork', substr($fingerprint, 0, 16).'…'),
            self::CODE_DUPLICATE,
            $context + ['fingerprint_prefix' => substr($fingerprint, 0, 16)],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Payment webhook refused: [%s] is not in the evidence room', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function context(): array
    {
        return $this->errorContext;
    }
}
