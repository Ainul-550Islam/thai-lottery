<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\DTOs\Operations\ProviderOperationData;
use App\Enums\ProviderOperationStatus;
use App\Events\ProviderOperationalStateChanged;
use App\Exceptions\ProviderOperationException;
use App\Models\ProviderOperation;
use Illuminate\Support\Facades\DB;

/**
 * ProviderOperationService — provider operational state seats over
 * an IMMUTABLE ledger of changes. One provider carries exactly one
 * current seat; every seat change needs evidence; the same change
 * lands exactly once; the event that flies carries sanitized
 * evidence only (fingerprints, statuses, timestamps — no secrets,
 * no credentials, no raw provider payloads).
 */
final class ProviderOperationService
{
    public function __construct(
        private readonly \App\Listeners\RecordProviderOperationAudit $audit,
    ) {
    }

    /**
     * The current operational seat for a provider (null = never
     * touched; the default reading is Available-per-config but the
     * ledger speaks iff asked).
     */
    public function currentStatus(string $provider): ?ProviderOperationStatus
    {
        $latest = $this->latestChange($provider);

        return $latest instanceof ProviderOperation ? $latest->status : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ProviderOperation>
     */
    public function ledgerFor(string $provider, int $limit = 50): \Illuminate\Support\Collection
    {
        return ProviderOperation::query()
            ->where('provider', $provider)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * SEAT a state change: exactly-once by changeFingerprint,
     * evidence always (operator note IS the operator evidence seat;
     * health evidence rides separately), no-op re-seats refuse by
     * name.
     */
    public function seatChange(ProviderOperationData $data): ProviderOperation
    {
        return DB::transaction(function () use ($data) {
            /** @var ProviderOperation|null $existing */
            $existing = ProviderOperation::query()
                ->where('change_fingerprint', $data->changeFingerprint())
                ->first();

            if ($existing instanceof ProviderOperation) {
                return $existing; // replay free
            }

            $current = $this->currentStatus($data->provider);

            if ($current === $data->status) {
                throw ProviderOperationException::stateAlreadySeated($data->provider, $data->status->value);
            }

            $row = ProviderOperation::query()->create([
                'change_fingerprint' => $data->changeFingerprint(),
                'provider' => $data->provider,
                'status' => $data->status,
                'changed_by_user_id' => $data->changedByUserId,
                'note' => $data->note,
                'evidence_fingerprint' => hash('sha256', implode('|', ['glo-provop-ev', $data->provider, $data->status->value, (string) $data->note])),
                'effective_at' => now(),
            ]);

            $this->audit->from($row, 'provider state seated: '.$data->status->value);
            event(new ProviderOperationalStateChanged($row, $current?->value));

            return $row->refresh();
        });
    }

    private function latestChange(string $provider): ?ProviderOperation
    {
        return ProviderOperation::query()
            ->where('provider', $provider)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }
}
