<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportJobStatus;
use App\Enums\ReportType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Exactly-once report generation request; same ask = same row.
 *
 * @property int $id
 * @property string $query_fingerprint
 * @property int $requester_user_id
 * @property ReportType $report_type
 * @property array $filters
 * @property ReportJobStatus $status
 * @property Carbon|null $horizon_start
 * @property Carbon|null $horizon_end
 * @property int|null $row_count
 * @property string|null $failure_reason
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $expires_at
 */
class OperationalReportJob extends Model
{
    protected $fillable = [
        'query_fingerprint', 'requester_user_id', 'report_type', 'filters',
        'status', 'horizon_start', 'horizon_end', 'row_count',
        'failure_reason', 'started_at', 'completed_at', 'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'filters' => 'array',
            'status' => ReportJobStatus::class,
            'horizon_start' => 'datetime',
            'horizon_end' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, self>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    /**
     * @return HasMany<ReportExport>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(ReportExport::class, 'report_job_id');
    }

    public function isPastHorizon(): bool
    {
        return now()->gte($this->expires_at);
    }
}
