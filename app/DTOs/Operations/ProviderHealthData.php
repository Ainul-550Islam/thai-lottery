<?php

declare(strict_types=1);

namespace App\DTOs\Operations;

/**
 * ProviderHealthData — a NORMALIZED, provider-neutral health
 * snapshot assembled from the desk's own integration boundaries.
 * Read-only evidence; never fabricated telemetry.
 */
final class ProviderHealthData
{
    public function __construct(
        public readonly string $provider,
        public readonly string $operationalStatus,  // desk seat
        public readonly ?string $observedState,      // gateway-integration lane reading (null when none recorded)
        public readonly ?int $failedAttempts24h,
        public readonly ?int $successfulAttempts24h,
        public readonly ?string $lastObservedAt,
    ) {
    }

    /**
     * Whether the desk observed ANY lane traffic in the window.
     */
    public function trafficPresent(): bool
    {
        return ((int) $this->failedAttempts24h + (int) $this->successfulAttempts24h) > 0;
    }

    /**
     * Success rate over the window; null when there was no traffic
     * (the desk reports 'unknown', never an invented 100%).
     */
    public function successRate(): ?float
    {
        $attempts = (int) $this->failedAttempts24h + (int) $this->successfulAttempts24h;
        if ($attempts === 0) {
            return null;
        }

        return round((int) $this->successfulAttempts24h / $attempts, 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'operational_status' => $this->operationalStatus,
            'observed_state' => $this->observedState,
            'failed_attempts_24h' => $this->failedAttempts24h,
            'successful_attempts_24h' => $this->successfulAttempts24h,
            'success_rate_24h' => $this->successRate(),
            'last_observed_at' => $this->lastObservedAt,
            'traffic_present' => $this->trafficPresent(),
        ];
    }
}
