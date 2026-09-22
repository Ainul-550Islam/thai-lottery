<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * NotificationTemplateException — template version/state/locale
 * validation failures.
 */
final class NotificationTemplateException extends RuntimeException
{
    public const MALFORMED = 'NOTIFTPL_MALFORMED';
    public const INVALID_LOCALE = 'NOTIFTPL_INVALID_LOCALE';
    public const DUPLICATE_VERSION = 'NOTIFTPL_DUPLICATE_VERSION';
    public const CONTENT_FORK = 'NOTIFTPL_CONTENT_FORK';
    public const NOT_ACTIVE = 'NOTIFTPL_NOT_ACTIVE';
    public const NOT_FOUND = 'NOTIFTPL_NOT_FOUND';

    private function __construct(
        private readonly string $deskCode,
        string $message,
        private readonly array $context = [],
    ) {
        parent::__construct('Notification template refused: '.$message);
    }

    public function errorCode(): string
    {
        return $this->deskCode;
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return $this->context;
    }

    public static function malformed(string $reason): self
    {
        return new self(self::MALFORMED, $reason, ['reason' => $reason]);
    }

    public static function invalidLocale(string $locale): self
    {
        return new self(self::INVALID_LOCALE, sprintf('locale [%s] is not an ISO-2 or xx-XX token', $locale), ['locale' => $locale]);
    }

    public static function duplicateVersion(string $key, int $version): self
    {
        return new self(self::DUPLICATE_VERSION, sprintf('template [%s] version %d already stands with different content', $key, $version), ['template_key' => $key, 'version' => $version]);
    }

    public static function contentFork(string $fingerprint): self
    {
        return new self(self::CONTENT_FORK, sprintf('content [%s] collides with another lane', substr($fingerprint, 0, 12)), ['content_fingerprint' => $fingerprint]);
    }

    public static function notActive(string $key, string $status): self
    {
        return new self(self::NOT_ACTIVE, sprintf('template [%s] in status [%s] accepts no new messages', $key, $status), ['template_key' => $key, 'status' => $status]);
    }

    public static function notFound(string $key): self
    {
        return new self(self::NOT_FOUND, sprintf('template [%s] is unknown', $key), ['template_key' => $key]);
    }
}
