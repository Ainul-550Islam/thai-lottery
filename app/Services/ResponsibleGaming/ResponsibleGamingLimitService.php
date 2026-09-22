<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\ResponsibleGamingLimitData;
use App\Enums\AuditAction;
use App\Enums\ResponsibleGamingLimitStatus;
use App\Enums\ResponsibleGamingLimitType;
use App\Enums\RiskLevel;
use App\Enums\SelfExclusionStatus;
use App\Exceptions\ResponsibleGamingLimitException;
use App\Exceptions\SelfExclusionException;
use App\Models\AuditLog;
use App\Models\ResponsibleGamingLimitVersion;
use App\Models\SelfExclusion;
use Illuminate\Support\Facades\DB;

/**
 * ResponsibleGamingLimitService — create / replace / activate /
 * expire limit VERSIONS with exact bcmath comparisons and
 * stricter-limit precedence:
 *
 * - A DECREASE binds at once (player's choice to be safer).
 * - AN INCREASE, or a brand-new loosening, waits out the reserve
 *   cool-off (24h) as a Pending version; it activates only when the
 *   sweep seats it after the horizon and it still agrees with the
 *   then-current ceiling (fork-checked).
 * - Versions are never edited: Active → Replaced | Expired;
 *   Pending → Active | Cancelled. The desk's audit stamps every
 *   passage, by anchor, exactly once per act.
 */
final class ResponsibleGamingLimitService
{
    /** Increases cool off for 24 hours before they may bind. */
    private const COOLING_OFF_HOURS = 24;

