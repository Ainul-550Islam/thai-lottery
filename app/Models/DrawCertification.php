<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DrawCertificationStatus;
use App\Enums\ResultSourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrawCertification extends Model
{
    protected $fillable = [
        'certification_key',
        'draw_id',
        'source_type',
        'result_fingerprint',
        'winning_numbers',
        'certifier_reference',
        'certified_at',
        'superseded_by_key',
        'metadata',
    ];

    protected $casts = [
        'status' => DrawCertificationStatus::class,
        'source_type' => ResultSourceType::class,
        'winning_numbers' => 'array',
        'certified_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(DrawPublication::class);
    }
}
