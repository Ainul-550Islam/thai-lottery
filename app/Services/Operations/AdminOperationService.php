<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\DTOs\Operations\AdminOperationData;
use App\Enums\AdminOperationStatus;
use App\Enums\AdminOperationType;
use App\Events\AdminOperationCompleted;
use App\Exceptions\AdminOperationException;
use App\Models\AdminOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AdminOperationService — the evidence-backed admin-operation
 * lifecycle. Four-eyes physics are structural: the maker may never
 * check their own ask; only an Approved seat may execute, and an
 * execution is seated by ExecuteAdminOperationJob exactly once,
 * with the result ferried through sanitized summary lanes ONLY.
 * Registered routes call BACK into the desks that own the truth —
 * the operation is a gate, never a shortcut that duplicates rules.
 */
final class AdminOperationService
{
    /**
     * Lanes whose execution is registered here. Registered lanes
     * pronounce THROUGH the owning desks; unregistered lanes refuse
     * by name (EXECUTION_ROUTE_NOT_SET) — never a silent no-op.
     *
     * @var array<string, string>
     */
    private const REGISTERED_LANES = [
        'user_suspend', 'user_reactivate', 'compliance_flag',
    ];

    public function __construct(
        private readonly \App\Listeners\RecordAdminOperationAudit $audit,
    ) {
    }

    /**
     * The capability a seat asks.
     */
    public static function assertAuthorized(User $user, string $capability): void
    {
        $isAdmin = $user->isAdmin() || $user->isSuperAdmin();
        $can = $isAdmin && ($capability === 'propose' || $capability === 'approve' || $capability === 'view');

        if (! $can) {
            throw AdminOperationException::forbidden($capability);
        }
    }

