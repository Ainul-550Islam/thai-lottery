<?php

declare(strict_types=1);

namespace App\DTOs\ResponsibleGaming;

use App\Exceptions\RealityCheckException;

/**
 * Reality-check identity: user, session reference + duration
 * threshold, due time, delivery channel, acknowledgement state.
 * Delivery is replay-safe by fingerprint — re-dispatching the same
 * due check lands on the same row, never doubles the pronouncement.
 */
final class RealityCheckData
{
    private const MIN_THRESHOLD_MINUTES = 15;

    private const MAX_THRESHOLD_MINUTES = 24 * 60;

    private const ACK_HORIZON_HOURS = 6;

    public function __construct(
        public readonly int $userId,
        public readonly string $sessionReference,
        public readonly int $thresholdMinutes,
        public readonly \DateTimeInterface $dueAt,
        public readonly string $deliveryChannel = 'in_app',
        public readonly ?\DateTimeInterface $expiresAt = null,
    ) {}

    /**
     * @param  array{user_id:int, session_reference:string, threshold_minutes:int|string, due_at:\DateTimeInterface, delivery_channel?:string, expires_at?:\DateTimeInterface|null}  $data
     */
    public static function fromInput(array $data): self
    {
        $session = trim((string) ($data['session_reference'] ?? ''));
        $threshold = (int) ($data['threshold_minutes'] ?? 0);
        $channel = strtolower(trim((string) ($data['delivery_channel'] ?? 'in_app')));

        if (! preg_match('/^[A-Za-z0-9:_\-\.]{8,64}$/', $session)) {
            throw RealityCheckException::invalidSchedule('A session reference must be a canonical 8-64 character token');
        }

        if ($threshold < self::MIN_THRESHOLD_MINUTES || $threshold > self::MAX_THRESHOLD_MINUTES) {
            throw RealityCheckException::invalidSchedule(sprintf(
                'threshold must fall in [%d, %d] minutes', self::MIN_THRESHOLD_MINUTES, self::MAX_THRESHOLD_MINUTES,
            ));
        }

        $dueAt = $data['due_at'] ?? null;

        if (! $dueAt instanceof \DateTimeInterface) {
            throw RealityCheckException::invalidSchedule('A due_at moment is required');
        }

        if (! in_array($channel, ['in_app', 'push', 'email'], true)) {
            throw RealityCheckException::invalidSchedule('Channel must be one of in_app, push, email');
        }

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            sessionReference: $session,
            thresholdMinutes: $threshold,
            dueAt: $dueAt,
            deliveryChannel: $channel,
            expiresAt: $data['expires_at'] ?? null,
        );
    }

    /**
     * Same (user, session, threshold, due window) = same delivery.
     */
    public function deliveryFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-rc', (string) $this->userId, $this->sessionReference,
            (string) $this->thresholdMinutes, $this->dueAt->format(DATE_ATOM),
        ]));
    }

    /**
     * The acknowledgement horizon stamped on the row at schedule time.
     */
    public function acknowledgementHorizon(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($this->dueAt->format(DATE_ATOM)))
            ->modify('+'.self::ACK_HORIZON_HOURS.' hours');
    }
}
