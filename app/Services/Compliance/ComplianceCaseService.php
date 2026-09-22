<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\DTOs\Compliance\ComplianceCaseData;
use App\Enums\AmlRiskLevel;
use App\Enums\AuditAction;
use App\Enums\ComplianceActionType;
use App\Enums\ComplianceCaseStatus;
use App\Enums\RiskLevel;
use App\Events\ComplianceCaseEscalated;
use App\Exceptions\ComplianceCaseException;
use App\Models\AuditLog;
use App\Models\ComplianceAction;
use App\Models\ComplianceCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Open / investigate / escalate / resolve / close compliance cases
 * with immutable evidence and strict state guards.
 *
 * THE LAW OF THIS LANE
 *   1. ONE LIVE FILE per fact — the deterministic case key anchors a
 *      live conversation: re-opening identical facts replays; same
 *      key, different facts is a fork cried loudly.
 *   2. EVIDENCE OR SMOKE — every open/transition carries an evidence
 *      fingerprint or reference; nothing moves w/o paper.
 *   3. ESCALATION IS AN EVENT, ALWAYS — every escalation emits
 *      ComplianceCaseEscalated (immutable) inside the same
 *      transaction, so the desk-log and the envelope agree.
 *   4. CLOSURE IS JUDGED — only admins close, only a Resolved file
 *      may close, and NEVER while an unreleased compliance hold
 *      applies money to the subject.
 */
final class ComplianceCaseService
{
    /* --------------------------------------------------- open ----- */

