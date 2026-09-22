<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\SelfExclusionData;
use App\Enums\AuditAction;
use App\Enums\RiskLevel;
use App\Enums\SelfExclusionStatus;
use App\Events\SelfExclusionActivated;
use App\Exceptions\SelfExclusionException;
use App\Listeners\RecordSelfExclusionAudit;
use App\Models\AuditLog;
use App\Models\ResponsibleGamingLimit;
use App\Models\SelfExclusion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SelfExclusionService — create / activate / expire self-exclusions.
 * The live gate is DERIVED server-side (status + server clock) and
 * blocks prohibited account activity; no client may lift it.
 *
 * THE TWO LANES CORRESPOND: the legacy per-user
 * `responsible_gaming_limits.self_excluded_until` row is stamped at
 * activation/expiry so pre-existing gates (`User::isSelfExcluded`,
 * PrizeEligibilityService) keep telling the same truth — the
 * pronouncement lane stays authoritative, never edited outside here.
 */
final class SelfExclusionService
{
    public function __construct(
        private readonly RecordSelfExclusionAudit $audit,
    ) {}

    /**
     * THE SEATED REQUEST: one (user, scope, reason, window) = one
     * row, replayed for free. A PLAYER under an active exclusion
     * cannot re-request (the gate refuses by name).
     *
     * @return array{exclusion: SelfExclusion, replayed: bool}
     */
    public function request(SelfExclusionData $data): SelfExclusion
    {
        return DB::transaction(function () use ($data): SelfExclusion {
            $fingerprint = $data->requestFingerprint();

            /** @var SelfExclusion|null $existing */
            $existing = SelfExclusion::query()->where('request_fingerprint', $fingerprint)->first();

            if ($existing instanceof SelfExclusion) {
                return $existing; // deterministic replay, free of arithmetic
            }

            /** @var SelfExclusion|null $active */
            $active = SelfExclusion::query()
                ->where('user_id', $data->userId)
                ->where('status', SelfExclusionStatus::Active->value)
                ->lockForUpdate()
                ->get()
                ->first(fn (SelfExclusion $s) => $s->currentlyGates());

            if ($active instanceof SelfExclusion) {
                throw SelfExclusionException::activeExclusion($data->userId, $active->ends_at->toIso8601String());
            }

            $row = SelfExclusion::query()->create([
                'user_id' => $data->userId,
                'status' => SelfExclusionStatus::Requested,
                'scope' => $data->scope,
                'reason_code' => $data->reasonCode,
                'request_fingerprint' => $fingerprint,
                'effective_at' => $data->effectiveAt,
                'ends_at' => $data->endsAt,
            ]);

            return $row;
        });
    }

    /**
     * ACTIVATE: immediate-effect exclusions move Requested → Active
     * the moment the server clock crosses effective_at. The
     * SelfExclusionActivated envelope flies before the transaction
     * commits; the legacy row is stamped in the same seat.
     */
    public function activate(int|SelfExclusion $exclusion): SelfExclusion
    {
        return DB::transaction(function () use ($exclusion): SelfExclusion {
            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail(
                $exclusion instanceof SelfExclusion ? $exclusion->id : $exclusion,
            );

            if ($locked->status === SelfExclusionStatus::Active) {
                return $locked; // idempotent claim
            }

            if (! $locked->status->canTransitionTo(SelfExclusionStatus::Active)) {
                throw SelfExclusionException::invalidTransition(
                    self::ref($locked), $locked->status->value, SelfExclusionStatus::Active->value,
                );
            }

            $locked->status = SelfExclusionStatus::Active;
            $locked->activated_at = now();
            $locked->save();

            $this->stampLegacyRow($locked);

            event(new SelfExclusionActivated($locked, $locked->activated_at->toIso8601String()));

            return $locked->refresh();
        });
    }

    /**
     * EXPIRE: the only gate-lifter — server-authoritative end time
     * must have physically passed. Replay-safe.
     */
    public function expire(SelfExclusion $exclusion): SelfExclusion
    {
        return DB::transaction(function () use ($exclusion): SelfExclusion {
            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail($exclusion->id);

            if ($locked->status === SelfExclusionStatus::Expired) {
                return $locked;
            }

            if ($locked->status !== SelfExclusionStatus::Active) {
                return $locked; // never-active requests do not lapse this lane
            }

            if (now()->lt($locked->ends_at)) {
                // End time not reached — physics alone decides; fail-closed.
                throw SelfExclusionException::invalidTransition(
                    self::ref($locked), SelfExclusionStatus::Active->value, SelfExclusionStatus::Expired->value.' (end time not reached)',
                );
            }

            $locked->status = SelfExclusionStatus::Expired;
            $locked->expired_at = now();
            $locked->save();

            $this->stampLegacyRow($locked);
            $this->audit->from($locked, 'self-exclusion expired by server horizon');

            return $locked->refresh();
        });
    }

