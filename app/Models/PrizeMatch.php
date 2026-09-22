<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PrizeMatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrizeMatch extends Model
{
    protected $fillable = [
        'match_key',
        'draw_id',
        'bet_id',
        'ticket_id',
        'prize_tier',
        'matched_amount',
        'result_fingerprint',
        'verified_at',
        'rejected_at',
        'rejected_reason',
        'metadata',
    ];

    protected $casts = [
        'status' => PrizeMatchStatus::class,
        'matched_amount' => 'decimal:2',
        'verified_at' => 'datetime',
        'rejected_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
