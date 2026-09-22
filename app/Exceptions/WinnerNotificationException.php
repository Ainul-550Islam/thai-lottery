<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * The notification lane's pronounced refusals.
 *
 * - WINNER_NOTIF_MALFORMED          grammar never accepted the ask.
 * - WINNER_NOTIF_NOT_FOUND          named payout/notification missing.
 * - WINNER_NOTIF_INVALID_RECIPIENT  derived claimant isn't addressable.
 * - WINNER_NOTIF_INVALID_CHANNEL    channel outside the allowed set.
 * - WINNER_NOTIF_DUPLICATE          different ask under the same dedupe
 *                                   identity, or a send attempt against
 *                                   an already-Sent/Acknowledged row.
 * - WINNER_NOTIF_PROVIDER_REJECTED  the provider refused the hand-off.
 */
final class WinnerNotificationException extends Exception
{
    public const CODE_MALFORMED = 'WINNER_NOTIF_MALFORMED';

    public const CODE_NOT_FOUND = 'WINNER_NOTIF_NOT_FOUND';

    public const CODE_INVALID_RECIPIENT = 'WINNER_NOTIF_INVALID_RECIPIENT';

    public const CODE_INVALID_CHANNEL = 'WINNER_NOTIF_INVALID_CHANNEL';

    public const CODE_DUPLICATE = 'WINNER_NOTIF_DUPLICATE';

    public const CODE_PROVIDER_REJECTED = 'WINNER_NOTIF_PROVIDER_REJECTED';

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
            sprintf('Winner notification refused: %s', $reason),
            self::CODE_MALFORMED,
            $context + ['reason' => $reason],
        );
    }

    public static function notFound(string $reference, array $context = []): self
    {
        return new self(
            sprintf('Winner notification refused: [%s] is not on the ledger', $reference),
            self::CODE_NOT_FOUND,
            $context + ['reference' => $reference],
        );
    }

    public static function invalidRecipient(int $payoutId, array $context = []): self
    {
        return new self(
            sprintf('Winner notification refused: the claimant of payout #%d is not addressable', $payoutId),
            self::CODE_INVALID_RECIPIENT,
            $context + ['payout_id' => $payoutId],
        );
    }

    public static function invalidChannel(string $channel, array $context = []): self
    {
        return new self(
            sprintf('Winner notification refused: channel [%s] is not permitted', $channel),
            self::CODE_INVALID_CHANNEL,
            $context + ['channel' => $channel],
        );
    }

    public static function duplicate(string $notificationKey, array $context = []): self
    {
        return new self(
            sprintf('Winner notification refused: duplicate dispatch under %s...', substr($notificationKey, 0, 12)),
            self::CODE_DUPLICATE,
            $context + ['notification_key' => $notificationKey],
        );
    }

    public static function providerRejected(string $channel, string $reason, array $context = []): self
    {
        return new self(
            sprintf('Winner notification refused: provider for [%s] rejected (%s)', $channel, $reason),
            self::CODE_PROVIDER_REJECTED,
            $context + ['channel' => $channel, 'reason' => $reason],
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
