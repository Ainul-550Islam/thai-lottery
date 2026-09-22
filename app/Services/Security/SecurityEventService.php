<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\Security\SecurityEventData;
use App\Enums\SecurityRiskLevel;
use App\Models\SecurityEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SecurityEventService — exactly-once security event persistence:
 * same events land on one row by fingerprint, fingerprints only.
 */
final class SecurityEventService
{
    /**
     * PUBLISH: deterministic persistence. Returns what exists.
     *
     * @return array{event: SecurityEvent, persisted: bool}
     */
    public function publish(SecurityEventData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $fingerprint = $data->eventFingerprint();

            /** @var SecurityEvent|null $existing */
            $existing = SecurityEvent::query()->where('event_fingerprint', $fingerprint)->first();

            if ($existing instanceof SecurityEvent) {
                return ['event' => $existing, 'persisted' => false];
            }

            $event = SecurityEvent::query()->create([
                'event_fingerprint' => $fingerprint,
                'event_type' => $data->eventType,
                'user_id' => $data->userId,
                'risk_level' => $data->riskLevel,
                'ip_address' => $data->ipAddress,
                'device_fingerprint' => $data->deviceFingerprint,
                'session_fingerprint' => $data->sessionFingerprint,
                'payload' => $data->payload === [] ? null : $data->payload,
                'occurred_at' => $data->occurredAt,
            ]);

            return ['event' => $event, 'persisted' => true];
        });
    }

    /**
     * @return Collection<int, SecurityEvent>
     */
    public function unreviewedAtOrAbove(SecurityRiskLevel $floor, int $limit = 100): Collection
    {
        return SecurityEvent::query()
            ->where('reviewed', false)
            ->orderBy('occurred_at')
            ->get()
            ->filter(fn (SecurityEvent $e) => $e->risk_level->severity() >= $floor->severity())
            ->take($limit)
            ->values();
    }
}
