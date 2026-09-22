<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationTemplateStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One immutable template version. Content never edits: new content =
 * a new version.
 *
 * @property int $id
 * @property string $template_key
 * @property string $locale
 * @property int $version
 * @property NotificationTemplateStatus $status
 * @property string $subject
 * @property string $body
 * @property string $content_fingerprint
 * @property Carbon|null $activated_at
 * @property Carbon|null $retired_at
 */
class NotificationTemplate extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'template_key', 'locale', 'version', 'status',
        'subject', 'body', 'content_fingerprint',
        'activated_at', 'retired_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => NotificationTemplateStatus::class,
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Notification>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'template_id');
    }
}