    /**
     * @return array{case: ComplianceCase, replayed: bool}
     *
     * @throws ComplianceCaseException
     */
    public function open(ComplianceCaseData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var User|null $subject */
            $subject = User::query()->find($data->subjectUserId);

            if (! $subject instanceof User) {
                throw ComplianceCaseException::notFound('subject:'.$data->subjectUserId);
            }

            /** @var ComplianceCase|null $existing */
            $existing = ComplianceCase::query()
                ->lockForUpdate()
                ->where('case_key', $data->caseKey())
                ->first();

            if ($existing instanceof ComplianceCase) {
                $existingLevel = $existing->risk_level instanceof AmlRiskLevel ? $existing->risk_level->value : (string) $existing->risk_level;

                $factsMatch = (int) $existing->subject_user_id === $data->subjectUserId
                    && (string) $existing->case_type === $data->caseType
                    && (string) $existing->trigger_reference === $data->triggerReference
                    && $existingLevel === $data->riskLevel->value;

                if (! $factsMatch) {
                    throw ComplianceCaseException::duplicate($data->caseKey());
                }

                return ['case' => $existing, 'replayed' => true];
            }

            $row = new ComplianceCase;
            $row->fill([
                'case_key' => $data->caseKey(),
                'subject_user_id' => $data->subjectUserId,
                'case_type' => $data->caseType,
                'trigger_reference' => $data->triggerReference,
                'evidence_fingerprint' => $data->evidenceFingerprint,
                'assigned_desk' => $data->assignedDesk,
                'metadata' => [],
            ]);
            $row->risk_level = $data->riskLevel;
            $row->status = ComplianceCaseStatus::Open;
            $row->save();

            $this->recordAudit($row, sprintf(
                'Opened [%s] case against user #%d at risk %s (trigger %s)',
                $data->caseType,
                $data->subjectUserId,
                $data->riskLevel->value,
                $data->triggerReference,
            ));

            return ['case' => $row, 'replayed' => false];
        });
    }

    /* -------------------------------------------- investigate ----- */

    /**
     * @throws ComplianceCaseException
     */
    public function investigate(ComplianceCase $case): ComplianceCase
    {
        return $this->move($case, ComplianceCaseStatus::Investigating, null, static function (ComplianceCase $locked): void {
            $locked->investigating_since = $locked->investigating_since ?? now();
        });
    }

    /* ---------------------------------------------- escalate ------ */

    /**
     * Move the file to Escalated with a full-sentence reason AND emit
     * the immutable escalation event in the same transaction.
     *
     * @throws ComplianceCaseException
     */
    public function escalate(ComplianceCase $case, string $reason, ?string $source = 'desk'): ComplianceCase
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 8) {
            throw ComplianceCaseException::malformed('an escalation reason must be a full sentence (8+ characters)');
        }

        return DB::transaction(function () use ($case, $reason, $source): ComplianceCase {
            /** @var ComplianceCase|null $locked */
            $locked = ComplianceCase::query()->lockForUpdate()->find((int) $case->getKey());

            if (! $locked instanceof ComplianceCase) {
                throw ComplianceCaseException::notFound((string) $case->case_key);
            }

            if ($locked->status === ComplianceCaseStatus::Escalated) {
                return $locked; // replay
            }

            if (! $locked->status->canTransitionTo(ComplianceCaseStatus::Escalated)) {
                throw ComplianceCaseException::invalidTransition((string) $locked->case_key, $locked->status->value, ComplianceCaseStatus::Escalated->value);
            }

            $locked->status = ComplianceCaseStatus::Escalated;
            $locked->escalated_at = now();
            $locked->escalation_reason = Str::limit($reason, 255, '');
            $locked->save();

            $this->recordAudit($locked, sprintf('Escalated: %s', $reason), RiskLevel::High);

            // THE IMMUTABLE ESCALATION ENVELOPE — same transaction.
            event(new ComplianceCaseEscalated($locked, $reason, (string) $source, $locked->escalated_at?->toIso8601String() ?? now()->toIso8601String()));

            return $locked;
        });
    }

    /* ---------------------------------------------- resolve ------- */

    /**
     * @throws ComplianceCaseException
     */
    public function resolve(ComplianceCase $case, string $note, int $operatorUserId): ComplianceCase
    {
        $note = trim($note);

        if (mb_strlen($note) < 8) {
            throw ComplianceCaseException::malformed('a resolution note must be a full sentence (8+ characters)');
        }

        return $this->move($case, ComplianceCaseStatus::Resolved, null, static function (ComplianceCase $locked) use ($note, $operatorUserId): void {
            $locked->resolution_note = Str::limit($note, 255, '');
            $locked->resolved_at = $locked->resolved_at ?? now();
            $locked->resolved_by = $locked->resolved_by ?? $operatorUserId;
        });
    }

    /* ------------------------------------------------ close ------- */

    /**
     * Seal the file. Admin-authority required by name; only a Resolved
     * file may close; never while an unreleased control hold remains.
     *
     * @throws ComplianceCaseException
     */
    public function close(ComplianceCase $case, int $operatorUserId): ComplianceCase
    {
        return DB::transaction(function () use ($case, $operatorUserId): ComplianceCase {
            /** @var User|null $operator */
            $operator = User::query()->find($operatorUserId);

            if (! $operator instanceof User || ! $operator->isAdmin()) {
                throw ComplianceCaseException::unauthorizedClosure($operatorUserId);
            }

            /** @var ComplianceCase|null $locked */
            $locked = ComplianceCase::query()->lockForUpdate()->find((int) $case->getKey());

            if (! $locked instanceof ComplianceCase) {
                throw ComplianceCaseException::notFound((string) $case->case_key);
            }

            if ($locked->status === ComplianceCaseStatus::Closed) {
                return $locked; // replay
            }

            if (! $locked->status->canTransitionTo(ComplianceCaseStatus::Closed)) {
                throw ComplianceCaseException::invalidTransition((string) $locked->case_key, $locked->status->value, ComplianceCaseStatus::Closed->value);
            }

            $activeHold = ComplianceAction::query()
                ->where('case_id', (int) $locked->id)
                ->whereIn('action_type', [ComplianceActionType::Restrict->value, ComplianceActionType::Hold->value])
                ->where('is_released', false)
                ->exists();

            if ($activeHold) {
                throw ComplianceCaseException::holdActive((string) $locked->case_key);
            }

            $locked->status = ComplianceCaseStatus::Closed;
            $locked->closed_at = now();
            $locked->closed_by = $operatorUserId;
            $locked->save();

            $this->recordAudit($locked, sprintf('Closed by #%d', $operatorUserId), RiskLevel::High);

            return $locked;
        });
    }

    /* ------------------------------------------------ internals ---- */

    /**
     * @throws ComplianceCaseException
     */
    private function move(ComplianceCase $case, ComplianceCaseStatus $target, ?string $unused, callable $stamp): ComplianceCase
    {
        return DB::transaction(function () use ($case, $target, $stamp): ComplianceCase {
            /** @var ComplianceCase|null $locked */
            $locked = ComplianceCase::query()->lockForUpdate()->find((int) $case->getKey());

            if (! $locked instanceof ComplianceCase) {
                throw ComplianceCaseException::notFound((string) $case->case_key);
            }

            if ($locked->status === $target) {
                return $locked; // replay
            }

            if (! $locked->status->canTransitionTo($target)) {
                throw ComplianceCaseException::invalidTransition((string) $locked->case_key, $locked->status->value, $target->value);
            }

            $locked->status = $target;
            $stamp($locked);
            $locked->save();

            $this->recordAudit($locked, sprintf('Moved → %s', $target->value));

            return $locked;
        });
    }

    private function recordAudit(ComplianceCase $case, string $description, RiskLevel $riskLevel = RiskLevel::Medium): void
    {
        $log = new AuditLog;

        $log->fill([
            'user_id' => (int) $case->subject_user_id,
            'action' => AuditAction::Update,
            'risk_level' => $riskLevel,
            'auditable_type' => ComplianceCase::class,
            'auditable_id' => (int) $case->getKey(),
            'description' => sprintf('%s (case %s...)', $description, substr((string) $case->case_key, 0, 12)),
            'metadata' => [
                'case_key_prefix' => substr((string) $case->case_key, 0, 16),
                'case_type' => (string) $case->case_type,
                'risk_level' => $case->risk_level instanceof AmlRiskLevel ? $case->risk_level->value : (string) $case->risk_level,
                'lane' => 'compliance-case',
            ],
        ]);

        $log->save();
    }
}
