<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DrawPublicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrawPublication extends Model
{
    protected $fillable = [
        'publication_key',
        'draw_id',
        'draw_certification_id',
        'version',
        'result_fingerprint',
        'published_at',
        'retracted_at',
        'metadata',
    ];

    protected $casts = [
        'status' => DrawPublicationStatus::class,
        'published_at' => 'datetime',
        'retracted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function certification(): BelongsTo
    {
        return $this->belongsTo(DrawCertification::class, 'draw_certification_id');
    }
}
