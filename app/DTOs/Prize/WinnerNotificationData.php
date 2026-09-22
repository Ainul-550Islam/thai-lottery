<?php

declare(strict_types=1);

namespace App\DTOs\Prize;

/**
 * Winner notification payload.
 *
 * THE GRAMMAR
 * - payout_id: the prize reference — always the handle, and the service
 *   derives the claimant FROM it (the DTO never carries a claimant the
 *   caller makes up for somebody else).
 * - channel: one of the allowed delivery channels.
 * - draw_reference: the board's public draw handle (draw_number).
 * - result_version: the board version the notification speaks from.
 * - dedupe_key: caller-provided idempotency anchor (≥ 16 chars) — e.g.
 *   the match key or an ops run id; the channel plus key is the durable
 *   uniqueness.
 */
final readonly class WinnerNotificationData
{
    /**
     * Channels the board permits (keep the vocabulary here, never a
     * string bag at call sites).
     *
     * @var array<int, string>
     */
    public const ALLOWED_CHANNELS = ['mail', 'database', 'sms'];

    public function __construct(
        public int $payoutId,
        public string $channel,
        public string $drawReference,
        public int $resultVersion,
        public string $dedupeKey,
    ) {
    }

    /**
     * @throws \App\Exceptions\WinnerNotificationException
     */
    public static function fromInput(
        int $payoutId,
        string $channel,
        string $drawReference,
        int $resultVersion,
        string $dedupeKey,
    ): self {
        $ch = strtolower(trim($channel));
        $ref = strtoupper(trim($drawReference));
        $dedupe = trim($dedupeKey);

        if ($payoutId < 1) {
            throw \App\Exceptions\WinnerNotificationException::malformed(
                'the payout handle must be a positive integer',
            );
        }

        if (! in_array($ch, self::ALLOWED_CHANNELS, true)) {
            throw \App\Exceptions\WinnerNotificationException::invalidChannel(
                $ch,
            );
        }

        if (! preg_match('/^[A-Z0-9-]{1,64}$/', $ref)) {
            throw \App\Exceptions\WinnerNotificationException::malformed(
                'the draw reference is not canonical (uppercase ASCII, digits, hyphens)',
            );
        }

        if ($resultVersion < 1) {
            throw \App\Exceptions\WinnerNotificationException::malformed(
                'the result version must be a positive integer',
            );
        }

        if (mb_strlen($dedupe) < 16 || mb_strlen($dedupe) > 64) {
            throw \App\Exceptions\WinnerNotificationException::malformed(
                'the dedupe key must be 16-64 characters',
            );
        }

        return new self(
            payoutId: $payoutId,
            channel: $ch,
            drawReference: $ref,
            resultVersion: $resultVersion,
            dedupeKey: $dedupe,
        );
    }

    /**
     * Durable uniqueness over (payout, channel): one lane per (prize,
     * medium), with the caller's dedupe key INSIDE the identity so two
     * differently-keyed asks are visibly different conversations.
     */
    public function notificationKey(int $claimantUserId): string
    {
        return hash('sha256', sprintf(
            'winner-notif:%d:%d:%s:%s',
            $this->payoutId,
            $claimantUserId,
            $this->channel,
            $this->dedupeKey,
        ));
    }
}
