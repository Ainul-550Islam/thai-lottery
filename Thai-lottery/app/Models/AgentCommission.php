<?php

namespace App\Models;

use App\Enums\CommissionStatus;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentCommission extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference_number',
        'agent_id',
        'user_id',
        'bet_id',
        'draw_id',
        'financial_transaction_id',
        'status',
        'currency',
        'base_amount',
        'commission_rate',
        'commission_amount',
        'accrued_at',
        'paid_at',
        'reversed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommissionStatus::class,
            'currency' => Currency::class,
            'base_amount' => 'decimal:2',
            'commission_rate' => 'decimal:4',
            'commission_amount' => 'decimal:2',
            'accrued_at' => 'datetime',
            'paid_at' => 'datetime',
            'reversed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bet(): BelongsTo
    {
        return $this->belongsTo(Bet::class);
    }

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(FinancialTransaction::class);
    }

    public function isPaid(): bool
    {
        return $this->status === CommissionStatus::Paid;
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function scopePayable($query)
    {
        return $query->where('status', CommissionStatus::Payable);
    }

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('agent_id', $agentId);
    }
}
