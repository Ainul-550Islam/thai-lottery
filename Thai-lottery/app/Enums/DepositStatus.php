<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a deposit request.
 *
 * WHAT WAS PRESERVED FROM THE AUDITED FOUNDATION
 * ----------------------------------------------
 * The five original cases (pending, confirmed, failed, cancelled, refunded) and
 * every original method (label, isFinal, isSuccessful, canCancel, color) are kept
 * with their original backing values, because App\Models\Deposit casts
 * `deposits.status` to this enum and already relies on `Confirmed` in
 * isConfirmed(), scopeConfirmed() and scopePending(). Existing rows keep working.
 *
 * WHAT WAS ADDED FOR THE PHASE 2.2 WORKFLOW
 * `approved`, `processing` and `rejected`, so the required
 * pending -> approved -> completed pipeline can be expressed, plus an explicit
 * transition map. The column is `string(32)` with no database-level enumeration,
 * so adding cases required no migration.
 *
 * VOCABULARY NOTE
 * The workflow's terminal success state is `confirmed`, NOT a new `completed`
 * case. Introducing a second success state next to the existing `confirmed`
 * would give the system two ways to say "the money arrived", and any query that
 * checked only one of them would be silently wrong. `completedCase()` names the
 * single success state so calling code never has to guess.
 *
 * SCHEMA LIMITATION (reported, not patched)
 * `deposits` has no `approved_at`, `rejected_at` or `reviewed_by` column - it has
 * only `confirmed_at`, `failed_at` and `failure_reason`. Approval and rejection
 * timestamps are therefore recorded in the `metadata` JSON column, and a
 * rejection additionally stamps `failed_at` / `failure_reason`, which are the
 * closest columns the audited schema provides.
 */
enum DepositStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Processing = 'processing';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

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
     * The single state that means "the money reached the wallet".
     *
     * Exists so no caller has to hardcode which of the states counts as success.
     */
    public static function completedCase(): self
    {
        return self::Confirmed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Processing => 'Processing',
            self::Confirmed => 'Confirmed',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * A final state can never move anywhere else.
     */
    public function isFinal(): bool
    {
        return in_array($this, [
            self::Confirmed,
            self::Rejected,
            self::Failed,
            self::Cancelled,
            self::Refunded,
        ], true);
    }

    /**
     * Alias of isFinal() using the state-machine vocabulary.
     */
    public function isTerminal(): bool
    {
        return $this->isFinal();
    }

    public function isSuccessful(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Whether the request is still moving through the workflow.
     */
    public function isOpen(): bool
    {
        return ! $this->isFinal();
    }

    /**
     * The states a deposit may legally move to from here.
     *
     * Deliberately excludes every backwards move: nothing returns to `pending`,
     * and no final state has a successor. `refunded` is reachable only from
     * `confirmed`, because refunding money that never arrived is meaningless -
     * and a refund is a compensating financial transaction, not a status reset.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Approved, self::Processing, self::Rejected, self::Cancelled, self::Failed],
            self::Approved => [self::Processing, self::Confirmed, self::Failed, self::Cancelled],
            self::Processing => [self::Confirmed, self::Failed],
            self::Confirmed => [self::Refunded],
            self::Rejected, self::Failed, self::Cancelled, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /**
     * Whether an administrator may still approve the request.
     */
    public function canApprove(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Whether an administrator may still reject the request.
     */
    public function canReject(): bool
    {
        return in_array($this, [self::Pending, self::Approved], true);
    }

    /**
     * Preserved from the audited foundation, widened to cover `approved`.
     *
     * An approved deposit has not been credited yet, so cancelling it is still
     * safe; a confirmed one is not cancellable and must be refunded instead.
     */
    public function canCancel(): bool
    {
        return in_array($this, [self::Pending, self::Approved], true);
    }

    /**
     * Whether the deposit may be settled into the wallet from here.
     *
     * Only `approved` and `processing` qualify. A `pending` deposit has not been
     * reviewed, and a `confirmed` one has already been credited.
     */
    public function canComplete(): bool
    {
        return in_array($this, [self::Approved, self::Processing], true);
    }

    /**
     * Whether reaching this state means the wallet was credited.
     */
    public function impliesWalletCredit(): bool
    {
        return $this === self::Confirmed;
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'yellow',
            self::Approved => 'blue',
            self::Processing => 'blue',
            self::Confirmed => 'green',
            self::Rejected => 'red',
            self::Failed => 'red',
            self::Cancelled => 'gray',
            self::Refunded => 'orange',
        };
    }
}