    /**
     * CANCEL: allowed only while the exclusion was never live —
     * cancelling an ACTIVE gate is forbidden by name.
     */
    public function cancel(SelfExclusion $exclusion, string $cancelledBy): SelfExclusion
    {
        return DB::transaction(function () use ($exclusion, $cancelledBy): SelfExclusion {
            /** @var SelfExclusion $locked */
            $locked = SelfExclusion::query()->lockForUpdate()->findOrFail($exclusion->id);

            if ($locked->status === SelfExclusionStatus::Cancelled) {
                return $locked;
            }

            if (! $locked->status->canTransitionTo(SelfExclusionStatus::Cancelled)) {
                throw SelfExclusionException::forbiddenCancellation(self::ref($locked), $locked->status->value);
            }

            $locked->status = SelfExclusionStatus::Cancelled;
            $locked->cancelled_at = now();
            $locked->cancelled_by = $cancelledBy;
            $locked->save();

            $this->audit->from($locked, 'self-exclusion request withdrawn pre-activation');

            return $locked->refresh();
        });
    }

    /**
     * THE FAIL-CLOSED GATE: the player is excluded iff an ACTIVE
     * exclusion whose end is in the future exists. Derived anew at
     * every call; never a stored flag.
     */
    public function hasActiveExclusion(int $userId): bool
    {
        return SelfExclusion::query()
            ->where('user_id', $userId)
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '>', now())
            ->exists();
    }

    public function currentActiveFor(int $userId): ?SelfExclusion
    {
        /** @var SelfExclusion|null $active */
        $active = SelfExclusion::query()
            ->where('user_id', $userId)
            ->where('status', SelfExclusionStatus::Active->value)
            ->orderByDesc('ends_at')
            ->first();

        return $active instanceof SelfExclusion && $active->currentlyGates() ? $active : null;
    }

    /**
     * Pending requests whose effective moment the server clock has
     * crossed — the activation sweep reads this page by page.
     *
     * @return Collection<int, SelfExclusion>
     */
    public function dueForActivation(int $limit = 100): Collection
    {
        return SelfExclusion::query()
            ->where('status', SelfExclusionStatus::Requested->value)
            ->where('effective_at', '<=', now())
            ->orderBy('effective_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, SelfExclusion>
     */
    public function dueForExpiry(int $limit = 100): Collection
    {
        return SelfExclusion::query()
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '<=', now())
            ->orderBy('ends_at')
            ->limit($limit)
            ->get();
    }

    /**
     * The two lanes agree: legacy row holds excluded-until for the
     * active lane, and is cleared (never shortened) on expiry/cancel.
     */
    private function stampLegacyRow(SelfExclusion $exclusion): void
    {
        /** @var ResponsibleGamingLimit|null $legacy */
        $legacy = ResponsibleGamingLimit::query()->where('user_id', $exclusion->user_id)->lockForUpdate()->first();

        if (! $legacy instanceof ResponsibleGamingLimit) {
            $legacy = new ResponsibleGamingLimit;
            $legacy->user_id = $exclusion->user_id;
        }

        if ($exclusion->status === SelfExclusionStatus::Active) {
            // The new lane is authoritative: extend to match unless the
            // legacy gate already runs LONGER (never silently shorten).
            $current = $legacy->self_excluded_until;

            if (! $current instanceof Carbon || $current->lt($exclusion->ends_at)) {
                $legacy->self_excluded_until = $exclusion->ends_at;
            }
        } else {
            $current = $legacy->self_excluded_until;

            if ($current instanceof Carbon && ! $current->isFuture()) {
                $legacy->self_excluded_until = null;
            }
        }

        $legacy->save();

        AuditLog::create([
            'user_id' => $exclusion->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => SelfExclusion::class,
            'auditable_id' => $exclusion->id,
            'metadata' => [
                'lane' => 'self-exclusion',
                'risk_rating' => RiskLevel::High->value,
                'status' => $exclusion->status->value,
                'reference' => self::ref($exclusion),
            ],
        ]);
    }

    private static function ref(SelfExclusion $exclusion): string
    {
        return 'SX-'.strtoupper(substr((string) $exclusion->request_fingerprint, 0, 12));
    }
}
