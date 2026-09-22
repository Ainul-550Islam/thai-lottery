<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\DTOs\Operations\OperationalReportData;
use App\Enums\ReportJobStatus;
use App\Enums\ReportType;
use App\Exceptions\OperationalReportException;
use App\Models\AdminOperation;
use App\Models\AuditLog;
use App\Models\ComplianceCase;
use App\Models\Draw;
use App\Models\LedgerEntry;
use App\Models\OperationalReportJob;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * OperationalReportService — READ-ONLY cross-lane reporting from
 * lanes that own the truth. Rows are projections of desk tables,
 * timestamps pinned, and identical asks (same requester + blend)
 * seal into one generation job row, forever. Nothing here mutates
 * the desks it reads.
 */
final class OperationalReportService
{
    private const MAX_ROWS = 50000;

    /**
     * ASK: same ask = one job row (replay free).
     */
    public function ask(OperationalReportData $data): array
    {
        return DB::transaction(function () use ($data): array {
            /** @var OperationalReportJob|null $existing */
            $existing = OperationalReportJob::query()
                ->where('query_fingerprint', $data->queryFingerprint())
                ->first();

            if ($existing instanceof OperationalReportJob) {
                return ['job' => $existing, 'created' => false];
            }

            $row = OperationalReportJob::query()->create([
                'query_fingerprint' => $data->queryFingerprint(),
                'requester_user_id' => $data->requesterUserId,
                'report_type' => $data->reportType,
                'filters' => $data->filters,
                'status' => ReportJobStatus::Queued,
                'horizon_start' => $data->horizonStart,
                'horizon_end' => $data->horizonEnd,
                'expires_at' => now()->addHours(\App\DTOs\Operations\ReportExportData::RETENTION_HOURS),
            ]);

            return ['job' => $row, 'created' => true];
        });
    }

    /**
     * RUN a queued job: gathers rows from the lane projections and
     * seats Completed (the rows themselves travel to the export
     * lane for deterministic rendering — the job records counts).
     */
    public function run(string $queryFingerprint): OperationalReportJob
    {
        return DB::transaction(function () use ($queryFingerprint): OperationalReportJob {
            /** @var OperationalReportJob $locked */
            $locked = OperationalReportJob::query()->lockForUpdate()
                ->where('query_fingerprint', $queryFingerprint)
                ->first();

            if (! $locked instanceof OperationalReportJob) {
                throw OperationalReportException::notFound(substr($queryFingerprint, 0, 12));
            }

            if ($locked->status === ReportJobStatus::Completed) {
                return $locked;
            }

            if ($locked->status !== ReportJobStatus::Queued && $locked->status !== ReportJobStatus::Running) {
                throw OperationalReportException::terminalJob($queryFingerprint, $locked->status->value);
            }

            $locked->status = ReportJobStatus::Running;
            $locked->started_at = now();
            $locked->save();

            try {
                $rows = $this->rows($locked);
                $locked->row_count = $rows->count();
                $locked->status = ReportJobStatus::Completed;
                $locked->completed_at = now();
                $locked->save();
            } catch (\Throwable $e) {
                $locked->status = ReportJobStatus::Failed;
                $locked->failure_reason = substr('generation lane: '.$e->getMessage(), 0, 96);
                $locked->save();
                throw OperationalReportException::generationFailure($locked->failure_reason);
            }

            return $locked->refresh();
        });
    }

