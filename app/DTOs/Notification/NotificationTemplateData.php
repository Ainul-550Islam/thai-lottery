<?php

declare(strict_types=1);

namespace App\DTOs\Notification;

use App\Exceptions\NotificationTemplateException;

/**
 * Versioned template identity: key + locale + version +
 * immutable CONTENT fingerprint. Same content = one row forever.
 */
final class NotificationTemplateData
{
    public function __construct(
        public readonly string $templateKey,
        public readonly string $locale,
        public readonly int $version,
        public readonly string $subject,
        public readonly string $body,
    ) {
    }

    /**
     * @param array{template_key:string, locale?:string, version?:int, subject:string, body:string} $data
     */
    public static function fromInput(array $data): self
    {
        $key = strtolower(trim((string) ($data['template_key'] ?? '')));
        $locale = strtolower(trim((string) ($data['locale'] ?? 'th')));
        $version = (int) ($data['version'] ?? 1);
        $subject = trim((string) ($data['subject'] ?? ''));
        $body = trim((string) ($data['body'] ?? ''));

        if (! preg_match('/^[a-z0-9._-]{3,96}$/', $key)) {
            throw NotificationTemplateException::malformed('A template key must be a 3-96 lowercase identifier');
        }

        if (! preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale)) {
            throw NotificationTemplateException::invalidLocale($locale);
        }

        if ($version < 1 || $version > 65535) {
            throw NotificationTemplateException::malformed('Version must stand in [1, 65535]');
        }

        if ($subject === '' || mb_strlen($subject) > 255) {
            throw NotificationTemplateException::malformed('A subject is required (max 255 chars)');
        }

        if ($body === '') {
            throw NotificationTemplateException::malformed('A body is required');
        }

        return new self($key, $locale, $version, $subject, $body);
    }

    /**
     * Content identity — the immutable fingerprint.
     */
    public function contentFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-notif-tpl', $this->templateKey, $this->locale, (string) $this->version,
            $this->subject, $this->body,
        ]));
    }
}
