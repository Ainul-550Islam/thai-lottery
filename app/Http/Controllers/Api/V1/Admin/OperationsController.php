<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\DTOs\Operations\AdminOperationData;
use App\DTOs\Operations\OperationalReportData;
use App\DTOs\Operations\ProviderOperationData;
use App\DTOs\Operations\ReportExportData;
use App\Exceptions\AdminOperationException;
use App\Exceptions\OperationalReportException;
use App\Exceptions\ProviderOperationException;
use App\Exceptions\ReportExportException;
use App\Http\Responses\ApiResponse;
use App\Jobs\ExecuteAdminOperationJob;
use App\Jobs\GenerateOperationalReportJob;
use App\Models\User;
use App\Services\Operations\AdminAuditQueryService;
use App\Services\Operations\AdminOperationService;
use App\Services\Operations\OperationalReportService;
use App\Services\Operations\ProviderHealthService;
use App\Services\Operations\ProviderOperationService;
use App\Services\Operations\ReportExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OperationsController — the admin command surface: operations
 * lifecycle, provider operational seats, cross-lane report asks,
 * export retrieval metadata, and the read-only audit query view.
 * EVERY route assumes an authenticated admin operator (enforced at
 * route mount); capability floors are asserted per capability.
 */
final class OperationsController
{
    public function __construct(
        private readonly AdminOperationService $operations,
        private readonly ProviderOperationService $providers,
        private readonly ProviderHealthService $health,
        private readonly OperationalReportService $reports,
        private readonly ReportExportService $exports,
        private readonly AdminAuditQueryService $audits,
    ) {
    }

