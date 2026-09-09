<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\DTOs\Agent\AgentOnboardingData;
use App\Enums\AgentStatus;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\RiskLevel;
use App\Enums\WalletType;
use App\Exceptions\FinancialException;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Agent onboarding and lifecycle management service.
 */
class AgentOnboardingService
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {
    }

    /**
     * Onboard a user as a registered agent.
     *
     * @throws FinancialException
     */
    public function onboard(AgentOnboardingData $data): Agent
    {
        return DB::transaction(function () use ($data): Agent {
            $user = User::query()->find($data->userId);

            if (! $user instanceof User) {
                throw FinancialException::withCode(
                    'user_not_found',
                    sprintf('User %d does not exist.', $data->userId),
                    ['user_id' => $data->userId],
                );
            }

            if (Agent::query()->where('user_id', $data->userId)->exists()) {
                throw FinancialException::withCode(
                    'agent_already_exists',
                    sprintf('User %d is already an agent.', $data->userId),
                    ['user_id' => $data->userId],
                );
            }

            // Validate parent agent hierarchy
            $parentAgent = null;
            if ($data->parentAgentId !== null) {
                $parentAgent = Agent::query()->find($data->parentAgentId);

                if (! $parentAgent instanceof Agent) {
                    throw FinancialException::withCode(
                        'parent_agent_not_found',
                        sprintf('Parent agent %d does not exist.', $data->parentAgentId),
                        ['parent_agent_id' => $data->parentAgentId],
                    );
                }

                if ($parentAgent->user_id === $data->userId) {
                    throw FinancialException::withCode(
                        'self_referral_not_allowed',
                        'An agent cannot be their own parent agent.',
                        ['user_id' => $data->userId],
                    );
                }

                $maxDepth = (int) $this->config->get('agent.hierarchy.max_depth', 2);
                $depth = $this->calculateHierarchyDepth($parentAgent);

                if ($depth >= $maxDepth) {
                    throw FinancialException::withCode(
                        'max_hierarchy_depth_exceeded',
                        sprintf('Hierarchy depth limit exceeded: cannot exceed maximum depth of %d levels.', $maxDepth),
                        ['current_depth' => $depth, 'max_depth' => $maxDepth],
                    );
                }
            }

            // Generate or validate unique agent referral code
            $agentCode = $data->customAgentCode !== null && trim($data->customAgentCode) !== ''
                ? strtoupper(trim($data->customAgentCode))
                : $this->generateUniqueAgentCode();

            if (Agent::query()->where('agent_code', $agentCode)->exists()) {
                throw FinancialException::withCode(
                    'agent_code_already_exists',
                    sprintf('Agent code [%s] is already taken.', $agentCode),
                    ['agent_code' => $agentCode],
                );
            }

            $rate = $data->commissionRate;
            $maxRate = (string) $this->config->get('agent.commission.max_rate', '0.1000');

            if (bccomp($rate, '0.0000', 4) < 0 || bccomp($rate, $maxRate, 4) > 0) {
                throw FinancialException::withCode(
                    'invalid_commission_rate',
                    sprintf('Commission rate must be between 0.0000 and %s.', $maxRate),
                    ['commission_rate' => $rate, 'max_rate' => $maxRate],
                );
            }

            $status = $data->status ?? ($data->autoApprove ? AgentStatus::Active : AgentStatus::Inactive);

            $agent = new Agent();
            $agent->fill([
                'agent_code' => $agentCode,
                'user_id' => $data->userId,
                'parent_agent_id' => $data->parentAgentId,
                'status' => $status,
                'currency' => $data->currency,
                'commission_rate' => $rate,
                'total_referrals' => 0,
                'total_commission_earned' => '0.00',
                'total_commission_paid' => '0.00',
                'approved_at' => $status === AgentStatus::Active ? Carbon::now() : null,
                'metadata' => $data->metadata,
            ]);
            $agent->save();

            // Assign role if Spatie roles are initialized
            if (class_exists(Role::class) && Role::where('name', 'agent')->exists()) {
                $user->assignRole('agent');
            }

            // Ensure agent has a primary wallet in the designated currency
            $this->ensureAgentWallet($user, $data->currency);

            $this->recordAudit(
                action: AuditAction::Create,
                auditable: $agent,
                description: sprintf('Agent %s (Code: %s) onboarded for user #%d with rate %s.', $user->name, $agentCode, $user->id, $rate),
                metadata: ['agent_code' => $agentCode, 'user_id' => $user->id, 'rate' => $rate],
            );

            return $agent;
        });
    }

    /**
     * Approve a pending agent.
     */
    public function approve(Agent $agent, ?int $approverUserId = null): Agent
    {
        return DB::transaction(function () use ($agent, $approverUserId): Agent {
            $agent->status = AgentStatus::Active;
            $agent->approved_at = Carbon::now();
            $metadata = is_array($agent->metadata) ? $agent->metadata : [];
            $metadata['approved_by'] = $approverUserId;
            $agent->metadata = $metadata;
            $agent->save();

            $this->recordAudit(
                action: AuditAction::RoleAssign,
                auditable: $agent,
                description: sprintf('Agent %s (Code: %s) approved.', $agent->agent_code, $agent->agent_code),
                oldValues: ['status' => 'inactive'],
                newValues: ['status' => 'active'],
            );

            return $agent;
        });
    }

    /**
     * Suspend an active agent.
     */
    public function suspend(Agent $agent, string $reason, ?int $actorUserId = null): Agent
    {
        return DB::transaction(function () use ($agent, $reason, $actorUserId): Agent {
            $agent->status = AgentStatus::Suspended;
            $agent->suspended_at = Carbon::now();
            $agent->suspension_reason = $reason;
            $metadata = is_array($agent->metadata) ? $agent->metadata : [];
            $metadata['suspended_by'] = $actorUserId;
            $agent->metadata = $metadata;
            $agent->save();

            // Cascade suspension to child agents if configured
            if ((bool) $this->config->get('agent.hierarchy.cascade_suspension_to_children', true)) {
                $children = Agent::query()->where('parent_agent_id', $agent->id)->get();
                foreach ($children as $child) {
                    $child->status = AgentStatus::Suspended;
                    $child->suspended_at = Carbon::now();
                    $child->suspension_reason = 'Parent agent suspended: '.$reason;
                    $child->save();
                }
            }

            $this->recordAudit(
                action: AuditAction::SecurityAlert,
                auditable: $agent,
                description: sprintf('Agent %s suspended: %s', $agent->agent_code, $reason),
                oldValues: ['status' => 'active'],
                newValues: ['status' => 'suspended'],
                metadata: ['reason' => $reason],
            );

            return $agent;
        });
    }

    /**
     * Terminate an agent permanently.
     */
    public function terminate(Agent $agent, string $reason, ?int $actorUserId = null): Agent
    {
        return DB::transaction(function () use ($agent, $reason, $actorUserId): Agent {
            $agent->status = AgentStatus::Terminated;
            $metadata = is_array($agent->metadata) ? $agent->metadata : [];
            $metadata['terminated_by'] = $actorUserId;
            $metadata['termination_reason'] = $reason;
            $metadata['terminated_at'] = Carbon::now()->toIso8601String();
            $agent->metadata = $metadata;
            $agent->save();

            $this->recordAudit(
                action: AuditAction::SecurityAlert,
                auditable: $agent,
                description: sprintf('Agent %s terminated: %s', $agent->agent_code, $reason),
                newValues: ['status' => 'terminated'],
                metadata: ['reason' => $reason],
            );

            return $agent;
        });
    }

    /**
     * Activate a suspended or inactive agent.
     */
    public function activate(Agent $agent): Agent
    {
        return DB::transaction(function () use ($agent): Agent {
            $agent->status = AgentStatus::Active;
            $agent->suspended_at = null;
            $agent->suspension_reason = null;
            $agent->save();

            return $agent;
        });
    }

    /**
     * Generate a guaranteed unique collision-resistant agent referral code.
     */
    public function generateUniqueAgentCode(): string
    {
        $prefix = (string) $this->config->get('agent.lifecycle.code_prefix', 'AG');
        $length = (int) $this->config->get('agent.lifecycle.code_length', 8);
        $randomBytesLength = max(3, (int) ceil(($length - strlen($prefix)) / 2));

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = strtoupper($prefix.substr(bin2hex(random_bytes($randomBytesLength)), 0, $length - strlen($prefix)));

            if (! Agent::withTrashed()->where('agent_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Failed to generate unique agent referral code.');
    }

    /**
     * Calculate depth of an agent in the hierarchy (Root = 1, Sub-agent = 2, etc.).
     */
    public function calculateHierarchyDepth(Agent $agent): int
    {
        $depth = 1;
        $current = $agent;

        while ($current->parent_agent_id !== null && $depth < 10) {
            $parent = Agent::query()->find($current->parent_agent_id);
            if (! $parent instanceof Agent) {
                break;
            }
            $depth++;
            $current = $parent;
        }

        return $depth;
    }

    /**
     * Ensure the agent has a wallet to receive commission payouts.
     */
    private function ensureAgentWallet(User $user, Currency $currency): Wallet
    {
        $wallet = Wallet::query()
            ->where('user_id', $user->id)
            ->where('currency', $currency->value)
            ->first();

        if (! $wallet instanceof Wallet) {
            $wallet = new Wallet();
            $wallet->user_id = $user->id;
            $wallet->type = WalletType::Primary;
            $wallet->currency = $currency;
            $wallet->balance = '0.00';
            $wallet->locked_balance = '0.00';
            $wallet->total_deposited = '0.00';
            $wallet->total_withdrawn = '0.00';
            $wallet->total_wagered = '0.00';
            $wallet->total_won = '0.00';
            $wallet->status = \App\Enums\WalletStatus::Active;
            $wallet->save();
        }

        return $wallet;
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
