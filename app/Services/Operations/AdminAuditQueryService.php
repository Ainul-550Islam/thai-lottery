<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Enums\AuditAction;
use App\Exceptions\AdminOperationException;
use App\Models\AuditLog;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * AdminAuditQueryService — READ-ONLY operational audit/search view.
 * It never mutates history, its clauses are a tight whitelist, and
 * answers are paged > the console lane can't drain the desk's
 * memory in one breath.
 */
final class AdminAuditQueryService
{
    private const MAX_PER_PAGE = 100;

    /**
     * Field → clause whitelist. Anything unknown refuses by name.
     */
    public function search(array $query, int $page, int $perPage): LengthAwarePaginator
    {
        $builder = AuditLog::query();

        foreach ($query as $field => $value) {
            match ($field) {
                'user_id' => $builder->where('user_id', (int) $value),
                'action' => $this->applyAction($builder, (string) $value),
                'auditable_type' => $builder->where('auditable_type', (string) $value),
                'auditable_id' => $builder->where('auditable_id', (int) $value),
                'on_or_after' => $builder->where('created_at', '>=', self::stamp((string) $value)),
                'on_or_before' => $builder->where('created_at', '<=', self::stamp((string) $value)),
                'audit_anchor' => $builder->whereJsonContains('metadata->audit_anchor', (string) $value),
                default => throw AdminOperationException::malformed("unsupported audit filter [{$field}]"),
            };
        }

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        return $builder
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', max(1, $page));
    }

    private function applyAction($builder, string $value)
    {
        $action = AuditAction::tryFrom(strtolower(trim($value)));
        if (! $action instanceof AuditAction) {
            throw AdminOperationException::malformed('unknown audit action ['.$value.']');
        }

        return $builder->where('action', $action->value);
    }

    private static function stamp(string $value): string
    {
        $stamp = \Illuminate\Support\Carbon::parse($value, 'UTC');

        return $stamp->toDateTimeString();
    }
}
