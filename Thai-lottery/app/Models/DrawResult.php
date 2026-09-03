<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DrawResult extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'draw_id',
        'first_prize',
        'second_prize',
        'third_prize',
        'consolation_prizes',
        'all_numbers',
        'total_winners',
        'total_payout',
        'house_profit',
        'published_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'first_prize' => 'string',
            'second_prize' => 'array',
            'third_prize' => 'array',
            'consolation_prizes' => 'array',
            'all_numbers' => 'array',
            'total_winners' => 'integer',
            'total_payout' => 'decimal:2',
            'house_profit' => 'decimal:2',
            'published_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function winningNumbers(): HasMany
    {
        return $this->hasMany(WinningNumber::class, 'draw_id', 'draw_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
