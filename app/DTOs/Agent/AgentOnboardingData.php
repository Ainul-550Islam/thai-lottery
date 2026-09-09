<?php

declare(strict_types=1);

namespace App\DTOs\Agent;

use App\Enums\AgentStatus;
use App\Enums\Currency;

/**
 * Immutable DTO carrying agent onboarding parameters.
 */
final class AgentOnboardingData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $userId,
        public readonly ?int $parentAgentId = null,
        public readonly ?string $customAgentCode = null,
        public readonly string $commissionRate = '0.0500',
        public readonly Currency $currency = Currency::THB,
        public readonly bool $autoApprove = true,
        public readonly ?AgentStatus $status = null,
        public readonly array $metadata = [],
    ) {
    }
}