    /**
     * THE PROJECTIONS — read-only rows per report family. Every
     * where clause is additive and deterministic in output.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function rows(OperationalReportJob $job): \Illuminate\Support\Collection
    {
        $filters = $job->filters;

        return match ($job->report_type) {
            ReportType::Financial => $this->financialRows($job, $filters),
            ReportType::Payment => $this->paymentRows($job, $filters),
            ReportType::Draw => $this->drawRows($job, $filters),
            ReportType::Prize => $this->prizeRows($job, $filters),
            ReportType::Compliance => $this->complianceRows($job, $filters),
            ReportType::Player => $this->playerRows($job, $filters),
            ReportType::Operational => $this->operationalRows($job, $filters),
        };
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function financialRows(OperationalReportJob $job, array $filters): \Illuminate\Support\Collection
    {
        return LedgerEntry::query()
            ->whereBetween('posted_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['entry_purpose']), fn ($q) => $q->where('metadata->purpose', $filters['entry_purpose']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('wallet_id', 'like', '%%'))
            ->orderBy('posted_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (LedgerEntry $e): array => [
                'id' => $e->id,
                'type' => $e->type,
                'amount' => $e->amount,
                'currency' => $e->currency,
                'reference_type' => $e->reference_type,
                'posted_at' => $e->posted_at?->toIso8601String(),
            ]);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function paymentRows(OperationalReportJob $job, array $filters): \Illuminate\Support\Collection
    {
        return Payment::query()
            ->whereBetween('created_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['provider']), fn ($q) => $q->where('gateway', $filters['provider']))
            ->when(isset($filters['method']), fn ($q) => $q->where('method', $filters['method']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Payment $p): array => [
                'reference_number' => $p->reference_number,
                'user_id' => $p->user_id,
                'method' => $p->method,
                'status' => $p->status,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'gateway' => $p->gateway,
                'created_at' => $p->created_at?->toIso8601String(),
            ]);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function drawRows(OperationalReportJob $job, array $filters): \Illuminate\Support\Collection
    {
        return Draw::query()
            ->whereBetween('scheduled_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['draw_id']), fn ($q) => $q->where('id', (int) $filters['draw_id']))
            ->when(isset($filters['draw_type']), fn ($q) => $q->where('type', $filters['draw_type']))
            ->orderBy('draw_number')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Draw $d): array => [
                'draw_number' => $d->draw_number,
                'type' => $d->type,
                'status' => $d->status,
                'scheduled_at' => $d->scheduled_at?->toIso8601String(),
                'total_bets' => $d->total_bets,
            ]);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function prizeRows(OperationalReportJob $job, array $filters): \Illuminate\Support\Collection
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('prize_claims')) {
            return collect();
        }

        return collect();
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function complianceRows(OperationalReportJob $job, array $filters): \Illuminate\Support\Collection
    {
        return ComplianceCase::query()
            ->whereBetween('created_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['case_type']), fn ($q) => $q->where('case_type', $filters['case_type']))
            ->when(isset($filters['risk_level']), fn ($q) => $q->where('risk_level', $filters['risk_level']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('subject_user_id', (int) $filters['user_id']))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (ComplianceCase $c): array => [
                'case_key' => $c->case_key,
                'subject_user_id' => $c->subject_user_id,
                'case_type' => $c->case_type,
                'risk_level' => $c->risk_level,
                'status' => $c->status,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function playerRows(OperationalReportJob $job, array $filters): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereBetween('created_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['user_id']), fn ($q) => $q->where('id', (int) $filters['user_id']))
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'username' => $u->username,
                'status' => $u->status->value,
                'created_at' => $u->created_at?->toIso8601String(),
            ]);
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function operationalRows(OperationalReportJob $job, array $filters): \Illuminate\Support\Collection
    {
        return AuditLog::query()
            ->whereBetween('created_at', [$job->horizon_start, $job->horizon_end])
            ->when(isset($filters['auditable_type']), fn ($q) => $q->where('auditable_type', $filters['auditable_type']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('user_id', (int) $filters['user_id']))
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (AuditLog $a): array => [
                'id' => $a->id,
                'user_id' => $a->user_id,
                'action' => $a->action instanceof \BackedEnum ? $a->action->value : (string) $a->action,
                'auditable_type' => $a->auditable_type,
                'created_at' => $a->created_at?->toIso8601String(),
            ]);
    }

    /**
     * EXPIRE horizon-passed jobs whose artifacts aged out.
     */
    public function expireStale(int $limit = 200): int
    {
        $expired = 0;

        OperationalReportJob::query()
            ->whereIn('status', [ReportJobStatus::Queued->value, ReportJobStatus::Running->value, ReportJobStatus::Completed->value])
            ->where('expires_at', '<=', now())
            ->limit($limit)
            ->get()
            ->each(function (OperationalReportJob $job) use (&$expired): void {
                DB::transaction(function () use ($job, &$expired): void {
                    /** @var OperationalReportJob $locked */
                    $locked = OperationalReportJob::query()->lockForUpdate()->findOrFail($job->id);
                    if ($locked->status->isTerminal()) {
                        return;
                    }
                    $locked->status = ReportJobStatus::Expired;
                    $locked->save();
                    $expired++;
                });
            });

        return $expired;
    }
}
