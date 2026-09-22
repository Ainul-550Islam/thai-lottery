<?php

declare(strict_types=1);

namespace App\Services\ResponsibleGaming;

use App\DTOs\ResponsibleGaming\PlayerProtectionCaseData;
use App\Enums\AuditAction;
use App\Enums\PlayerProtectionAction;
use App\Enums\PlayerProtectionCaseStatus;
use App\Enums\RiskLevel;
use App\Events\PlayerProtectionCaseEscalated;
use App\Exceptions\PlayerProtectionCaseException;
use App\Models\AuditLog;
use App\Models\PlayerProtectionAct;
use App\Models\PlayerProtectionCase;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * PlayerProtectionCaseService — open / monitor / escalate / resolve /
 * close protection cases on immutable evidence. One fact = one live
 * file (deterministic case key, replay for free, fork by name);
 * evidence carried at opening is never rewritten; Closed is one-way;
 * sealing is forbidden while the case's own durable restrictions
 * stand unreleased.
 */
final class PlayerProtectionCaseService
{
    /**
     * @return array{case: PlayerProtectionCase, replayed: bool}
     */
    public function open(PlayerProtectionCaseData $data): array
    {
        return DB::transaction(function () use ($data): array {
            $key = $data->caseKey();

            /** @var PlayerProtectionCase|null $existing */
            $existing = PlayerProtectionCase::query()->where('case_key', $key)->first();

            if ($existing instanceof PlayerProtectionCase) {
                return ['case' => $existing, 'replayed' => true];
            }

            $case = PlayerProtectionCase::query()->create([
                'case_key' => $key,
                'user_id' => $data->userId,
                'status' => PlayerProtectionCaseStatus::Open,
                'trigger_reason' => $data->triggerReason,
                'action_summary' => $data->actionSummary,
                'risk_indicators' => $data->riskIndicators,
                'evidence_fingerprint' => $data->evidenceFingerprint,
                'opened_at' => now(),
            ]);

            $this->audit($case, 'protection case opened', RiskLevel::Medium);

            return ['case' => $case, 'replayed' => false];
        });
    }

    public function monitor(PlayerProtectionCase $case): PlayerProtectionCase
    {
        return $this->move($case, PlayerProtectionCaseStatus::Monitoring, function (PlayerProtectionCase $locked): void {
            $locked->monitoring_since ??= now();
        }, 'case claimed into the watch-state');
    }

    public function escalate(PlayerProtectionCase $case, string $reason, string $escalatedBy): PlayerProtectionCase
    {
        $moved = $this->move($case, PlayerProtectionCaseStatus::Escalated, function (PlayerProtectionCase $locked) use ($reason, $escalatedBy): void {
            $locked->escalated_at = now();
            $locked->escalated_by = $escalatedBy;
            $locked->escalation_reason = substr($reason, 0, 255);
        }, sprintf('case escalated: %s', $reason), RiskLevel::High);

        event(new PlayerProtectionCaseEscalated($moved, $reason, $escalatedBy, now()->toIso8601String()));

        return $moved;
    }

    public function resolve(PlayerProtectionCase $case, string $resolutionNote, int|string $resolvedBy): PlayerProtectionCase
    {
        if (str_word_count($resolutionNote) < 3) {
            throw PlayerProtectionCaseException::missingEvidence('A resolution note must be a full sentence, not a word');
        }

        return $this->move($case, PlayerProtectionCaseStatus::Resolved, function (PlayerProtectionCase $locked) use ($resolutionNote, $resolvedBy): void {
            $locked->resolved_at = now();
            $locked->resolved_by = (string) $resolvedBy;
            $locked->resolution_note = $resolutionNote;
        }, 'case resolved with a full-sentence note', RiskLevel::High);
    }

    /**
     * CLOSE: admins alone; Resolved first; no unreleased durable
     * restriction pronounced by this file may stand at sealing time.
     */
    public function close(PlayerProtectionCase $case, int $closedByUserId): PlayerProtectionCase
    {
        return DB::transaction(function () use ($case, $closedByUserId): PlayerProtectionCase {
            /** @var PlayerProtectionCase $locked */
            $locked = PlayerProtectionCase::query()->lockForUpdate()->findOrFail($case->id);

            if ($locked->status === PlayerProtectionCaseStatus::Closed) {
                return $locked;
            }

            /** @var User|null $closer */
            $closer = User::query()->find($closedByUserId);

            if (! $closer instanceof User || ! $closer->hasRole('admin')) {
                throw PlayerProtectionCaseException::unauthorizedClosure((string) $locked->case_key, $closedByUserId);
            }

            if (! $locked->status->canTransitionTo(PlayerProtectionCaseStatus::Closed)) {
                throw PlayerProtectionCaseException::invalidTransition(
                    (string) $locked->case_key, $locked->status->value, PlayerProtectionCaseStatus::Closed->value,
                );
            }

            $restriction = PlayerProtectionAct::query()
                ->where('case_key', $locked->case_key)
                ->whereIn('action_type', [PlayerProtectionAction::LockAccount->value, PlayerProtectionAction::LowerLimit->value])
                ->where('is_released', false)
                ->exists();

            if ($restriction) {
                throw PlayerProtectionCaseException::unresolvedRestriction((string) $locked->case_key);
            }

            $locked->status = PlayerProtectionCaseStatus::Closed;
            $locked->closed_at = now();
            $locked->closed_by = (string) $closedByUserId;
            $locked->save();

            $this->audit($locked, 'protection file sealed (hold-free, resolved)', RiskLevel::High);

            return $locked->refresh();
        });
    }

    /**
     * @return Collection<int, PlayerProtectionCase>
     */
    public function staleOpen(int $hours, int $limit = 100): Collection
    {
        return PlayerProtectionCase::query()
            ->where('status', PlayerProtectionCaseStatus::Open->value)
            ->where('opened_at', '<=', now()->subHours($hours))
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, PlayerProtectionCase>
     */
    public function staleMonitoring(int $hours, int $limit = 100): Collection
    {
        return PlayerProtectionCase::query()
            ->where('status', PlayerProtectionCaseStatus::Monitoring->value)
            ->where('monitoring_since', '<=', now()->subHours($hours))
            ->limit($limit)
            ->get();
    }

    private function move(PlayerProtectionCase $case, PlayerProtectionCaseStatus $target, callable $stamp, string $note, RiskLevel $risk = RiskLevel::Medium): PlayerProtectionCase
    {
        return DB::transaction(function () use ($case, $target, $stamp, $note, $risk): PlayerProtectionCase {
            /** @var PlayerProtectionCase $locked */
            $locked = PlayerProtectionCase::query()->lockForUpdate()->findOrFail($case->id);

            if ($locked->status === $target) {
                return $locked; // idempotent replay of the same passage
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw PlayerProtectionCaseException::invalidTransition(
                    (string) $locked->case_key, $locked->status->value, $target->value,
                );
            }

            $locked->status = $target;
            $stamp($locked);
            $locked->save();

            $this->audit($locked, $note, $risk);

            return $locked->refresh();
        });
    }

    private function audit(PlayerProtectionCase $case, string $note, RiskLevel $risk): void
    {
        AuditLog::create([
            'user_id' => $case->user_id,
            'action' => AuditAction::Update,
            'auditable_type' => PlayerProtectionCase::class,
            'auditable_id' => $case->id,
            'metadata' => [
                'lane' => 'player-protection-case',
                'risk_rating' => $risk->value,
                'status' => $case->status->value,
                'case_key' => $case->case_key,
                'note' => $note,
            ],
        ]);
    }
}
