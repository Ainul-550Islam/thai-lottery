<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Production Queue Priority Vocabulary.
 *
 * Defines the logical queue channels for asynchronous background processing.
 *
 * Priority Order:
 * 1. financial-critical: Real-money prize settlements, withdrawal disbursements
 * 2. webhooks: Inbound payment gateway notifications and callbacks
 * 3. reconciliation: Automated financial reconciliation and accounting audits
 * 4. default: General application maintenance and domain tasks
 * 5. notifications: Non-blocking player emails, SMS, and operator alerts
 */
enum QueueName: string
{
    case FinancialCritical = 'financial-critical';
    case Webhooks = 'webhooks';
    case Reconciliation = 'reconciliation';
    case Default = 'default';
    case Notifications = 'notifications';

    /**
     * Return all queue names in strict priority order.
     *
     * @return list<string>
     */
    public static function priorityOrder(): array
    {
        return [
            self::FinancialCritical->value,
            self::Webhooks->value,
            self::Reconciliation->value,
            self::Default->value,
            self::Notifications->value,
        ];
    }

    /**
     * Return comma-separated string for artisan queue:work --queue=...
     */
    public static function workerQueueString(): string
    {
        return implode(',', self::priorityOrder());
    }

    /**
     * Human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::FinancialCritical => 'Financial Critical',
            self::Webhooks => 'Payment Webhooks',
            self::Reconciliation => 'Financial Reconciliation',
            self::Default => 'Default',
            self::Notifications => 'Notifications & Alerts',
        };
    }
}
