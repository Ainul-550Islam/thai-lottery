<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\DTOs\Notification\NotificationTemplateData;
use App\Enums\NotificationTemplateStatus;
use App\Exceptions\NotificationTemplateException;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;

/**
 * NotificationTemplateService — versioned template registration,
 * activation and retirement. Same content = one immutable row;
 * only ONE (key, locale) version may be Active at a time;
 * retirees and disables refuse new message binding by name.
 */
final class NotificationTemplateService
{
    /**
     * @return array{template: NotificationTemplate, created: bool}
     */
    public function register(NotificationTemplateData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var NotificationTemplate|null $byFingerprint */
            $byFingerprint = NotificationTemplate::query()
                ->where('content_fingerprint', $data->contentFingerprint())
                ->first();

            if ($byFingerprint instanceof NotificationTemplate) {
                if ($byFingerprint->template_key !== $data->templateKey
                    || $byFingerprint->locale !== $data->locale
                    || (int) $byFingerprint->version !== $data->version) {
                    throw NotificationTemplateException::contentFork($data->contentFingerprint());
                }

                return ['template' => $byFingerprint, 'created' => false];
            }

            /** @var NotificationTemplate|null $versionHolder */
            $versionHolder = NotificationTemplate::query()
                ->where('template_key', $data->templateKey)
                ->where('locale', $data->locale)
                ->where('version', $data->version)
                ->lockForUpdate()
                ->first();

            if ($versionHolder instanceof NotificationTemplate) {
                throw NotificationTemplateException::duplicateVersion($data->templateKey, $data->version);
            }

            $template = NotificationTemplate::query()->create([
                'template_key' => $data->templateKey,
                'locale' => $data->locale,
                'version' => $data->version,
                'status' => NotificationTemplateStatus::Draft,
                'subject' => $data->subject,
                'body' => $data->body,
                'content_fingerprint' => $data->contentFingerprint(),
            ]);

            return ['template' => $template, 'created' => true];
        });
    }

    /**
     * ACTIVATE: one (key, locale) winner at a time — the previous
     * holder is disabled in the same seat.
     */
    public function activate(NotificationTemplate $template): NotificationTemplate
    {
        return DB::transaction(function () use ($template): NotificationTemplate {
            /** @var NotificationTemplate $locked */
            $locked = NotificationTemplate::query()->lockForUpdate()->findOrFail($template->id);

            if ($locked->status === NotificationTemplateStatus::Active) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(NotificationTemplateStatus::Active)) {
                throw NotificationTemplateException::notActive($locked->template_key, $locked->status->value);
            }

            NotificationTemplate::query()
                ->where('template_key', $locked->template_key)
                ->where('locale', $locked->locale)
                ->where('status', NotificationTemplateStatus::Active->value)
                ->update(['status' => NotificationTemplateStatus::Disabled->value]);

            $locked->status = NotificationTemplateStatus::Active;
            $locked->activated_at = now();
            $locked->save();

            return $locked->refresh();
        });
    }

    public function disable(NotificationTemplate $template): NotificationTemplate
    {
        return $this->moveTo($template, NotificationTemplateStatus::Disabled);
    }

    public function retire(NotificationTemplate $template): NotificationTemplate
    {
        return $this->moveTo($template, NotificationTemplateStatus::Retired);
    }

    /**
     * Resolve the active (key, locale); falling back to the key's
     * global-subject language lane when a locale-specific fails?
     * NO — specificity rules live with the caller. Strict only.
     */
    public function activeFor(string $templateKey, string $locale): ?NotificationTemplate
    {
        return NotificationTemplate::query()
            ->where('template_key', $templateKey)
            ->where('locale', $locale)
            ->where('status', NotificationTemplateStatus::Active->value)
            ->first();
    }

    private function moveTo(NotificationTemplate $template, NotificationTemplateStatus $target): NotificationTemplate
    {
        return DB::transaction(function () use ($template, $target): NotificationTemplate {
            /** @var NotificationTemplate $locked */
            $locked = NotificationTemplate::query()->lockForUpdate()->findOrFail($template->id);

            if ($locked->status === $target) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw NotificationTemplateException::notFound($locked->template_key);
            }

            $locked->status = $target;
            if ($target === NotificationTemplateStatus::Retired) {
                $locked->retired_at = now();
            }
            $locked->save();

            return $locked->refresh();
        });
    }
}
