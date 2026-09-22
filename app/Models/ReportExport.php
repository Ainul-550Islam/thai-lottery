<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sealed export artifact: checksum-bound, expiry-bound.
 *
 * @property int $id
 * @property string $artifact_fingerprint
 * @property int $report_job_id
 * @property ReportFormat $format
 * @property string $status           ready | expired
 * @property string $checksum
 * @property int $byte_size
 * @property string $storage_path
 * @property int $row_count
 * @property Carbon $expires_at
 */
class ReportExport extends Model
{
    protected $fillable = [
        'artifact_fingerprint', 'report_job_id', 'format', 'status',
        'checksum', 'byte_size', 'storage_path', 'row_count', 'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => ReportFormat::class,
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OperationalReportJob, self>
     */
    public function reportJob(): BelongsTo
    {
        return $this->belongsTo(OperationalReportJob::class, 'report_job_id');
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || now()->gte($this->expires_at);
    }
}