    /**
     * PROPOSE: same ask = one row, forever (replay free).
     */
    public function propose(AdminOperationData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var AdminOperation|null $existing */
            $existing = AdminOperation::query()
                ->where('operation_fingerprint', $data->operationFingerprint())
                ->first();

            if ($existing instanceof AdminOperation) {
                return ['operation' => $existing, 'created' => false];
            }

            $row = AdminOperation::query()->create([
                'operation_fingerprint' => $data->operationFingerprint(),
                'actor_user_id' => $data->actorUserId,
                'type' => $data->type,
                'status' => AdminOperationStatus::Pending,
                'target_lane' => $data->type->lane(),
                'target_reference' => $data->targetReference,
                'payload_fingerprint' => $data->payloadFingerprint(),
                'payload' => $data->payload,
                'evidence_fingerprint' => $data->evidenceFingerprint,
            ]);

            $this->audit->from($row, 'operation proposed');

            return ['operation' => $row, 'created' => true];
        });
    }

    /**
     * APPROVE: checker ≠ maker, and reaffirming a settled seat is
     * refused by name.
     */
    public function approve(int $operationId, User $approver, ?string $note = null): AdminOperation
    {
        return DB::transaction(function () use ($operationId, $approver, $note): AdminOperation {
            /** @var AdminOperation $locked */
            $locked = AdminOperation::query()->lockForUpdate()->findOrFail($operationId);

            if ($locked->status === AdminOperationStatus::Approved
                && (int) $locked->approver_user_id === (int) $approver->id) {
                return $locked; // replay free: same checker, same seat
            }

            if ((int) $locked->actor_user_id === (int) $approver->id) {
                throw AdminOperationException::makerCannotBeChecker($locked->operation_fingerprint);
            }

            if (! $locked->status->canTransitionTo(AdminOperationStatus::Approved)) {
                throw AdminOperationException::terminalOperation($locked->operation_fingerprint, $locked->status->value);
            }

            $locked->status = AdminOperationStatus::Approved;
            $locked->approver_user_id = $approver->id;
            $locked->approval_note = substr((string) $note, 0, 1000) ?: null;
            $locked->approved_at = now();
            $locked->save();

            $this->audit->from($locked, 'operation approved');

            return $locked->refresh();
        });
    }

    /**
     * CANCEL: from a non-terminal seat, with the reason named.
     */
    public function cancel(int $operationId, User $canceller, string $reason): AdminOperation
    {
        return DB::transaction(function () use ($operationId, $canceller, $reason): AdminOperation {
            /** @var AdminOperation $locked */
            $locked = AdminOperation::query()->lockForUpdate()->findOrFail($operationId);

            if ($locked->status === AdminOperationStatus::Cancelled) {
                return $locked;
            }
            if ($locked->status->isTerminal()) {
                throw AdminOperationException::terminalOperation($locked->operation_fingerprint, $locked->status->value);
            }
            if (! $locked->status->canTransitionTo(AdminOperationStatus::Cancelled)) {
                throw AdminOperationException::invalidTransition($locked->operation_fingerprint, $locked->status->value, 'cancelled');
            }

            $locked->status = AdminOperationStatus::Cancelled;
            $locked->denial_reason = substr(trim($reason), 0, 96);
            $locked->cancelled_at = now();
            $locked->save();

            $this->audit->from($locked, 'operation cancelled ('.$locked->denial_reason.')');

            return $locked->refresh();
        });
    }

    /**
     * SEAT "executing" — the job takes the wheel from here.
     */
    public function markExecuting(int $operationId): AdminOperation
    {
        return DB::transaction(function () use ($operationId): AdminOperation {
            /** @var AdminOperation $locked */
            $locked = AdminOperation::query()->lockForUpdate()->findOrFail($operationId);

            if (! $locked->status->acceptsExecution()) {
                if ($locked->status === AdminOperationStatus::Executing || $locked->status->isTerminal()) {
                    return $locked; // idempotent re-seat for the job
                }
                throw AdminOperationException::invalidTransition($locked->operation_fingerprint, $locked->status->value, 'executing');
            }

            $locked->status = AdminOperationStatus::Executing;
            $locked->executed_at = now();
            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * EXECUTE: the owning desk route runs inside this transaction;
     * the outcome seats Completed or Failed by name.
     */
    public function execute(int $operationId): AdminOperation
    {
        return DB::transaction(function () use ($operationId): AdminOperation {
            /** @var AdminOperation $locked */
            $locked = AdminOperation::query()->lockForUpdate()->findOrFail($operationId);

            if ($locked->status === AdminOperationStatus::Completed || $locked->status === AdminOperationStatus::Failed) {
                return $locked; // replay free
            }

            if ($locked->status !== AdminOperationStatus::Executing) {
                $locked = $this->markExecutingInternal($locked);
            }

            try {
                $summary = $this->route($locked);

                $locked->status = AdminOperationStatus::Completed;
                $locked->result_summary = substr((string) $summary, 0, 160);
                $locked->completed_at = now();
                $locked->save();

                $this->audit->from($locked, 'operation completed: '.$summary);
                event(new AdminOperationCompleted($locked));
            } catch (AdminOperationException $e) {
                $locked->status = AdminOperationStatus::Failed;
                $locked->result_summary = substr($e->errorCode(), 0, 160);
                $locked->save();
                $this->audit->from($locked, 'operation failed: '.$e->errorCode());
                event(new AdminOperationCompleted($locked));
            } catch (\Throwable $e) {
                $locked->status = AdminOperationStatus::Failed;
                $locked->result_summary = substr('unexpected failure lane', 0, 160);
                $locked->save();
                $this->audit->from($locked, 'operation failed: unexpected lane');
                event(new AdminOperationCompleted($locked));
            }

            return $locked->refresh();
        });
    }

    private function markExecutingInternal(AdminOperation $locked): AdminOperation
    {
        if (! $locked->status->acceptsExecution()) {
            throw AdminOperationException::invalidTransition($locked->operation_fingerprint, $locked->status->value, 'executing');
        }
        $locked->status = AdminOperationStatus::Executing;
        $locked->executed_at = now();
        $locked->save();

        return $locked;
    }

    /**
     * Route to the owning desk. Routes take their mandatory
     * parameters from the SEALED payload; anything absent fails by
     * name rather than inventing facts.
     */
    private function route(AdminOperation $operation): string
    {
        $type = $operation->type;

        if (! in_array($type->value, self::REGISTERED_LANES, true)) {
            throw AdminOperationException::executionRouteNotSet($type->value);
        }

        return match ($type) {
            AdminOperationType::UserSuspend => $this->executeUserSuspend($operation),
            AdminOperationType::UserReactivate => $this->executeUserReactivate($operation),
            AdminOperationType::ComplianceFlag => $this->executeComplianceFlag($operation),
            default => throw AdminOperationException::executionRouteNotSet($type->value),
        };
    }

    /**
     * user_suspend: seat the account Suspended and sweep every live
     * session through the session desk's own revoke (audit stays
     * exactly-once per session diagnosis).
     */
    private function executeUserSuspend(AdminOperation $operation): string
    {
        $payload = $operation->payload;
        $reason = mb_substr((string) ($payload['reason'] ?? 'admin operation'), 0, 96);

        /** @var \App\Models\User $target */
        $target = \App\Models\User::query()->findOrFail((int) (self::requireInt($payload, 'user_id')));
        $target->status = \App\Enums\UserStatus::Suspended;
        $target->save();

        $swept = app(\App\Services\Security\UserSessionSecurityService::class)
            ->revokeAllSessionsFor((int) $target->id, 'admin operation '.$operation->id, 'system-admin-op');

        return sprintf('user %d suspended, %d session(s) swept (%s)', $target->id, $swept, $reason);
    }

    /**
     * user_reactivate: seat Active only from a Suspended seat —
     * a banned judgement is never quietly lifted by operations.
     */
    private function executeUserReactivate(AdminOperation $operation): string
    {
        $payload = $operation->payload;

        /** @var \App\Models\User $target */
        $target = \App\Models\User::query()->findOrFail((int) self::requireInt($payload, 'user_id'));

        if ($target->status === \App\Enums\UserStatus::Banned) {
            throw AdminOperationException::malformed('a banned account may not be reactivated by operations');
        }

        $target->status = \App\Enums\UserStatus::Active;
        $target->save();

        return sprintf('user %d reactivated', $target->id);
    }

    /**
     * compliance_flag: seal an immutable flag onto audit history —
     * evidence rides as fingerprints/strings, NEVER raw payloads.
     */
    private function executeComplianceFlag(AdminOperation $operation): string
    {
        $reason = mb_substr((string) ($operation->payload['reason'] ?? 'flag'), 0, 96);

        \App\Models\AuditLog::create([
            'user_id' => $operation->actor_user_id,
            'action' => \App\Enums\AuditAction::Update,
            'auditable_type' => AdminOperation::class,
            'auditable_id' => $operation->id,
            'metadata' => [
                'lane' => 'admin-operation',
                'compliance_flag' => true,
                'reason' => $reason,
                'target_reference' => $operation->target_reference,
                'audit_anchor' => 'adminop-flag:'.$operation->operation_fingerprint,
            ],
        ]);

        return 'compliance flag sealed ('.$reason.')';
    }

    private static function requireInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (! is_numeric($value)) {
            throw AdminOperationException::malformed("payload needs a numeric [{$key}]");
        }

        return (int) $value;
    }

    /**
     * Read-side listings for the console.
     *
     * @return \Illuminate\Support\Collection<int, AdminOperation>
     */
    public function listFor(?string $status, int $limit = 50): \Illuminate\Support\Collection
    {
        return AdminOperation::query()
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
