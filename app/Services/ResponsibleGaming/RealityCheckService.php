<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\RealityCheckData;
use App\Enums\RealityCheckStatus;
use App\Exceptions\RealityCheckException;
use App\Models\RealityCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RealityCheckService — schedule / deliver / acknowledge reality
 * checks. Delivery is REPLAY-SAFE by fingerprint; acknowledgement is
 * bound to the session the check belongs to (no cross-session acks);
 * stale acknowledgements are refused by name.
 */
final class RealityCheckService
{
    /**
     * SCHEDULE: deterministic per (user, session, threshold, due).
     *
     * @return array{check: RealityCheck, replayed: bool}
     */
    public function schedule(RealityCheckData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $fingerprint = $data->deliveryFingerprint();

            /** @var RealityCheck|null $existing */
            $existing = RealityCheck::query()->where('delivery_fingerprint', $fingerprint)->first();

            if ($existing instanceof RealityCheck) {
                return ['check' => $existing, 'replayed' => true];
            }

            $dueAt = $data->dueAt;
            $status = now()->gte($data->dueAt) ? RealityCheckStatus::Due : RealityCheckStatus::Scheduled;

            $check = RealityCheck::query()->create([
                'user_id' => $data->userId,
                'status' => $status,
                'session_reference' => $data->sessionReference,
                'threshold_minutes' => $data->thresholdMinutes,
                'due_at' => $dueAt,
                'delivery_fingerprint' => $fingerprint,
                'delivery_channel' => $data->deliveryChannel,
                'expires_at' => $data->expiresAt ?? $data->acknowledgementHorizon(),
            ]);

            return ['check' => $check, 'replayed' => false];
        });
    }

    /**
     * Modal time passage: a Scheduled check whose due moment arrived
     * becomes Due (the delivery sweep's first beat). Idempotent.
     */
    public function promoteDue(int $limit = 200): int
    {
        return RealityCheck::query()
            ->where('status', RealityCheckStatus::Scheduled->value)
            ->where('due_at', '<=', now())
            ->limit($limit)
            ->update(['status' => RealityCheckStatus::Due->value]);
    }

    /**
     * DELIVER: replay-safe — delivering the same due check twice is
     * the same pronouncement; a missed horizon expires instead.
     */
    public function deliver(RealityCheck $check): RealityCheck
    {
        return DB::transaction(function () use ($check): RealityCheck {
            /** @var RealityCheck $locked */
            $locked = RealityCheck::query()->lockForUpdate()->findOrFail($check->id);

            if ($locked->status === RealityCheckStatus::Delivered
                || $locked->status === RealityCheckStatus::Acknowledged) {
                return $locked; // replay: free of arithmetic
            }

            if ($locked->isOverdue()) {
                $this->markExpired($locked);

                return $locked;
            }

            if ($locked->status !== RealityCheckStatus::Due && $locked->status !== RealityCheckStatus::Scheduled) {
                throw RealityCheckException::invalidTransition(
                    (string) $locked->delivery_fingerprint, $locked->status->value, RealityCheckStatus::Delivered->value,
                );
            }

            // A Scheduled check promoted and delivered in one seated act
            // carries its full journey in the row (Due is implied by the
            // due_at moment having passed — never re-written by hand).
            $locked->status = RealityCheckStatus::Delivered;
            $locked->delivered_at = now();
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * ACKNOWLEDGE: session-bound and horizon-bound. The wrong session
     * or a check past its horizon is refused by name.
     */
    public function acknowledge(RealityCheck $check, string $sessionReference): RealityCheck
    {
        $outcome = DB::transaction(function () use ($check, $sessionReference): array {
            /** @var RealityCheck $locked */
            $locked = RealityCheck::query()->lockForUpdate()->findOrFail($check->id);

            if ($locked->session_reference !== $sessionReference) {
                throw RealityCheckException::sessionMismatch($locked->session_reference, $sessionReference);
            }

            if ($locked->status === RealityCheckStatus::Acknowledged) {
                return ['check' => $locked, 'stale' => false]; // replay-safe
            }

            if ($locked->isOverdue()) {
                $this->markExpired($locked);

                return ['check' => $locked->refresh(), 'stale' => true]; // physics committed; refusal pronounced after commit
            }

            if (! $locked->status->requiresAcknowledgement()) {
                throw RealityCheckException::invalidTransition(
                    (string) $locked->delivery_fingerprint, $locked->status->value, RealityCheckStatus::Acknowledged->value,
                );
            }

            $locked->status = RealityCheckStatus::Acknowledged;
            $locked->acknowledged_at = now();
            $locked->acknowledgement_fingerprint = hash('sha256', implode('|', [
                'glo-rc-ack', (string) $locked->delivery_fingerprint, $sessionReference, $locked->acknowledged_at->toIso8601String(),
            ]));
            $locked->save();

            return ['check' => $locked->refresh(), 'stale' => false];
        });

        if ($outcome['stale']) {
            throw RealityCheckException::staleAcknowledgement((string) $outcome['check']->delivery_fingerprint);
        }

        return $outcome['check'];
    }

    /**
     * Expiry sweep: everything overdue by physics. Replay-safe.
     */
    public function expireOverdue(int $limit = 200): int
    {
        $expired = 0;

        RealityCheck::query()
            ->whereIn('status', [RealityCheckStatus::Scheduled->value, RealityCheckStatus::Due->value, RealityCheckStatus::Delivered->value])
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->get()
            ->each(function (RealityCheck $check) use (&$expired): void {
                DB::transaction(function () use ($check, &$expired): void {
                    /** @var RealityCheck|null $locked */
                    $locked = RealityCheck::query()->lockForUpdate()->find($check->id);

                    if (! $locked instanceof RealityCheck || ! $locked->isOverdue()) {
                        return;
                    }

                    $this->markExpired($locked);
                    $expired++;
                });
            });

        return $expired;
    }

    /**
     * @return Collection<int, RealityCheck>
     */
    public function dueForDelivery(int $limit = 200): Collection
    {
        return RealityCheck::query()
            ->whereIn('status', [RealityCheckStatus::Scheduled->value, RealityCheckStatus::Due->value])
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit($limit)
            ->get();
    }

    private function markExpired(RealityCheck $check): void
    {
        $check->status = RealityCheckStatus::Expired;
        $check->save();
    }
}
