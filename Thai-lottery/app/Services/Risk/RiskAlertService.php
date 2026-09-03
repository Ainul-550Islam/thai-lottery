<?php

declare(strict_types=1);

namespace App\Services\Risk;

use App\Enums\AuditAction;
use App\Enums\RiskAlertType;
use App\Enums\RiskLevel;
use App\Models\AuditLog;
use App\Models\NumberLimit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Records risk alerts without creating any new table and without notifying anyone.
 *
 * STORAGE DECISION — WHY audit_logs
 * The audited schema has no risk_alerts table, no risk_events table and no
 * exposure_snapshots table, and Phase 3.1 is not permitted to add a migration.
 * `audit_logs` is the only existing append-only trail with a `risk_level` column
 * (cast to App\Enums\RiskLevel), a polymorphic `auditable` target and a JSON
 * `metadata` column, which is exactly the shape an alert needs. An alert is
 * therefore written as:
 *
 *   action          AuditAction::SecurityAlert
 *   risk_level      the severity of the alert
 *   auditable       the App\Models\NumberLimit the alert concerns (nullable)
 *   description     a short human sentence
 *   metadata        risk_alert_type plus safe diagnostic amounts
 *
 * AuditAction has no dedicated 'risk_alert' case and AuditAction is outside the
 * Phase 3.1 file list, so SecurityAlert is reused rather than invented. The
 * specific alert kind lives in metadata under RiskAlertType::metadataKey().
 *
 * NOT DONE HERE, ON PURPOSE
 * - No notifications: no mail, no SMS, no push, no Notification class, no queued
 *   job. config('risk.alerts.channel') is honoured only to the extent of writing to
 *   the log channel; 'notify_admin' and 'notify_agent' are read and REPORTED in the
 *   returned payload so a later phase can act on them, but nothing is dispatched.
 * - No new table, no migration, no cache table.
 * - No exceptions thrown for alerting failures: an alert must never be the reason a
 *   risk evaluation crashes, so a storage failure is logged and swallowed while the
 *   alert payload is still returned to the caller.
 *
 * THROTTLING
 * config('risk.alerts.throttle_minutes') is 5. Duplicate suppression is a lookback
 * query on audit_logs for the same alert type against the same limit within the
 * window. This uses no cache and no extra table, so it works identically across
 * processes, which matters because the concurrency tests run in separate OS
 * processes.
 *
 * SECURITY
 * Every metadata value passes through redaction against
 * config('security.audit.sensitive_fields'). Only safe diagnostics are ever passed
 * in: ids, bet types, canonical numbers, exact decimal amounts and ratios.
 */
class RiskAlertService
{
    public function __construct(private readonly ConfigRepository $config)
    {
    }

    /**
     * Whether alerting is switched on.
     */
    public function isEnabled(): bool
    {
        return $this->config->get('risk.alerts.enabled') === true;
    }