    /** POST /admin/operations — propose an operation (maker). */
    public function proposeOperation(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:40'],
            'target_reference' => ['nullable', 'string', 'max:96'],
            'payload' => ['nullable', 'array'],
            'evidence' => ['nullable', 'string', 'max:2048'],
        ]);

        try {
            AdminOperationService::assertAuthorized($user, 'propose');
            $result = $this->operations->propose(AdminOperationData::fromInput([
                'actor_user_id' => (int) $user->id,
                'type' => $validated['type'],
                'target_reference' => $validated['target_reference'] ?? null,
                'payload' => $validated['payload'] ?? [],
                'evidence' => $validated['evidence'] ?? null,
            ]));
        } catch (AdminOperationException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'operation_fingerprint' => $result['operation']->operation_fingerprint,
            'status' => $result['operation']->status->value,
            'created' => $result['created'],
        ], $result['created'] ? 'Operation proposed.' : 'Operation replayed (already pending).', $result['created'] ? 201 : 200);
    }

    /** GET /admin/operations — the console list. */
    public function listOperations(Request $request): JsonResponse
    {
        AdminOperationService::assertAuthorized($request->user(), 'view');

        $status = $request->query('status');

        return ApiResponse::success([
            'operations' => $this->operations->listFor(is_string($status) ? $status : null)
                ->map(static fn ($op): array => [
                    'id' => $op->id,
                    'operation_fingerprint' => $op->operation_fingerprint,
                    'type' => $op->type->value,
                    'status' => $op->status->value,
                    'target_lane' => $op->target_lane,
                    'target_reference' => $op->target_reference,
                    'actor_user_id' => $op->actor_user_id,
                    'approver_user_id' => $op->approver_user_id,
                    'result_summary' => $op->result_summary,
                    'created_at' => $op->created_at?->toIso8601String(),
                ]),
        ], 'Operations listed.');
    }

    /** POST /admin/operations/{fingerprint}/approve — checker. */
    public function approveOperation(Request $request, string $fingerprint): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $operation = \App\Models\AdminOperation::query()->where('operation_fingerprint', $fingerprint)->firstOrFail();

        try {
            AdminOperationService::assertAuthorized($user, 'approve');
            $row = $this->operations->approve((int) $operation->id, $user, $request->input('note'));
            ExecuteAdminOperationJob::dispatch($fingerprint);
        } catch (AdminOperationException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'operation_fingerprint' => $row->operation_fingerprint,
            'status' => $row->status->value,
            'approver_user_id' => $row->approver_user_id,
        ], 'Operation approved; execution enqueued.');
    }

    /** GET /admin/operations/{fingerprint} — status view. */
    public function showOperation(Request $request, string $fingerprint): JsonResponse
    {
        AdminOperationService::assertAuthorized($request->user(), 'view');

        $operation = \App\Models\AdminOperation::query()->where('operation_fingerprint', $fingerprint)->first();

        if (! $operation instanceof \App\Models\AdminOperation) {
            return ApiResponse::error('adminop_not_found', 'No such operation.', 404);
        }

        return ApiResponse::success([
            'operation_fingerprint' => $operation->operation_fingerprint,
            'type' => $operation->type->value,
            'status' => $operation->status->value,
            'result_summary' => $operation->result_summary,
            'approved_at' => $operation->approved_at?->toIso8601String(),
            'completed_at' => $operation->completed_at?->toIso8601String(),
        ], 'Operation detail.');
    }

    /** PUT /admin/providers/{provider}/state — seat an operational state. */
    public function seatProviderState(Request $request, string $provider): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:available,degraded,suspended,disabled'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            AdminOperationService::assertAuthorized($user, 'approve');
            $row = $this->providers->seatChange(ProviderOperationData::fromInput([
                'provider' => $provider,
                'status' => $validated['status'],
                'changed_by_user_id' => (int) $user->id,
                'note' => $validated['note'] ?? null,
            ]));
        } catch (ProviderOperationException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'change_fingerprint' => $row->change_fingerprint,
            'provider' => $row->provider,
            'status' => $row->status->value,
        ], 'Provider state seated.');
    }

    /** GET /admin/providers/health — desk-wide provider health sheet. */
    public function providerHealth(Request $request): JsonResponse
    {
        AdminOperationService::assertAuthorized($request->user(), 'view');

        return ApiResponse::success([
            'providers' => $this->health->sheet()->map(fn ($row) => $row->toArray())->values(),
        ], 'Provider health sheet.');
    }

    /** POST /admin/reports — ask a report (queued by horizon law). */
    public function askReport(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'report_type' => ['required', 'string', 'in:financial,payment,draw,prize,compliance,player,operational'],
            'filters' => ['nullable', 'array'],
            'horizon_start' => ['nullable', 'date'],
            'horizon_end' => ['nullable', 'date'],
        ]);

        try {
            AdminOperationService::assertAuthorized($user, 'view');
            $ask = OperationalReportData::fromInput([
                'requester_user_id' => (int) $user->id,
                'report_type' => $validated['report_type'],
                'filters' => $validated['filters'] ?? [],
                'horizon_start' => $validated['horizon_start'] ?? null,
                'horizon_end' => $validated['horizon_end'] ?? null,
            ]);
            $result = $this->reports->ask($ask);

            if (! $ask->withinSyncHorizon()) {
                GenerateOperationalReportJob::dispatch($ask->queryFingerprint());
            } else {
                $result['job'] = $this->reports->run($ask->queryFingerprint());
            }
        } catch (OperationalReportException | AdminOperationException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'query_fingerprint' => $result['job']->query_fingerprint,
            'status' => $result['job']->status->value,
            'row_count' => $result['job']->row_count,
        ], $result['created'] ? 'Report registered.' : 'Report replayed (identical ask).');
    }

    /** GET /admin/reports/{queryFingerprint}/export?format=json — render the artifact (metadata lane). */
    public function exportReport(Request $request, string $queryFingerprint): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate(['format' => ['nullable', 'string', 'in:json,csv,xlsx,pdf']]);

        $job = \App\Models\OperationalReportJob::query()->where('query_fingerprint', $queryFingerprint)->firstOrFail();

        try {
            AdminOperationService::assertAuthorized($user, 'view');
            $result = $this->exports->render(ReportExportData::fromInput([
                'report_job_id' => (int) $job->id,
                'format' => $validated['format'] ?? 'json',
            ]));
            /** @var \App\Models\ReportExport $artifact */
            $artifact = $result['export'];
            $retrieved = $this->exports->retrieve($artifact->artifact_fingerprint, (int) $user->id);
        } catch (ReportExportException | AdminOperationException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return response($retrieved['bytes'], 200)
            ->header('Content-Type', $artifact->format->mime())
            ->header('X-Report-Checksum-Sha256', $artifact->checksum)
            ->header('X-Artifact-Fingerprint', $artifact->artifact_fingerprint);
    }

    /** GET /admin/audit-logs — the read-only audit query view. */
    public function auditQuery(Request $request): JsonResponse
    {
        AdminOperationService::assertAuthorized($request->user(), 'view');

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 50);

        try {
            $results = $this->audits->search(
                $request->query->all() + [],
                $page,
                $perPage,
            );
        } catch (AdminOperationException $e) {
            return ApiResponse::error(strtolower($e->errorCode()), $e->getMessage(), 422);
        }

        return ApiResponse::success([
            'audit' => array_map(static fn ($a): array => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'action' => $a->action instanceof \BackedEnum ? $a->action->value : (string) $a->action,
                'auditable_type' => $a->auditable_type,
                'auditable_id' => $a->auditable_id,
                'created_at' => $a->created_at?->toIso8601String(),
            ], $results->items()),
            'page' => $results->currentPage(),
            'total' => $results->total(),
        ], 'Audit results.');
    }
}
