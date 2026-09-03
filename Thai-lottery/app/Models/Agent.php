<?php

namespace App\Models;

use App\Enums\AgentStatus;
use App\Enums\Currency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Agent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'agent_code',
        'user_id',
        'parent_agent_id',
        'status',
        'currency',
        'commission_rate',
        'total_referrals',
        'total_commission_earned',
        'total_commission_paid',
        'approved_at',
        'suspended_at',
        'suspension_reason',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => AgentStatus::class,
            'currency' => Currency::class,
            'commission_rate' => 'decimal:4',
            'total_referrals' => 'integer',
            'total_commission_earned' => 'decimal:2',
            'total_commission_paid' => 'decimal:2',
            'approved_at' => 'datetime',
            'suspended_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'parent_agent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Agent::class, 'parent_agent_id');
    }

    public function commissions(): HasMany
    {
        return $this->hasMany(AgentCommission::class);
    }

    public function isActive(): bool
    {
        return $this->status === AgentStatus::Active;
    }

    public function canEarnCommission(): bool
    {
        return $this->status->canOperate();
    }

    public function scopeActive($query)
    {
        return $query->where('status', AgentStatus::Active);
    }
}