    /**
     * Raise an alert, subject to the enable flag, the notify level and throttling.
     *
     * Returns the alert payload that was considered, with 'recorded' stating whether
     * a row was actually written and 'reason' explaining a suppression. Nothing here
     * throws.
     *
     * @param  array<string, scalar|null>  $context  safe diagnostics only
     * @return array{
     *     type: string,
     *     level: string,
     *     recorded: bool,
     *     reason: string|null,
     *     audit_log_id: int|null,
     *     notify_admin: bool,
     *     notify_agent: bool,
     *     channel: string,
     *     context: array<string, scalar|null>
     * }
     */
    public function raise(
        RiskAlertType $type,
        RiskLevel $level,
        ?NumberLimit $limit = null,
        array $context = [],
        ?string $description = null,
    ): array {
        // An alert kind carries a severity floor: a LimitExceeded alert can never be
        // filed as Low even if the caller passes Low by mistake.
        $effective = $level->atLeast($type->defaultLevel()) ? $level : $type->defaultLevel();

        $payload = [
            'type' => $type->value,
            'level' => $effective->value,
            'recorded' => false,
            'reason' => null,
            'audit_log_id' => null,
            'notify_admin' => $this->config->get('risk.alerts.notify_admin') === true,
            'notify_agent' => $this->config->get('risk.alerts.notify_agent') === true,
            'channel' => is_string($this->config->get('risk.alerts.channel'))
                ? (string) $this->config->get('risk.alerts.channel')
                : 'log',
            'context' => $this->redact($context),
        ];

        if (! $this->isEnabled()) {
            $payload['reason'] = 'alerts_disabled';

            return $payload;
        }

        if (! $this->meetsNotifyLevel($effective)) {
            $payload['reason'] = 'below_notify_level';

            return $payload;
        }

        if ($this->isThrottled($type, $limit)) {
            $payload['reason'] = 'throttled';

            return $payload;
        }

        $sentence = $description ?? $this->describe($type, $effective, $limit);

        try {
            $log = AuditLog::create([
                'user_id' => null,
                'action' => AuditAction::SecurityAlert,
                'risk_level' => $effective,
                'auditable_type' => $limit instanceof NumberLimit ? $limit->getMorphClass() : null,
                'auditable_id' => $limit instanceof NumberLimit ? $limit->getKey() : null,
                'description' => $sentence,
                'metadata' => array_merge(
                    [
                        RiskAlertType::metadataKey() => $type->value,
                        'risk_level' => $effective->value,
                        'stops_selling' => $type->stopsSelling(),
                    ],
                    $payload['context'],
                ),
            ]);

            $payload['recorded'] = true;
            $payload['audit_log_id'] = (int) $log->getKey();
        } catch (\Throwable $exception) {
            // An alert must never break a risk evaluation. The failure is reported
            // through the log channel and through the returned payload.
            $payload['reason'] = 'storage_failed';

            Log::warning('Risk alert could not be persisted.', [
                'risk_alert_type' => $type->value,
                'risk_level' => $effective->value,
                'error' => $exception->getMessage(),
            ]);

            return $payload;
        }

        $this->writeToChannel($payload, $sentence);

        return $payload;
    }

    /**
     * Raise several alerts, returning one payload per alert in the given order.
     *
     * @param  iterable<array{type: RiskAlertType, level: RiskLevel}>  $alerts
     * @param  array<string, scalar|null>  $context
     * @return list<array<string, mixed>>
     */
    public function raiseMany(iterable $alerts, ?NumberLimit $limit = null, array $context = []): array
    {
        $payloads = [];

        foreach ($alerts as $alert) {
            $payloads[] = $this->raise($alert['type'], $alert['level'], $limit, $context);
        }

        return $payloads;
    }

