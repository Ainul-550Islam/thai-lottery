<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Financial transaction lifecycle status.
 *
 * Pending    - recorded and idempotency-keyed, not yet applied to any balance.
 * Processing - being applied; the wallet row is locked while this lasts.
 * Completed  - balanced ledger entries were posted successfully.
 * Failed     - could not be applied; no balance or ledger effect remains.
 * Cancelled  - abandoned before it was applied (user or administrator action).
 * Reversed   - was completed, then compensated by an opposite ledger entry.
 *
 * A completed transaction is never edited or deleted: correcting it means
 * posting a reversal, which keeps the double-entry ledger append only.
 *
 * This enum only describes the lifecycle. Balance mutation, ledger posting and
 * reversal are performed by the transaction engine, never here.
 */
enum TransactionStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Reversed = 'reversed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }

    /**
     * Statuses that are still in motion and may still change.
     *
     * @return array<int, self>
     */
    public static function open(): array
    {
        return [self::Pending, self::Processing];
    }

    /**
     * Statuses that can never change again.
     *
     * @return array<int, self>
     */
    public static function final(): array
    {
        return [self::Completed, self::Failed, self::Cancelled, self::Reversed];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Reversed => 'Reversed',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, self::final(), true);
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Failed, cancelled and reversed transactions all leave no net balance
     * effect, which is what reporting treats as unsuccessful.
     */
    public function isUnsuccessful(): bool
    {
        return match ($this) {
            self::Failed, self::Cancelled, self::Reversed => true,
            self::Pending, self::Processing, self::Completed => false,
        };
    }

    /**
     * Only a completed transaction has ledger entries worth compensating.
     */
    public function canReverse(): bool
    {
        return $this === self::Completed;
    }

    /**
     * A transaction can be cancelled only while it has no balance effect yet.
     */
    public function canCancel(): bool
    {
        return $this === self::Pending;
    }

    /**
     * A failed attempt may be retried; anything already applied may not.
     */
    public function canRetry(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Whether this status requires processed_at to be set on the record.
     */
    public function requiresProcessedAt(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Reversed => true,
            self::Pending, self::Processing, self::Cancelled => false,
        };
    }

    /**
     * Allowed forward transitions, used to reject invalid state changes.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::Pending => in_array($target, [self::Processing, self::Failed, self::Cancelled], true),
            self::Processing => in_array($target, [self::Completed, self::Failed], true),
            self::Completed => $target === self::Reversed,
            self::Failed => $target === self::Processing,
            self::Cancelled, self::Reversed => false,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Processing => 'blue',
            self::Completed => 'green',
            self::Failed => 'red',
            self::Cancelled => 'gray',
            self::Reversed => 'orange',
        };
    }
}