    /**
     * PRONOUNCE a new version. Deterministic by limit key: re-issuing
     * the same pronunciation replays against the same row. Lowering
     * takes effect NOW; raising waits the cooling-off horizon.
     *
     * @return array{version: ResponsibleGamingLimitVersion, replayed: bool}
     */
    public function pronounce(ResponsibleGamingLimitData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $key = $data->limitKey();

            /** @var ResponsibleGamingLimitVersion|null $existing */
            $existing = ResponsibleGamingLimitVersion::query()->where('limit_key', $key)->first();

            if ($existing instanceof ResponsibleGamingLimitVersion) {
                return ['version' => $existing, 'replayed' => true];
            }

            /** @var ResponsibleGamingLimitVersion|null $active */
            $active = ResponsibleGamingLimitVersion::query()
                ->where('user_id', $data->userId)
                ->where('limit_type', $data->type->value)
                ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
                ->lockForUpdate()
                ->first();

            /** @var ResponsibleGamingLimitVersion|null $pending */
            $pending = ResponsibleGamingLimitVersion::query()
                ->where('user_id', $data->userId)
                ->where('limit_type', $data->type->value)
                ->where('limit_status', ResponsibleGamingLimitStatus::Pending->value)
                ->lockForUpdate()
                ->first();

            // Stricter precedence: while ANY version is pending on this
            // (user, type), a second pronunciation must not dodge the
            // cooling-off of the first. Two Pending twins = fork.
            if ($pending instanceof ResponsibleGamingLimitVersion) {
                throw ResponsibleGamingLimitException::cooldownViolation(
                    $data->type->value,
                    $pending->effective_from?->toIso8601String() ?? 'unset',
                );
            }

            // Self-exclusion is the master's voice: limits MUST NOT be
            // raised while an exclusion stands active (the gate itself).
            if ($active instanceof ResponsibleGamingLimitVersion
                && bccomp($data->amount, (string) $active->amount, 2) > 0) {
                $exclusion = SelfExclusion::query()
                    ->where('user_id', $data->userId)
                    ->where('status', SelfExclusionStatus::Active->value)
                    ->where('ends_at', '>', now())
                    ->first();

                if ($exclusion !== null) {
                    throw SelfExclusionException::activeExclusion($data->userId, (string) $exclusion->ends_at->toIso8601String());
                }
            }

            $isStricter = $active === null || bccomp($data->amount, (string) $active->amount, 2) <= 0;

            $version = new ResponsibleGamingLimitVersion;
            $version->fill([
                'user_id' => $data->userId,
                'limit_type' => $data->type,
                'amount' => $data->amount,
                'currency' => $data->currency,
                'limit_key' => $key,
                'limit_status' => $isStricter ? ResponsibleGamingLimitStatus::Active : ResponsibleGamingLimitStatus::Pending,
                'effective_from' => $isStricter ? now() : now()->addHours(self::COOLING_OFF_HOURS),
                'effective_to' => $data->effectiveTo,
                'activated_at' => $isStricter ? now() : null,
            ]);
            $version->save();

            if ($isStricter && $active instanceof ResponsibleGamingLimitVersion) {
                $this->markReplaced($active, $version);
            }

            $this->scribble($version, sprintf(
                '%s %s limit pronounced (%s)',
                $isStricter ? 'stricter' : 'pending increase',
                $data->type->value,
                $data->amount,
            ));

            return ['version' => $version, 'replayed' => false];
        });
    }

    /**
     * The sweep: every Pending increase whose cooling-off has run its
     * hour, and which still out-runs the then-current ceiling, seats
     * Active (its ancestor Replaced in the same breath). A pending
     * increase that no longer beats the ceiling becomes Cancelled —
     * the purpose of the horizon is exactly this question.
     */
    public function activateDuePending(int $limit = 100): int
    {
        $seated = 0;

        ResponsibleGamingLimitVersion::query()
            ->where('limit_status', ResponsibleGamingLimitStatus::Pending->value)
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->limit($limit)
            ->get()
            ->each(function (ResponsibleGamingLimitVersion $pending) use (&$seated): void {
                DB::transaction(function () use ($pending, &$seated): void {
                    /** @var ResponsibleGamingLimitVersion|null $locked */
                    $locked = ResponsibleGamingLimitVersion::query()->lockForUpdate()->find($pending->id);

                    if (! $locked instanceof ResponsibleGamingLimitVersion
                        || $locked->limit_status !== ResponsibleGamingLimitStatus::Pending) {
                        return;
                    }

                    /** @var ResponsibleGamingLimitVersion|null $ceiling */
                    $ceiling = ResponsibleGamingLimitVersion::query()
                        ->where('user_id', $locked->user_id)
                        ->where('limit_type', $locked->limit_type->value)
                        ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
                        ->lockForUpdate()
                        ->first();

                    if ($ceiling instanceof ResponsibleGamingLimitVersion
                        && bccomp((string) $locked->amount, (string) $ceiling->amount, 2) <= 0) {
                        $locked->limit_status = ResponsibleGamingLimitStatus::Cancelled;
                        $locked->cancelled_at = now();
                        $locked->save();
                        $this->scribble($locked, 'pending increase cancelled — no longer looser than the active ceiling');

                        return;
                    }

                    if ($ceiling instanceof ResponsibleGamingLimitVersion) {
                        $this->markReplaced($ceiling, $locked);
                    }

                    $locked->limit_status = ResponsibleGamingLimitStatus::Active;
                    $locked->activated_at = now();
                    $locked->save();
                    $this->scribble($locked, 'pending increase seated after cooling-off');
                    $seated++;
                });
            });

        return $seated;
    }

    /**
     * Expire versions whose effective horizon physically ended.
     */
    public function expireStale(int $limit = 100): int
    {
        $expired = 0;

        ResponsibleGamingLimitVersion::query()
            ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
            ->whereNotNull('effective_to')
            ->where('effective_to', '<=', now())
            ->orderBy('effective_to')
            ->limit($limit)
            ->get()
            ->each(function (ResponsibleGamingLimitVersion $v) use (&$expired): void {
                DB::transaction(function () use ($v, &$expired): void {
                    /** @var ResponsibleGamingLimitVersion|null $locked */
                    $locked = ResponsibleGamingLimitVersion::query()->lockForUpdate()->find($v->id);

                    if (! $locked instanceof ResponsibleGamingLimitVersion
                        || $locked->limit_status !== ResponsibleGamingLimitStatus::Active) {
                        return;
                    }

                    $locked->limit_status = ResponsibleGamingLimitStatus::Expired;
                    $locked->expired_at = now();
                    $locked->save();
                    $this->scribble($locked, 'limit version expired by server horizon');
                    $expired++;
                });
            });

        return $expired;
    }

    /**
     * The player's binding ceiling per type = the LOWEST amount among
     * Active versions on file (should never be two, but the safer
     * reads alone) — exact bcmath comparison, never float math.
     */
    public function bindingLimitFor(int $userId, ResponsibleGamingLimitType $type): ?ResponsibleGamingLimitVersion
    {
        return ResponsibleGamingLimitVersion::query()
            ->where('user_id', $userId)
            ->where('limit_type', $type->value)
            ->where('limit_status', ResponsibleGamingLimitStatus::Active->value)
            ->orderBy('amount')
            ->first();
    }

    /**
     * THE INLINE SCRIBE: one row per pronouncement passage, anchored
     * against the limit key + note so replays add no rows.
     */
    private function scribble(ResponsibleGamingLimitVersion $version, string $note): void
    {
        $anchor = 'rgl-audit:'.(string) $version->limit_key.':'.$version->limit_status->value.':'.$note;

        $exists = AuditLog::query()
            ->where('auditable_type', ResponsibleGamingLimitVersion::class)
            ->where('created_at', '>=', now()->subHours(48))
            ->whereJsonContains('metadata->audit_anchor', $anchor)
            ->exists();

        if ($exists) {
            return;
        }

        AuditLog::create([
            'user_id' => $version->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => ResponsibleGamingLimitVersion::class,
            'auditable_id' => $version->id,
            'metadata' => [
                'lane' => 'responsible-gaming-limit',
                'risk_rating' => RiskLevel::Medium->value,
                'limit_key' => $version->limit_key,
                'limit_type' => $version->limit_type->value,
                'limit_status' => $version->limit_status->value,
                'audit_anchor' => $anchor,
                'note' => $note,
            ],
        ]);
    }

    private function markReplaced(ResponsibleGamingLimitVersion $old, ResponsibleGamingLimitVersion $new): void
    {
        $old->limit_status = ResponsibleGamingLimitStatus::Replaced;
        $old->replaced_by_key = $new->limit_key;
        $old->save();
        $this->scribble($old, sprintf('superseded by [%s]', substr($new->limit_key, 0, 12)));
    }
}
