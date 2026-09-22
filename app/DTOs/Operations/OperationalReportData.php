<?php

declare(strict_types=1);

namespace App\DTOs\Operations;

use App\Enums\ReportType;
use App\Exceptions\OperationalReportException;
use Illuminate\Support\Carbon;

/**
 * OperationalReportData — a report ask: family + sealed filter
 * blend + time horizon + requester. The same blend = one job row.
 */
final class OperationalReportData
{
    private const MAX_HORIZON_DAYS = 366;

    private const ALLOWED_FILTERS = [
        'financial' => ['currency', 'wallet_kind', 'entry_purpose', 'user_id'],
        'payment' => ['provider', 'method', 'status', 'channel', 'user_id'],
        'draw' => ['draw_id', 'draw_type', 'lifecycle_state', 'winner_only'],
        'prize' => ['draw_id', 'tier', 'claim_status', 'winning_status'],
        'compliance' => ['case_type', 'risk_level', 'status', 'user_id'],
        'player' => ['kyc_status', 'risk_level', 'user_id', 'registered_within'],
        'operational' => ['lane', 'risk_rating', 'auditable_type', 'user_id'],
    ];

    public function __construct(
        public readonly int $requesterUserId,
        public readonly ReportType $reportType,
        public readonly array $filters,
        public readonly Carbon $horizonStart,
        public readonly Carbon $horizonEnd,
    ) {
    }

    /**
     * @param array{requester_user_id:int, report_type:string|ReportType, filters?:array, horizon_start?:string|\DateTimeInterface|null, horizon_end?:string|\DateTimeInterface|null} $data
     */
    public static function fromInput(array $data): self
    {
        $type = $data['report_type'] ?? null;
        if (! $type instanceof ReportType) {
            $type = is_string($type) ? ReportType::tryFrom(strtolower(trim($type))) : null;
        }
        if (! $type instanceof ReportType) {
            throw OperationalReportException::malformed('A valid report type is required');
        }

        $filters = $data['filters'] ?? [];
        if (! is_array($filters)) {
            throw OperationalReportException::malformed('Filters must be a sealed array');
        }

        $allowed = self::ALLOWED_FILTERS[$type->value] ?? [];
        foreach (array_keys($filters) as $key) {
            if (! in_array($key, $allowed, true)) {
                throw OperationalReportException::unsupportedFilter($type->value, (string) $key);
            }
        }
        // Whitelist + string trim: sealed, stable order.
        $sealed = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $value = $filters[$key];
                $sealed[$key] = is_bool($value) ? $value : mb_substr(trim((string) $value), 0, 96);
            }
        }

        $now = Carbon::now();
        $start = self::toCarbon($data['horizon_start'] ?? null) ?? $now->copy()->subDays(30);
        $end = self::toCarbon($data['horizon_end'] ?? null) ?? $now->copy();

        if ($end->lte($start)) {
            throw OperationalReportException::malformed('The horizon end must stand after the start');
        }
        if ($start->diffInDays($end) > self::MAX_HORIZON_DAYS) {
            throw OperationalReportException::malformed('Horizon wider than the desk ceiling of '.self::MAX_HORIZON_DAYS.' days');
        }

        return new self(
            requesterUserId: (int) ($data['requester_user_id'] ?? 0),
            reportType: $type,
            filters: $sealed,
            horizonStart: $start,
            horizonEnd: $end,
        );
    }

    /**
     * Same ask = one generation job row, forever.
     */
    public function queryFingerprint(): string
    {
        return hash('sha256', implode('|', [
            'glo-opreport', (string) $this->requesterUserId, $this->reportType->value,
            json_encode($this->filters),
            $this->horizonStart->toIso8601String(), $this->horizonEnd->toIso8601String(),
        ]));
    }

    /**
     * Whether the ask stands inside the synchronous sound barrier.
     */
    public function withinSyncHorizon(): bool
    {
        return $this->horizonStart->diffInDays($this->horizonEnd) <= $this->reportType->syncHorizonDays();
    }

    private static function toCarbon(string|\DateTimeInterface|null $value): ?Carbon
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }
        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw OperationalReportException::malformed('Horizon edges must parse as timestamps');
        }
    }
}
