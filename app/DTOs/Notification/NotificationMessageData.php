<?php

declare(strict_types=1);

namespace App\DTOs\Notification;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEventType;
use App\Enums\NotificationPriority;
use App\Exceptions\NotificationException;

/**
 * Deterministic notification identity: recipient + event + channel +
 * payload fingerprint (payload lives on the message row conveniently
 * rendered; its full cognitive content NEVER re-keys at attempt
 * time and never crosses into audit lanes).
 */
final class NotificationMessageData
{
    private const EXPIRY_HOURS = 72;

    public function __construct(
        public readonly int $userId,
        public readonly NotificationEventType $eventType,
        public readonly NotificationChannel $channel,
        public readonly NotificationPriority $priority,
        public readonly string $locale,
        public readonly string $subject,
        public readonly string $body,
        public readonly ?int $templateId,
        public readonly \DateTimeInterface $expiresAt,
    ) {
    }

    /**
     * @param array{user_id:int, event_type:string|NotificationEventType, channel:string|NotificationChannel, priority?:string|NotificationPriority, locale?:string, subject:string, body:string, template_id?:int|null, expires_at?:\DateTimeInterface|null} $data
     */
    public static function fromInput(array $data): self
    {
        $event = $data['event_type'] ?? null;
        if (! $event instanceof NotificationEventType) {
            $event = is_string($event) ? NotificationEventType::tryFrom(strtolower(trim($event))) : null;
        }
        if (! $event instanceof NotificationEventType) {
            throw NotificationException::malformed('A valid event type is required');
        }

        $channel = $data['channel'] ?? null;
        if (! $channel instanceof NotificationChannel) {
            $channel = is_string($channel) ? NotificationChannel::tryFrom(strtolower(trim($channel))) : null;
        }
        if (! $channel instanceof NotificationChannel) {
            throw NotificationException::malformed('A channel is required');
        }

        $priority = $data['priority'] ?? null;
        $priority = $priority instanceof NotificationPriority
            ? $priority
            : (is_string($priority) ? NotificationPriority::tryFrom($priority) : null)
            ?? $event->defaultPriority();

        $subject = mb_substr(trim((string) ($data['subject'] ?? '')), 0, 255);
        $body = trim((string) ($data['body'] ?? ''));

        if ($subject === '' && $body === '') {
            throw NotificationException::malformed('A message needs a subject or a body');
        }

        $expiresAt = $data['expires_at']
            ?? \Illuminate\Support\Carbon::now()->addHours(self::EXPIRY_HOURS);

        return new self(
            userId: (int) ($data['user_id'] ?? 0),
            eventType: $event,
            channel: $channel,
            priority: $priority,
            locale: strtolower(substr((string) ($data['locale'] ?? 'th'), 0, 8)),
            subject: $subject,
            body: $body,
            templateId: isset($data['template_id']) ? (int) $data['template_id'] : null,
            expiresAt: $expiresAt,
        );
    }

    /**
     * Same recipient + event + channel + content = one pronouncement.
     */
    public function messageFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-notif', (string) $this->userId, $this->eventType->value,
            $this->channel->value, $this->subject, $this->body,
        ]));
    }

    public function payloadFingerprint(): string
    {
        return hash('sha256', 'glo-notif-payload|'.$this->subject.'|'.$this->body);
    }
}
