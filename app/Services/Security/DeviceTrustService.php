<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\DTOs\Security\DeviceTrustData;
use App\DTOs\Security\SecurityEventData;
use App\Enums\DeviceTrustStatus;
use App\Enums\SecurityEventType;
use App\Exceptions\DeviceTrustException;
use App\Models\TrustedDevice;
use Illuminate\Support\Facades\DB;

/**
 * DeviceTrustService — register / verify / revoke trusted devices
 * with EVIDENCE BINDING: a device becomes trusted only when the
 * verification evidence fingerprint matches what was registered;
 * presenting a different device under a known fingerprint refuses.
 */
final class DeviceTrustService
{
    public function __construct(
        private readonly SecurityEventService $events,
    ) {}

    /**
     * REGISTER: one row per (user, device fingerprint). Re-registering
     * a revoked device opens a fresh Pending era; re-presenting under
     * a DIFFERENT presentation hash is a fork by name.
     */
    public function register(DeviceTrustData $data): TrustedDevice
    {
        return DB::transaction(function () use ($data): TrustedDevice {
            /** @var TrustedDevice|null $existing */
            $existing = TrustedDevice::query()
                ->where('user_id', $data->userId)
                ->where('device_fingerprint', $data->deviceFingerprint)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof TrustedDevice) {
                if ($existing->presentation_hash !== $data->presentationHash) {
                    throw DeviceTrustException::presentationMismatch($data->deviceFingerprint);
                }

                if ($existing->trust_status === DeviceTrustStatus::Revoked) {
                    $existing->trust_status = DeviceTrustStatus::Pending;
                    $existing->evidence_fingerprint = $data->evidenceFingerprint;
                    $existing->revoked_at = null;
                    $existing->revoked_by = null;
                    $existing->revocation_reason = null;
                    $existing->save();
                }

                return $existing;
            }

            $device = TrustedDevice::query()->create([
                'user_id' => $data->userId,
                'device_fingerprint' => $data->deviceFingerprint,
                'trust_status' => DeviceTrustStatus::Pending,
                'presentation_hash' => $data->presentationHash,
                'evidence_fingerprint' => $data->evidenceFingerprint,
                'registered_at' => now(),
            ]);

            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::DeviceRegistered,
                'user_id' => $data->userId,
                'device_fingerprint' => $data->deviceFingerprint,
            ]));

            return $device;
        });
    }

    /**
     * VERIFY: the desk supplies the evidence fingerprint its
     * verification step produced; matching grants trust, mismatch
     * refuses by name — never half-trusts.
     */
    public function verify(DeviceTrustData $data): TrustedDevice
    {
        return DB::transaction(function () use ($data): TrustedDevice {
            /** @var TrustedDevice|null $locked */
            $locked = TrustedDevice::query()
                ->where('user_id', $data->userId)
                ->where('device_fingerprint', $data->deviceFingerprint)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TrustedDevice) {
                throw DeviceTrustException::notFound($data->deviceFingerprint);
            }

            if ($locked->trust_status === DeviceTrustStatus::Trusted) {
                return $locked; // idempotent: verified once stands
            }

            if ($locked->trust_status !== DeviceTrustStatus::Pending) {
                throw DeviceTrustException::revocationConflict($data->deviceFingerprint, $locked->trust_status->value);
            }

            if ($locked->evidence_fingerprint !== $data->evidenceFingerprint) {
                throw DeviceTrustException::evidenceMismatch($data->deviceFingerprint);
            }

            $locked->trust_status = DeviceTrustStatus::Trusted;
            $locked->trusted_at = now();
            $locked->save();

            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::DeviceTrusted,
                'user_id' => $data->userId,
                'device_fingerprint' => $data->deviceFingerprint,
            ]));

            return $locked->refresh();
        });
    }

    /**
     * REVOKE: deliberate, auditable. Re-revoking replays free.
     */
    public function revoke(int $userId, string $deviceFingerprint, string $reason, string $revokedBy): TrustedDevice
    {
        return DB::transaction(function () use ($userId, $deviceFingerprint, $reason, $revokedBy): TrustedDevice {
            /** @var TrustedDevice|null $locked */
            $locked = TrustedDevice::query()
                ->where('user_id', $userId)
                ->where('device_fingerprint', $deviceFingerprint)
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof TrustedDevice) {
                throw DeviceTrustException::notFound($deviceFingerprint);
            }

            if ($locked->trust_status === DeviceTrustStatus::Revoked) {
                return $locked;
            }

            if (! $locked->trust_status->canTransitionTo(DeviceTrustStatus::Revoked)) {
                throw DeviceTrustException::revocationConflict($deviceFingerprint, $locked->trust_status->value);
            }

            $locked->trust_status = DeviceTrustStatus::Revoked;
            $locked->revoked_at = now();
            $locked->revoked_by = $revokedBy;
            $locked->revocation_reason = substr($reason, 0, 128);
            $locked->save();

            $this->events->publish(SecurityEventData::fromInput([
                'event_type' => SecurityEventType::DeviceRevoked,
                'user_id' => $userId,
                'device_fingerprint' => $deviceFingerprint,
                'payload' => ['revoked_by' => $revokedBy],
            ]));

            return $locked->refresh();
        });
    }

    public function trustStateFor(int $userId, string $deviceFingerprint): DeviceTrustStatus
    {
        /** @var TrustedDevice|null $row */
        $row = TrustedDevice::query()
            ->where('user_id', $userId)
            ->where('device_fingerprint', $deviceFingerprint)
            ->first();

        return $row instanceof TrustedDevice ? $row->trust_status : DeviceTrustStatus::Unknown;
    }
}
