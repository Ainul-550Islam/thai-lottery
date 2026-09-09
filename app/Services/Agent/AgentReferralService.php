<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentStatus;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service managing user-to-agent referral attribution.
 */
class AgentReferralService
{
    /**
     * Attribute a registered user to an agent using their referral code.
     *
     * @throws FinancialException
     */
    public function attributeUser(User $user, string $agentCode): Agent
    {
        return DB::transaction(function () use ($user, $agentCode): Agent {
            $code = strtoupper(trim($agentCode));

            $agent = Agent::query()->where('agent_code', $code)->first();

            if (! $agent instanceof Agent) {
                throw FinancialException::withCode(
                    'invalid_agent_code',
                    sprintf('Referral code [%s] is not valid or does not exist.', $code),
                    ['agent_code' => $code],
                );
            }

            if (! $agent->canAcceptPlayers()) {
                throw FinancialException::withCode(
                    'agent_not_active',
                    sprintf('Agent [%s] is currently %s and cannot accept new players.', $code, $agent->status->value),
                    ['agent_code' => $code, 'agent_status' => $agent->status->value],
                );
            }

            if ($agent->user_id === (int) $user->getKey()) {
                throw FinancialException::withCode(
                    'self_referral_not_allowed',
                    'A user cannot refer themselves.',
                    ['user_id' => $user->getKey()],
                );
            }

            $preferences = is_array($user->preferences) ? $user->preferences : [];

            // Prevent re-attribution if already assigned
            if (isset($preferences['referred_by_agent_id']) && (int) $preferences['referred_by_agent_id'] === $agent->id) {
                return $agent;
            }

            $preferences['referred_by_agent_id'] = $agent->id;
            $preferences['agent_code'] = $agent->agent_code;
            $preferences['referred_at'] = Carbon::now()->toIso8601String();

            $user->preferences = $preferences;
            $user->save();

            $agent->total_referrals = $agent->total_referrals + 1;
            $agent->save();

            $this->recordAudit(
                action: AuditAction::Update,
                auditable: $user,
                description: sprintf('User #%d (%s) attributed to agent %s (Agent #%d).', $user->id, $user->username, $agent->agent_code, $agent->id),
                metadata: ['agent_id' => $agent->id, 'agent_code' => $agent->agent_code],
            );

            return $agent;
        });
    }

    /**
     * Resolve the active Agent for a given player user.
     *
     * Returns null if the user has no referrer or if the referring agent is inactive/suspended.
     */
    public function resolveAgentForUser(User|int $user): ?Agent
    {
        $userModel = is_int($user) ? User::query()->find($user) : $user;

        if (! $userModel instanceof User) {
            return null;
        }

        $preferences = is_array($userModel->preferences) ? $userModel->preferences : [];
        $agentId = $preferences['referred_by_agent_id'] ?? null;

        if ($agentId === null || ! is_numeric($agentId)) {
            return null;
        }

        $agent = Agent::query()->find((int) $agentId);

        if (! $agent instanceof Agent) {
            return null;
        }

        if (! $agent->canEarnCommission()) {
            return null;
        }

        return $agent;
    }

    private function recordAudit(
        AuditAction $action,
        object $auditable,
        string $description,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
    ): void {
        $log = new AuditLog();
        $log->fill([
            'user_id' => null,
            'action' => $action,
            'risk_level' => RiskLevel::Low,
            'auditable_type' => get_class($auditable),
            'auditable_id' => (int) $auditable->getKey(),
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'metadata' => $metadata,
        ]);
        $log->save();
    }
}
