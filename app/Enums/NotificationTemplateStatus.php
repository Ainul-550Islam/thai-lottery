<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * NotificationTemplateStatus — template version lifecycle.
 *
 * A version is born Draft, activated at most one-per(lane,locale)
 * at a time, disabled out of service with new registrants refused,
 * and retired when retired with all READS kept (messages rendered
 * of it remain pronounceable of it forever — versions are content
 * history, never hidden).
 */
enum NotificationTemplateStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Disabled = 'disabled';
    case Retired = 'retired';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Retired],
            self::Active => [self::Disabled, self::Retired],
            self::Disabled => [self::Active, self::Retired],
            self::Retired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether new message enqueues may bind to this version.
     */
    public function acceptsEnqueues(): bool
    {
        return $this === self::Active;
    }
}