    /**
     * Whether an identical alert was already recorded inside the throttle window.
     *
     * A null limit cannot be scoped to a row, so it is throttled on the alert type
     * alone, which is the conservative reading.
     */
    public function isThrottled(RiskAlertType $type, ?NumberLimit $limit = null): bool
    {
        $minutes = $this->throttleMinutes();

        if ($minutes <= 0) {
            return false;
        }

        $since = Carbon::now()->subMinutes($minutes);

        $query = AuditLog::query()
            ->where('action', AuditAction::SecurityAlert->value)
            ->where('metadata->'.RiskAlertType::metadataKey(), $type->value)
            ->where('created_at', '>=', $since);

        if ($limit instanceof NumberLimit) {
            $query->where('auditable_type', $limit->getMorphClass())
                ->where('auditable_id', $limit->getKey());
        } else {
            $query->whereNull('auditable_id');
        }

        try {
            return $query->exists();
        } catch (\Throwable $exception) {
            // If the lookback itself fails, do not suppress the alert: a duplicate
            // alert is harmless, a missing one is not.
            Log::warning('Risk alert throttle lookup failed.', [
                'risk_alert_type' => $type->value,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Alerts already recorded against a limit within the throttle window.
     *
     * @return list<string> RiskAlertType values
     */
    public function recentAlertTypes(NumberLimit $limit): array
    {
        $minutes = $this->throttleMinutes();
        $since = Carbon::now()->subMinutes(max($minutes, 0));

        $rows = AuditLog::query()
            ->where('action', AuditAction::SecurityAlert->value)
            ->where('auditable_type', $limit->getMorphClass())
            ->where('auditable_id', $limit->getKey())
            ->where('created_at', '>=', $since)
            ->pluck('metadata');

        $types = [];

        foreach ($rows as $metadata) {
            $value = is_array($metadata)
                ? ($metadata[RiskAlertType::metadataKey()] ?? null)
                : null;

            if (is_string($value) && RiskAlertType::tryFrom($value) instanceof RiskAlertType) {
                $types[] = $value;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * The configured throttle window in minutes, floored at zero.
     */
    public function throttleMinutes(): int
    {
        $minutes = $this->config->get('risk.alerts.throttle_minutes');

        return is_int($minutes) && $minutes > 0 ? $minutes : 0;
    }

    /**
     * Whether a severity is loud enough to alert on.
     */
    public function meetsNotifyLevel(RiskLevel $level): bool
    {
        $configured = $this->config->get('risk.alerts.notify_at_level');
        $floor = is_string($configured) ? RiskLevel::tryFrom($configured) : null;

        // An unreadable configuration must not silence alerting; alert on
        // everything rather than nothing.
        return ! $floor instanceof RiskLevel || $level->atLeast($floor);
    }

    /**
     * Human sentence for an alert, with no amounts in it.
     *
     * Amounts live in metadata, where they are structured and queryable, rather
     * than being formatted into prose.
     */
    public function describe(RiskAlertType $type, RiskLevel $level, ?NumberLimit $limit = null): string
    {
        if (! $limit instanceof NumberLimit) {
            return sprintf('%s (%s).', $type->label(), $level->label());
        }

        return sprintf(
            '%s (%s) on number %s [%s] for draw %d.',
            $type->label(),
            $level->label(),
            (string) $limit->getAttribute('number'),
            $this->betTypeValue($limit),
            (int) $limit->getAttribute('draw_id'),
        );
    }

    /**
     * Mirror the alert to the configured log channel.
     *
     * This is the only "delivery" performed in Phase 3.1. It is not a notification:
     * nothing is sent to a user, an agent or an administrator.
     *
     * @param  array<string, mixed>  $payload
     */
    private function writeToChannel(array $payload, string $sentence): void
    {
        if ($payload['channel'] !== 'log') {
            // Any other channel is a later phase's responsibility. Recording that
            // it was requested is enough; nothing is dispatched.
            return;
        }

        $level = $payload['level'] ?? RiskLevel::Low->value;

        $context = [
            'risk_alert_type' => $payload['type'] ?? null,
            'risk_level' => $level,
            'audit_log_id' => $payload['audit_log_id'] ?? null,
        ];

        if ($level === RiskLevel::Critical->value) {
            Log::critical($sentence, $context);

            return;
        }

        if ($level === RiskLevel::High->value) {
            Log::error($sentence, $context);

            return;
        }

        Log::warning($sentence, $context);
    }

    /**
     * Replace any sensitive key with the configured placeholder.
     *
     * Nothing sensitive should ever be passed in, so this is a second line of
     * defence rather than the first.
     *
     * @param  array<string, scalar|null>  $context
     * @return array<string, scalar|null>
     */
    private function redact(array $context): array
    {
        $sensitive = $this->config->get('security.audit.sensitive_fields');
        $placeholder = $this->config->get('security.audit.redaction_placeholder');

        if (! is_array($sensitive) || $sensitive === []) {
            return $context;
        }

        $placeholder = is_string($placeholder) ? $placeholder : '[redacted]';
        $needles = [];

        foreach ($sensitive as $field) {
            if (is_string($field)) {
                $needles[] = strtolower($field);
            }
        }

        $clean = [];

        foreach ($context as $key => $value) {
            $clean[$key] = in_array(strtolower((string) $key), $needles, true)
                ? $placeholder
                : $value;
        }

        return $clean;
    }

    /**
     * The bet type of a limit row as a plain string.
     *
     * The model casts `bet_type` to App\Enums\BetType, so the attribute is an enum
     * instance and has to be unwrapped before it goes into a sentence.
     */
    private function betTypeValue(NumberLimit $limit): string
    {
        $betType = $limit->getAttribute('bet_type');

        return $betType instanceof \App\Enums\BetType ? $betType->value : (string) $betType;
    }
}
