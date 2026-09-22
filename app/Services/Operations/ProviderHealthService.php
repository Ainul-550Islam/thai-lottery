<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\DTOs\Operations\ProviderHealthData;
use App\Enums\ProviderOperationStatus;
use App\Models\Payment;
use App\Models\PaymentProvider;
use App\Models\PaymentWebhook;

/**
 * ProviderHealthService — provider-NEUTRAL health aggregation from
 * lanes that already carry the truth (payment transactions, payment
 * webhooks, provider operation seats). Nothing is fabricated: when
 * a lane carries no window traffic the desk says unknown, never a
 * flattering rate.
 */
final class ProviderHealthService
{
    public function __construct(
        private readonly ProviderOperationService $operations,
    ) {
    }

    /**
     * Every provider the desk has ever observed (transaction lanes +
     * webhook lanes + operation seats).
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public function knownProviders(): \Illuminate\Support\Collection
    {
        $lanes = collect();
        $lanes = $lanes->merge(PaymentProvider::query()->orderBy('code')->pluck('code'));
        $lanes = $lanes->merge(Payment::query()->whereNotNull('gateway')->distinct()->orderBy('gateway')->pluck('gateway'));
        $lanes = $lanes->merge(PaymentWebhook::query()->distinct()->orderBy('provider')->pluck('provider'));
        $lanes = $lanes->merge(\App\Models\ProviderOperation::query()->distinct()->orderBy('provider')->pluck('provider'));

        return $lanes->filter()->unique()->sort()->values();
    }

    /**
     * One provider's current snapshot.
     */
    public function snapshot(string $provider): ProviderHealthData
    {
        $seat = $this->operations->currentStatus($provider) ?? ProviderOperationStatus::Available;

        $windowStart = now()->subDay();
        $traffic = Payment::query()
            ->where('gateway', $provider)
            ->where('created_at', '>=', $windowStart)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $succeeded = (int) ($traffic['captured'] ?? 0) + (int) ($traffic['authorized'] ?? 0);
        $failed = (int) ($traffic['failed'] ?? 0) + (int) ($traffic['cancelled'] ?? 0);

        $lastWebhook = PaymentWebhook::query()
            ->where('provider', $provider)
            ->orderByDesc('created_at')
            ->value('created_at');

        $lastTx = Payment::query()
            ->where('gateway', $provider)
            ->orderByDesc('created_at')
            ->value('created_at');

        $lastObserved = collect([$lastWebhook, $lastTx])->filter()->map(fn ($t) => (string) $t)->sort()->last();

        return new ProviderHealthData(
            provider: $provider,
            operationalStatus: $seat->value,
            observedState: $lastObserved !== null ? 'traffic-observed' : null,
            failedAttempts24h: $failed,
            successfulAttempts24h: $succeeded,
            lastObservedAt: $lastObserved,
        );
    }

    /**
     * The desk-wide health sheet — one row per known provider.
     *
     * @return \Illuminate\Support\Collection<int, ProviderHealthData>
     */
    public function sheet(): \Illuminate\Support\Collection
    {
        return $this->knownProviders()
            ->map(fn (string $provider): ProviderHealthData => $this->snapshot($provider));
    }
}
